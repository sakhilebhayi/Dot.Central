<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftOperatorAssignment;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Services\Shifts\ShiftDerivation;
use App\Support\RoleClass;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** I-29 arrival and departure corrections (15-lineup-corrections-design.md §3). */
class LineupCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck;

    private LineupService $service;

    private LineupFace $face1;

    private LineupFace $face2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Http::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $this->shift = $this->openShift('DAY', '2026-10-15', '2026-10-15 06:00:00', '2026-10-15 18:00:00');
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service = app(LineupService::class);
        $this->face1 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $this->face2 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '07:00');
    }

    private function openShift(string $code, string $date, string $start, string $end): Shift
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', $code)->firstOrFail();

        return Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'OPEN']);
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

    public function test_a_move_clicked_late_is_corrected_and_the_face_it_came_from_moves_with_it(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        $operator = Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'One']);
        $assignment = ShiftOperatorAssignment::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'operator_id' => $operator->id, 'from_at' => '2026-10-15 08:00:00', 'assigned_by' => $this->user->id]);
        $this->truck->forceFill(['mines_operator_id' => null])->save();
        $derived = fn (): array => app(ShiftDerivation::class)->unitSummaries($this->shift)->map(fn (array $u) => collect($u)->except('equipment')->all())->all();
        $before = $derived();

        $correction = $this->service->correctTime($moved, LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->user, $moved->from_at->toIso8601String());

        $this->assertSame('2026-10-15 10:00:00', $moved->fresh()->from_at->toDateTimeString());
        $this->assertSame('2026-10-15 10:00:00', $first->fresh()->to_at->toDateTimeString(), 'the stint it came from ends at the same moment');
        $this->assertSame([LineupCorrection::ARRIVAL, '2026-10-15 10:30:00', '2026-10-15 10:00:00', $first->id], [$correction->edge, $correction->old_at->toDateTimeString(), $correction->new_at->toDateTimeString(), $correction->partner_allocation_id]);
        $this->assertSame('HT-01 on Face 2 — Loading area 2 — Overburden at 10:00 (was 10:30); off Face 1 — Loading area 1 — Overburden at the same time.', $this->service->describeCorrection($correction));

        $audit = AuditEntry::where('entity_type', LineupCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame(['override', $this->shift->id], [$audit->action, $audit->shift_id]);
        $this->assertStringContainsString('corrected from 10:30 to 10:00: The move was clicked late', (string) $audit->reason);
        $this->assertSame(['2026-10-15T10:30:00+00:00', '2026-10-15T10:00:00+00:00'], [$audit->changes['from_at']['old'], $audit->changes['from_at']['new']]);
        $this->assertSame('2026-10-15T10:00:00+00:00', $audit->changes['partner_to_at']['new']);

        $this->assertSame('2026-10-15 08:00:00', $assignment->fresh()->from_at->toDateTimeString(), 'operator rows are not touched');
        $this->assertSame($before, $derived(), 'derived hours, loads and stoppages do not read the line-up');
        Http::assertNothingSent();
    }

    public function test_a_departure_is_corrected_and_the_face_it_went_to_moves_with_it(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);

        $correction = $this->service->correctTime($first->fresh(), LineupCorrection::DEPARTURE, '10:45', 'Left later than clicked', $this->user);

        $this->assertSame('2026-10-15 10:45:00', $first->fresh()->to_at->toDateTimeString());
        $this->assertSame('2026-10-15 10:45:00', $moved->fresh()->from_at->toDateTimeString());
        $this->assertSame('HT-01 off Face 1 — Loading area 1 — Overburden at 10:45 (was 10:30); on Face 2 — Loading area 2 — Overburden at the same time.', $this->service->describeCorrection($correction));
        $this->assertStringContainsString('LU-05 Nothing to change: HT-01 is already recorded off Face 1 — Loading area 1 — Overburden at 10:45', $this->refusal(fn () => $this->service->correctTime($first->fresh(), LineupCorrection::DEPARTURE, '10:45', 'Again', $this->user)));
        $this->assertSame(1, LineupCorrection::withoutGlobalScopes()->count());
    }

    public function test_impossible_corrections_are_refused_with_the_reason(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        $correct = fn (LineupAllocation $a, string $edge, string $time, string $reason = 'Clicked late') => $this->service->correctTime($a->fresh(), $edge, $time, $reason, $this->user);

        $this->assertStringContainsString('LU-05 Say why the time is corrected', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '10:00', ' ')));
        $this->assertStringContainsString('HT-01 is still on Face 2', $this->refusal(fn () => $correct($moved, LineupCorrection::DEPARTURE, '11:00')));
        $this->assertStringContainsString('Times out of order', $this->refusal(fn () => $correct($first, LineupCorrection::DEPARTURE, '07:30')));
        $this->assertStringContainsString('Outside the life of the face: Face 1 — Loading area 1 — Overburden ran 07:00–now', $this->refusal(fn () => $correct($first, LineupCorrection::ARRIVAL, '06:30')));
        $this->assertStringContainsString('has not happened yet', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '13:00')));
        $this->assertStringContainsString('is outside', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '05:00')));

        $truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $early = $this->service->place($this->shift, $this->face1, $truck2, $this->user, '08:00');
        $this->service->release($early, 'Sent to the workshop', $this->user, '09:00');
        $later = $this->service->place($this->shift, $this->face2, $truck2, $this->user, '10:30');
        $this->assertStringContainsString('LU-05 Overlaps another entry: HT-02 was on Face 1 — Loading area 1 — Overburden 08:00–09:00', $this->refusal(fn () => $correct($later, LineupCorrection::ARRIVAL, '08:30')));

        EquipmentStatusEvent::create(['equipment_id' => $truck2->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 09:30:00', 'source' => 'sync']);
        EquipmentStatusEvent::create(['equipment_id' => $truck2->id, 'field' => 'mines_status', 'from_value' => 'maintenance', 'to_value' => 'active', 'observed_at' => '2026-10-15 10:00:00', 'source' => 'sync']);
        $this->assertStringContainsString('LU-02 HT-02 was down then: Dot.Mines: maintenance since 09:30', $this->refusal(fn () => $correct($later, LineupCorrection::ARRIVAL, '09:15')));
        $this->assertSame('2026-10-15 10:30:00', $later->fresh()->from_at->toDateTimeString(), 'refusals change nothing');

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $this->assertStringContainsString('SH-04', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '10:00')));
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $nightFace = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $planned = $this->service->place($night, $nightFace, $this->truck, $this->user);
        $this->assertStringContainsString('Nothing to correct while planning', $this->refusal(fn () => $correct($planned, LineupCorrection::ARRIVAL, '19:00')));
        Http::assertNothingSent();
    }

    public function test_standby_recorded_while_the_machine_was_really_working_is_trimmed(): void
    {
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $standby = $this->service->setStandby($this->shift, $this->truck, $reason, $this->user, '08:00');
        $placed = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '10:30');
        $this->assertSame('2026-10-15 10:30:00', $standby->fresh()->to_at->toDateTimeString());

        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '09:00', 'Started loading at nine', $this->user);
        $this->assertSame('2026-10-15 09:00:00', $standby->fresh()->to_at->toDateTimeString(), 'standby ends when the machine really started');

        $this->service->correctTime($placed->fresh(), LineupCorrection::ARRIVAL, '07:30', 'Started even earlier', $this->user);
        $this->assertNull(LineupStandby::withoutGlobalScopes()->find($standby->id), 'a standby wholly inside the worked time is removed');
    }

    public function test_a_second_supervisor_working_from_a_stale_screen_is_told_the_time_changed(): void
    {
        $placed = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '10:30');
        $seen = $placed->from_at->toIso8601String();
        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '10:00', 'Clicked late', $this->user, $seen);

        $message = $this->refusal(fn () => $this->service->correctTime($placed->fresh(), LineupCorrection::ARRIVAL, '09:50', 'Also clicked late', $this->user, $seen));
        $this->assertStringContainsString('LU-15 Someone changed this time meanwhile: HT-01 is now recorded on Face 1 — Loading area 1 — Overburden at 10:00', $message);
        $this->assertSame('2026-10-15 10:00:00', $placed->fresh()->from_at->toDateTimeString());
    }

    public function test_a_night_shift_correction_lands_on_the_right_side_of_midnight(): void
    {
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        Carbon::setTestNow('2026-10-16 03:00:00');
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $face = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '18:00');
        $placed = $this->service->place($night, $face, $this->truck, $this->user, '01:30');

        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '23:40', 'Clicked after midnight', $this->user);

        $this->assertSame('2026-10-15 23:40:00', $placed->fresh()->from_at->toDateTimeString());
    }

    public function test_a_stint_changed_by_someone_else_after_the_checks_never_ends_up_before_it_started(): void
    {
        $previous = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        $fired = false;
        Event::listen(TransactionBeginning::class, function () use (&$fired, $previous): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // a second supervisor's correction of the first stint's arrival commits just before this one takes its lock
            DB::table('lineup_allocations')->where('id', $previous->id)->update(['from_at' => '2026-10-15 10:15:00']);
        });

        $message = $this->refusal(fn () => $this->service->correctTime($moved->fresh(), LineupCorrection::ARRIVAL, '10:00', 'A: clicked late', $this->user, $moved->from_at->toIso8601String()));

        $this->assertTrue($fired);
        $this->assertStringContainsString('LU-05 Times out of order: HT-01 only arrived on Face 1', $message);
        $this->assertSame('2026-10-15 10:30:00', $moved->fresh()->from_at->toDateTimeString(), 'nothing was written');
        $this->assertSame(0, LineupCorrection::withoutGlobalScopes()->count());
    }

    public function test_a_stint_that_another_supervisor_removed_after_the_checks_is_reported_not_crashed_on(): void
    {
        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        Event::listen(TransactionBeginning::class, function () use ($moved): void {
            DB::table('lineup_allocations')->where('id', $moved->id)->delete();
        });

        $this->assertStringContainsString('LU-15', $this->refusal(fn () => $this->service->correctTime($moved->fresh(), LineupCorrection::ARRIVAL, '10:00', 'clicked late', $this->user)));
    }

    public function test_a_reason_longer_than_the_column_is_refused_and_a_full_length_multibyte_one_is_kept(): void
    {
        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);

        $tooLong = str_repeat('Ü', 256);
        $this->assertStringContainsString('LU-05 The reason is 256 characters; keep it to 255 or fewer', $this->refusal(fn () => $this->service->correctTime($moved->fresh(), LineupCorrection::ARRIVAL, '10:00', $tooLong, $this->user)));
        $this->assertSame('2026-10-15 10:30:00', $moved->fresh()->from_at->toDateTimeString(), 'nothing was changed');

        $full = str_repeat('😀', 255);
        $correction = $this->service->correctTime($moved->fresh(), LineupCorrection::ARRIVAL, '10:00', '  '.$full.'  ', $this->user);
        $this->assertSame($full, $correction->fresh()->reason);
    }

    public function test_the_notice_says_which_of_two_identical_clock_times_was_used(): void
    {
        $this->room->minesConnection->forceFill(['timezone' => 'Europe/London'])->save();
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        Carbon::setTestNow('2026-10-25 01:45:00');
        $night = $this->openShift('NIGHT', '2026-10-24', '2026-10-24 17:00:00', '2026-10-25 06:00:00');
        $face = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '18:00');
        $placed = $this->service->place($night, $face, $this->truck, $this->user, '23:30');

        $correction = $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '01:40', 'Clicked after the clocks went back', $this->user);

        $this->assertSame('2026-10-25 01:40:00', $placed->fresh()->from_at->toDateTimeString());
        $this->assertStringEndsWith('The time used was 01:40 GMT, the second time the clock showed 01:40.', $this->service->describeCorrection($correction));

        $other = $this->service->correctTime($placed->fresh(), LineupCorrection::ARRIVAL, '23:45', 'Not a repeated time', $this->user);
        $this->assertStringNotContainsString('The time used', $this->service->describeCorrection($other));
    }

    public function test_the_prefilled_minute_of_a_stint_with_seconds_is_not_a_correction(): void
    {
        $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        Carbon::setTestNow('2026-10-15 10:30:27');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, null, true);
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->assertSame('10:30:27', $moved->from_at->format('H:i:s'));

        $message = $this->refusal(fn () => $this->service->correctTime($moved->fresh(), LineupCorrection::ARRIVAL, '10:30', 'Just looking', $this->user, $moved->from_at->toIso8601String()));

        $this->assertStringContainsString('LU-05 Nothing to change: HT-01 is already recorded on Face 2 — Loading area 2 — Overburden at 10:30', $message);
        $this->assertSame(0, LineupCorrection::withoutGlobalScopes()->count());
        $this->assertSame('10:30:27', $moved->fresh()->from_at->format('H:i:s'));
    }
}
