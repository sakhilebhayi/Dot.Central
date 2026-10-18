<?php

namespace Tests\Feature;

use App\Livewire\Lineup;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * I-29 slice 2, gap B: a machine's standby and its stints never overlap, closed or open; and every
 * sentence prints the control room's clock (here Africa/Johannesburg, two hours ahead of UTC).
 */
class LineupStandbyRulesTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $truck;

    private LineupService $service;

    private LineupFace $face1;

    private LineupFace $face2;

    private DowntimeReason $standbyReason;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'Africa/Johannesburg']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        // 06:00 to 18:00 on the room's clock
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 04:00:00', 'ends_at' => '2026-10-15 16:00:00', 'status' => 'OPEN']);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->service = app(LineupService::class);
        $this->face1 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '06:00');
        $this->face2 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '06:00');
        $this->standbyReason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->orderBy('id')->firstOrFail();
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['lineup'][0];
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_standby_cannot_be_back_dated_into_a_closed_stint(): void
    {
        $stint = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $this->service->release($stint, 'Finished', $this->user, '10:00');

        $message = $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '09:00'));

        $this->assertSame('LU-05 Overlaps an earlier entry: HT-01 was on Face 1 — Loading area 1 — Overburden until 10:00. Record the standby at 10:00 or later, or first correct when it left that face (select its time under "Moved off this face").', $message);
        $this->assertSame(0, LineupStandby::withoutGlobalScopes()->count(), 'nothing was recorded');

        $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00');
        $this->assertSame('10:00', LineupStandby::withoutGlobalScopes()->firstOrFail()->from_at->setTimezone('Africa/Johannesburg')->format('H:i'), 'the stint ends exactly where the standby begins: allowed');
    }

    public function test_a_standby_cannot_be_back_dated_into_a_closed_standby(): void
    {
        $standby = $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '08:00');
        $this->service->endStandby($standby, $this->user, '10:00');

        $message = $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '09:00'));

        $this->assertSame('LU-05 Overlaps an earlier entry: HT-01 was on standby ("'.$this->standbyReason->name.'") until 10:00. Record the standby at 10:00 or later.', $message);
        $this->assertSame(1, LineupStandby::withoutGlobalScopes()->count());

        $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00');
        $this->assertSame(2, LineupStandby::withoutGlobalScopes()->count());
    }

    public function test_a_placement_cannot_be_back_dated_into_a_closed_standby(): void
    {
        $standby = $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '08:00');
        $this->service->endStandby($standby, $this->user, '10:00');

        $message = $this->refusal(fn () => $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '09:00'));

        $this->assertSame('LU-05 Overlaps an earlier entry: HT-01 was on standby ("'.$this->standbyReason->name.'") until 10:00. Record the change at 10:00 or later.', $message);
        $this->assertSame(0, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck->id)->count());

        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '10:00');
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck->id)->count());
    }

    public function test_ending_a_standby_at_or_before_it_began_is_refused_instead_of_deleting_it(): void
    {
        $standby = $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:30');

        $this->assertSame('LU-05 Time before the standby began: HT-01 has been on standby since 10:30. Pick a time after that, so the standby keeps its record.', $this->refusal(fn () => $this->service->endStandby($standby, $this->user, '10:00')));
        $this->assertStringContainsString('Time before the standby began', $this->refusal(fn () => $this->service->endStandby($standby, $this->user, '10:30')));
        $this->assertNull($standby->fresh()->to_at, 'still on standby');

        $this->service->endStandby($standby, $this->user, '11:00');
        $this->assertSame(1, LineupStandby::withoutGlobalScopes()->whereNotNull('to_at')->count());
    }

    public function test_ending_a_standby_that_someone_else_already_ended_is_reported(): void
    {
        $standby = $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:30');
        $stale = LineupStandby::withoutGlobalScopes()->findOrFail($standby->id);
        $this->service->endStandby($standby, $this->user, '11:00');

        $this->assertStringContainsString('LU-15', $this->refusal(fn () => $this->service->endStandby($stale, $this->user, '11:30')));
        $this->assertSame('09:00', LineupStandby::withoutGlobalScopes()->findOrFail($standby->id)->to_at->format('H:i'), 'the first ending stands (11:00 on the room clock is 09:00 UTC)');
    }

    public function test_a_standby_is_checked_again_under_the_machines_lock(): void
    {
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // another operator's placement and release of this truck commits just before this standby takes its lock
            DB::table('lineup_allocations')->insert(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'lineup_face_id' => $this->face2->id, 'equipment_id' => $this->truck->id, 'role' => 'HAUL', 'from_at' => '2026-10-15 06:00:00', 'to_at' => '2026-10-15 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        });

        $message = $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00'));

        $this->assertTrue($fired, 'the standby runs in a transaction');
        $this->assertStringContainsString('LU-05 Overlaps an earlier entry: HT-01 was on Face 2 — Loading area 2 — Coal until 11:00', $message);
        $this->assertSame(0, LineupStandby::withoutGlobalScopes()->count());
    }

    public function test_setting_and_ending_a_standby_take_the_machines_lock_before_reading_anything(): void
    {
        $first = [];
        $inside = false;
        Event::listen(TransactionBeginning::class, function () use (&$inside, &$first): void {
            $inside = true;
            $first[] = [];
        });
        DB::listen(function ($query) use (&$inside, &$first): void {
            if ($inside) {
                $first[array_key_last($first)][] = $query->sql;
            }
        });

        $standby = $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00');
        $this->service->endStandby($standby, $this->user, '11:00');

        $this->assertCount(2, $first, 'one transaction each');
        foreach ($first as $queries) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                $this->assertStringStartsWith('update "lineup_allocations" set "updated_at"', $queries[0], 'the first statement is the lock (SQLite: the write lock)');
            } else {
                $this->assertStringContainsString('for update', $queries[0], 'Postgres: the equipment row is locked before its stints (stints first deadlocked, 40P01)');
                $this->assertStringStartsWith('update "lineup_allocations" set "updated_at"', $queries[1]);
            }
        }
    }

    public function test_the_first_standby_is_not_hidden_behind_a_second_one_recorded_at_the_same_time(): void
    {
        $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00');

        $this->assertSame('LU-05 HT-01 already has "'.$this->standbyReason->name.'" from 10:00. End it first, or place the machine.', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:30')));
    }

    public function test_refusal_sentences_print_the_control_room_clock_not_utc(): void
    {
        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '09:00');

        $this->assertStringContainsString('Choose "Move here" to move it at 11:00.', $this->refusal(fn () => $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '11:00')));
        $this->assertSame('LU-05 Time before the machine arrived (09:00).', $this->refusal(fn () => $this->service->release(LineupAllocation::withoutGlobalScopes()->firstOrFail(), 'Finished', $this->user, '08:00')));
        $this->assertSame('LU-05 Time before the machine arrived on its face (09:00).', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '08:00')));

        $this->service->closeFace($this->face2, 'Finished', $this->user, '10:00');
        $this->assertStringContainsString('Face 2 — Loading area 2 — Coal was closed at 10:00. Allocate to an active face.', $this->refusal(fn () => $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '11:00', true)));
    }

    public function test_the_standby_that_is_already_open_and_the_down_since_time_print_the_room_clock(): void
    {
        $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:00');
        $this->assertStringContainsString('already has "'.$this->standbyReason->name.'" from 10:00.', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck, $this->standbyReason, $this->user, '10:30')));

        $down = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'maintenance', 'last_heard_at' => now()]);
        EquipmentStatusEvent::create(['equipment_id' => $down->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 07:00:00', 'source' => 'sync']);
        $this->assertStringContainsString('HT-02 is down: Dot.Mines: maintenance since 09:00.', $this->refusal(fn () => $this->service->place($this->shift, $this->face1, $down, $this->user, '10:00')));
    }

    public function test_a_release_or_closing_reason_longer_than_its_column_is_refused_and_a_full_length_multibyte_one_is_kept(): void
    {
        $stint = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $tooLong = str_repeat('Ü', 256);

        $this->assertSame('LU-05 The reason is 256 characters; keep it to 255 or fewer.', $this->refusal(fn () => $this->service->release($stint, $tooLong, $this->user, '10:00')));
        $this->assertNull($stint->fresh()->to_at, 'nothing was changed');
        $this->assertSame('LU-05 The reason is 256 characters; keep it to 255 or fewer.', $this->refusal(fn () => $this->service->closeFace($this->face1, $tooLong, $this->user, '10:00')));
        $this->assertFalse($this->face1->fresh()->isClosed());
        $this->assertNull($stint->fresh()->to_at);

        $full = str_repeat('😀', 255);
        $this->service->release($stint, '  '.$full.'  ', $this->user, '10:00');
        $this->assertSame($full, $stint->fresh()->ended_reason);

        $other = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30');
        $this->service->closeFace($this->face2, $full, $this->user, '11:00');
        $this->assertSame($full, $this->face2->fresh()->close_reason, 'the face keeps the whole reason');
        $this->assertLessThanOrEqual(255, mb_strlen((string) $other->fresh()->ended_reason), 'the stint note still fits its column');
    }

    public function test_the_release_and_closing_inputs_stop_typing_at_the_column_width(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSeeHtml('placeholder="Why close?" style="width:9rem;" maxlength="255"');
        $stint = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSeeHtml('placeholder="why?" style="width:7rem;" maxlength="255" aria-label="Reason to take HT-01 off"');
        $this->assertNotNull($stint);
    }

    public function test_releasing_a_stint_that_was_moved_meanwhile_is_reported_not_written_over_the_new_one(): void
    {
        $stale = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);

        $this->assertStringContainsString('LU-15', $this->refusal(fn () => $this->service->release($stale, 'Finished', $this->user, '11:00')));

        $this->assertSame('08:30', $stale->fresh()->to_at->format('H:i'), 'the move at 10:30 on the room clock (08:30 UTC) stands: the release did not write over it');
    }

    public function test_a_release_is_checked_again_under_the_machines_lock(): void
    {
        $stint = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $stint): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // a second supervisor corrects the arrival to after the time being recorded, just before this one takes its lock
            DB::table('lineup_allocations')->where('id', $stint->id)->update(['from_at' => '2026-10-15 08:15:00']);
        });

        $message = $this->refusal(fn () => $this->service->release($stint, 'Finished', $this->user, '10:00'));

        $this->assertTrue($fired, 'the release runs in a transaction');
        $this->assertSame('LU-05 Time before the machine arrived (10:15).', $message);
        $this->assertNull($stint->fresh()->to_at);
    }

    public function test_closing_a_face_also_ends_a_machine_placed_on_it_meanwhile(): void
    {
        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $late = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $late): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // another operator puts a second truck on the face just before the closing takes its locks
            DB::table('lineup_allocations')->insert(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'lineup_face_id' => $this->face1->id, 'equipment_id' => $late->id, 'role' => 'HAUL', 'from_at' => '2026-10-15 07:00:00', 'created_at' => now(), 'updated_at' => now()]);
        });

        $message = $this->refusal(fn () => $this->service->closeFace($this->face1, 'Finished', $this->user, '10:00'));

        $this->assertTrue($fired);
        $this->assertStringContainsString('LU-15', $message);
        $this->assertFalse($this->face1->fresh()->isClosed(), 'nothing half done: the face stays open for a second try');
        $this->service->closeFace($this->face1->fresh(), 'Finished', $this->user, '10:00');
        $this->assertSame(0, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $this->face1->id)->whereNull('to_at')->count());
    }

    public function test_a_face_cannot_be_closed_before_a_machine_left_it(): void
    {
        $stint = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $this->service->release($stint, 'Workshop', $this->user, '11:00');

        $message = $this->refusal(fn () => $this->service->closeFace($this->face1, 'Finished', $this->user, '10:00'));

        $this->assertSame('LU-05 Time before a machine left the face: HT-01 was on Face 1 — Loading area 1 — Overburden until 11:00. Close the face at 11:00 or later, or first correct when it left (select its time under "Moved off this face").', $message);
        $this->assertFalse($this->face1->fresh()->isClosed());

        $this->service->closeFace($this->face1->fresh(), 'Finished', $this->user, '11:00');
        $this->assertTrue($this->face1->fresh()->isClosed());
    }

    public function test_a_face_cannot_be_closed_before_it_started(): void
    {
        $late = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '10:00');

        $this->assertSame('LU-05 Face 3 — Loading area 3 — Coal only started at 10:00. Pick a later time.', $this->refusal(fn () => $this->service->closeFace($late, 'Finished', $this->user, '09:00')));
        $this->assertFalse($late->fresh()->isClosed());
    }

    public function test_a_machine_cannot_be_placed_before_the_face_started(): void
    {
        $late = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '10:00');

        $message = $this->refusal(fn () => $this->service->place($this->shift, $late, $this->truck, $this->user, '08:00'));

        $this->assertSame('LU-05 Time before the face started: Face 3 — Loading area 3 — Coal only started at 10:00. Record the placement at 10:00 or later.', $message);
        $this->assertSame(0, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck->id)->count());
        $this->service->place($this->shift, $late, $this->truck, $this->user, '10:00');
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck->id)->count());
    }
}
