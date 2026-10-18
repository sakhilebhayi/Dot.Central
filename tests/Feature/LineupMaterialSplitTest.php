<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MinesConnection;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** I-29 material split as a time split; no quantity is split (D3) (15-lineup-corrections-design.md §5). */
class LineupMaterialSplitTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck1;

    private Equipment $truck2;

    private LineupService $service;

    private LineupFace $face;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Http::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $this->shift = $this->openShift('DAY', '2026-10-15', '2026-10-15 06:00:00', '2026-10-15 18:00:00');
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck1 = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service = app(LineupService::class);
        $this->face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $this->service->place($this->shift, $this->face, $this->truck1, $this->user, '07:30');
        $this->service->place($this->shift, $this->face, $this->truck2, $this->user, '08:00');
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

    /** "face@from-to" per stint of the machine, in time order, on the room clock (UTC here). */
    private function history(Equipment $unit, ?Shift $shift = null): array
    {
        return LineupAllocation::withoutGlobalScopes()->where('shift_id', ($shift ?? $this->shift)->id)->where('equipment_id', $unit->id)->orderBy('from_at')->orderBy('id')->get()
            ->map(fn (LineupAllocation $a) => $a->face->seq.'@'.$a->from_at->format('H:i').'-'.($a->to_at?->format('H:i') ?? 'open'))->all();
    }

    private function addTo(LineupFace $face, string $name, string $role, string $hhmm): Equipment
    {
        $unit = Equipment::factory()->for($this->room)->create(['name' => $name, 'role_class' => $role, 'mines_status' => 'active']);
        $this->service->place($this->shift, $face, $unit, $this->user, $hhmm);

        return $unit;
    }

    /** Nothing overlaps, nothing is inverted, every stint sits inside the life of its face (the invariants the stress run checks). */
    private function assertSound(?Shift $shift = null): void
    {
        $shift ??= $this->shift;
        $end = $shift->ends_at;
        $faces = LineupFace::withoutGlobalScopes()->where('shift_id', $shift->id)->get()->keyBy('id');
        $stints = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->get();
        foreach ($stints as $a) {
            $this->assertTrue($a->to_at === null || $a->to_at->greaterThan($a->from_at), 'stint '.$a->id.' is inverted or empty');
            $face = $faces[$a->lineup_face_id];
            $this->assertTrue($a->from_at->greaterThanOrEqualTo($face->active_from), 'stint '.$a->id.' starts before its face');
            $this->assertTrue($face->closed_at === null || ($a->to_at !== null && $a->to_at->lessThanOrEqualTo($face->closed_at)), 'stint '.$a->id.' outlives its closed face');
        }
        foreach ($stints->groupBy('equipment_id') as $rows) {
            foreach ($rows as $a) {
                foreach ($rows as $b) {
                    $linked = $a->role === 'LOADER' && $b->role === 'LOADER' && $faces[$a->lineup_face_id]->sameWorkplaceAs($faces[$b->lineup_face_id]);
                    if ($a->id < $b->id && ! $linked) {
                        $this->assertFalse($a->from_at->lessThan($b->to_at ?? $end) && $b->from_at->lessThan($a->to_at ?? $end), 'stints '.$a->id.' and '.$b->id.' overlap');
                    }
                }
            }
        }
        foreach (LineupStandby::withoutGlobalScopes()->where('shift_id', $shift->id)->get() as $s) {
            foreach ($stints->where('equipment_id', $s->equipment_id) as $a) {
                $this->assertFalse($s->from_at->lessThan($a->to_at ?? $end) && $a->from_at->lessThan($s->to_at ?? $end), 'standby '.$s->id.' overlaps stint '.$a->id);
            }
        }
    }

    public function test_a_material_change_on_a_working_face_needs_a_decision_and_a_correction_edits_in_place(): void
    {
        $attrs = ['material' => 'Interburden', 'cut' => 'C1', 'blocks' => ['B1']];
        $message = $this->refusal(fn () => $this->service->editFace($this->face, $attrs, $this->user));
        $this->assertStringContainsString('LU-14 Face 1 — Loading area 1 — Overburden · C1 / B1 has been loading Overburden since 07:00.', $message);
        $this->assertSame('Overburden', $this->face->fresh()->material, 'a refused edit changes nothing');

        $this->service->editFace($this->face->fresh(), $attrs, $this->user, materialCorrection: true);

        $this->assertSame('Interburden', $this->face->fresh()->material);
        $audit = AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'override')->firstOrFail();
        $this->assertSame('Material corrected from Overburden to Interburden (wrong from the start)', $audit->reason);
        $this->assertSame(0, AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'updated')->count(), 'one reasoned row, no reason-less duplicate');

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $planned = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $this->service->editFace($planned, ['material' => 'Softs'], $this->user);
        $this->assertSame('Softs', $planned->fresh()->material, 'while planning there is nothing to decide');
    }

    public function test_within_the_minute_a_face_started_nothing_has_happened_on_it_to_split(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:20');
        $fresh = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);

        Carbon::setTestNow('2026-10-15 12:00:50');
        $this->service->editFace($fresh, ['material' => 'Parting'], $this->user);
        $this->assertSame('Parting', $fresh->fresh()->material, 'a slip noticed within the same minute is simply edited: a split at that minute could not be recorded');

        Carbon::setTestNow('2026-10-15 12:01:05');
        $this->assertStringContainsString('LU-14', $this->refusal(fn () => $this->service->editFace($fresh->fresh(), ['material' => 'Waste'], $this->user)));
        $new = $this->service->splitFace($fresh->fresh(), 'Waste', $this->user, '12:01');
        $this->assertSame('2026-10-15 12:01:00', $new->active_from->toDateTimeString());
    }

    public function test_turning_a_loading_face_into_a_work_area_is_not_a_change_of_material(): void
    {
        $area = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '07:00');

        $this->service->editFace($area, ['kind' => 'AREA', 'activity' => 'Dozing'], $this->user);

        $this->assertSame(['AREA', null, 'Dozing'], [$area->fresh()->kind, $area->fresh()->material, $area->fresh()->activity], 'no question: the material is not changed, the face stops loading');
    }

    public function test_only_a_real_change_of_material_on_a_loading_face_is_a_decision(): void
    {
        $same = ['material' => 'Overburden', 'cut' => 'C2', 'blocks' => ['B1'], 'notes' => 'wet'];

        $this->service->editFace($this->face, $same, $this->user);
        $this->assertSame(['C2', 'wet'], [$this->face->fresh()->cut, $this->face->fresh()->notes], 'a cut or a note is edited as before');

        $this->service->editFace($this->face->fresh(), ['material' => ' OVERBURDEN ', 'cut' => 'C2', 'blocks' => ['B1']], $this->user);
        $this->assertSame(0, AuditEntry::where('entity_type', LineupFace::class)->where('action', 'override')->count(), 'a spelling of the same material is no decision');

        $this->service->editFace($this->face->fresh(), ['material' => 'Interburden', 'cut' => 'C2', 'blocks' => ['B1']], $this->user, hhmm: '07:00');
        $this->assertSame('Interburden', $this->face->fresh()->material, 'a change recorded at the moment the face started has nothing to split');

        $this->service->editFace($this->face->fresh(), ['material' => 'Interburden', 'cut' => 'C2', 'blocks' => ['B1']], $this->user, materialCorrection: true);
        $this->assertSame(0, AuditEntry::where('entity_type', LineupFace::class)->where('action', 'override')->count(), 'a correction of nothing is an ordinary save');

        $area = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'AREA', 'activity' => 'Dozing'], $this->user, '07:00');
        $this->service->editFace($area, ['kind' => 'AREA', 'activity' => 'Drilling'], $this->user);
        $this->assertSame('Drilling', $area->fresh()->activity, 'a work area has no material to decide');

        $without = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING'], $this->user, '07:00');
        $this->service->editFace($without, ['material' => 'Coal'], $this->user);
        $this->assertSame('Coal', $without->fresh()->material, 'a face with no material yet takes its first one without a question');
    }

    public function test_a_face_that_changed_material_at_a_time_is_split_and_the_machines_carry_on(): void
    {
        $truck2Stint = LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck2->id)->firstOrFail();
        $this->service->release($truck2Stint, 'Refuelling', $this->user, '11:00');

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00', 'Hit the interburden');

        $old = $this->face->fresh();
        $this->assertSame(['CLOSED', '2026-10-15 10:00:00', 'Material changed to Interburden: Hit the interburden'], [$old->status, $old->closed_at->toDateTimeString(), $old->close_reason]);
        $this->assertSame([2, 'Interburden', 'C1', ['B1'], 'Loading area 1', '2026-10-15 10:00:00', $old->id], [$new->seq, $new->material, $new->cut, $new->blocks, $new->location_name, $new->active_from->toDateTimeString(), $new->split_from_face_id]);
        $this->assertSame('Face 1 (Overburden until 10:00)', $this->service->splitOrigin($new));
        $this->assertNull($this->service->splitOrigin($old));

        $open = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->whereNull('to_at')->orderBy('equipment_id')->get();
        $this->assertSame([$this->loader->id, $this->truck1->id], $open->pluck('equipment_id')->all());
        $this->assertTrue($open->every(fn (LineupAllocation $a) => $a->from_at->toDateTimeString() === '2026-10-15 10:00:00'));
        $this->assertSame(3, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $old->id)->where('to_at', '2026-10-15 10:00:00')->where('ended_reason', 'Face changed material')->count());

        $remainder = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->where('equipment_id', $this->truck2->id)->firstOrFail();
        $this->assertSame(['2026-10-15 10:00:00', '2026-10-15 11:00:00', 'Refuelling'], [$remainder->from_at->toDateTimeString(), $remainder->to_at->toDateTimeString(), $remainder->ended_reason]);
        $this->assertSame(['1@08:00-10:00', '2@10:00-11:00'], $this->history($this->truck2));
        $this->assertSame([], $this->service->linkedFaces($new)->all());
        $this->assertSound();
        Http::assertNothingSent();
    }

    public function test_splits_that_cannot_be_right_are_refused(): void
    {
        $this->assertStringContainsString('LU-05 Pick the new material', $this->refusal(fn () => $this->service->splitFace($this->face, ' overburden ', $this->user, '10:00')));
        $this->assertStringContainsString('only started at 07:00. Pick a later time.', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '06:30')));
        $area = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'AREA', 'activity' => 'Dozing'], $this->user, '07:00');
        $this->assertStringContainsString('Only a loading face changes material', $this->refusal(fn () => $this->service->splitFace($area, 'Interburden', $this->user, '10:00')));

        $stale = LineupFace::withoutGlobalScopes()->findOrFail($this->face->id);
        $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');
        $this->assertStringContainsString('LU-15', $this->refusal(fn () => $this->service->splitFace($stale, 'Softs', $this->user, '10:30')));
        $this->assertStringContainsString('is closed', $this->refusal(fn () => $this->service->splitFace($this->face->fresh(), 'Softs', $this->user, '10:30')));

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $planned = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $this->assertStringContainsString('Before the shift starts, edit the face instead', $this->refusal(fn () => $this->service->splitFace($planned, 'Softs', $this->user)));
    }

    // ---- adversarial cases ------------------------------------------------------------------------------------

    public function test_a_split_at_or_before_the_moment_the_face_started_is_refused_and_changes_nothing(): void
    {
        foreach (['07:00', '06:59', '06:00'] as $time) {
            $this->assertSame('LU-05 Face 1 — Loading area 1 — Overburden · C1 / B1 only started at 07:00. Pick a later time.', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, $time)));
        }
        $this->assertStringContainsString('05:00 is outside', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '05:00')));
        $this->assertSame(1, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());
        $this->assertSame('ACTIVE', $this->face->fresh()->status);
        $this->assertStringContainsString('has not happened yet', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '13:00')));
        $this->assertStringContainsString('is not a time', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '25:99')));
        $this->assertSame(1, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());
    }

    public function test_stints_that_began_or_ended_around_the_split_are_cut_moved_or_left_alone(): void
    {
        $left = $this->addTo($this->face, 'HT-03', RoleClass::HAUL, '08:30');
        $this->service->release(LineupAllocation::withoutGlobalScopes()->where('equipment_id', $left->id)->firstOrFail(), 'Sent to the workshop', $this->user, '09:00');
        $late = Equipment::factory()->for($this->room)->create(['name' => 'HT-04', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service->place($this->shift, $this->face, $late, $this->user, '11:00');
        $second = Equipment::factory()->for($this->room)->create(['name' => 'HT-05', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service->place($this->shift, $this->face, $second, $this->user, '10:00');
        $exact = $this->addTo($this->face, 'HT-06', RoleClass::HAUL, '08:30');
        $this->service->release(LineupAllocation::withoutGlobalScopes()->where('equipment_id', $exact->id)->firstOrFail(), 'Sent for service', $this->user, '10:00');

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertSame(['1@08:30-09:00'], $this->history($left), 'a stint that ended before the split stays on the old face, untouched');
        $this->assertSame(['1@08:30-10:00'], $this->history($exact), 'a stint that ended exactly at the split moment is not cut: no empty entry is left on the new face');
        $this->assertSame(['Sent for service'], LineupAllocation::withoutGlobalScopes()->where('equipment_id', $exact->id)->pluck('ended_reason')->all());
        $this->assertSame(['2@11:00-open'], $this->history($late), 'a stint that began after the split is re-pointed with its times');
        $this->assertSame(['2@10:00-open'], $this->history($second), 'a stint that began at the split moment belongs to the new face');
        $this->assertSame(['1@07:30-10:00', '2@10:00-open'], $this->history($this->truck1));
        $this->assertSame(['Sent to the workshop'], LineupAllocation::withoutGlobalScopes()->where('equipment_id', $left->id)->pluck('ended_reason')->all());
        $this->assertSame($new->id, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $late->id)->firstOrFail()->lineup_face_id);
        $this->assertSound();
    }

    public function test_machines_on_standby_at_the_split_moment_stay_on_standby_and_the_stint_before_a_later_standby_is_split_with_the_face(): void
    {
        $standby = DowntimeReason::where('control_room_id', $this->room->id)->whereIn('category', LineupStandby::CATEGORIES)->firstOrFail();
        $already = $this->addTo($this->face, 'HT-03', RoleClass::HAUL, '07:40');
        $this->service->setStandby($this->shift, $already, $standby, $this->user, '09:00');
        $later = $this->addTo($this->face, 'HT-04', RoleClass::HAUL, '07:50');
        $this->service->setStandby($this->shift, $later, $standby, $this->user, '11:00');

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertSame(['1@07:40-09:00'], $this->history($already), 'a machine on standby at the split moment is on no face: nothing is carried');
        $this->assertSame('2026-10-15 09:00:00', LineupStandby::withoutGlobalScopes()->where('equipment_id', $already->id)->firstOrFail()->from_at->toDateTimeString());
        $this->assertNull(LineupStandby::withoutGlobalScopes()->where('equipment_id', $already->id)->firstOrFail()->to_at);
        $this->assertSame(['1@07:50-10:00', '2@10:00-11:00'], $this->history($later), 'standby set after the split moment: the working time before it moves to the new face');
        $this->assertSame('Set to '.$standby->name, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->where('equipment_id', $later->id)->firstOrFail()->ended_reason);
        $this->assertSame('2026-10-15 11:00:00', LineupStandby::withoutGlobalScopes()->where('equipment_id', $later->id)->firstOrFail()->from_at->toDateTimeString());
        $this->assertSound();

        $this->service->endStandby(LineupStandby::withoutGlobalScopes()->where('equipment_id', $already->id)->firstOrFail(), $this->user, '10:30');
        $this->service->place($this->shift, $new, $already, $this->user, '10:30');
        $this->assertSame(['1@07:40-09:00', '2@10:30-open'], $this->history($already), 'the standby machine can be put on the new face afterwards');
        $this->assertSound();
    }

    public function test_the_split_time_follows_the_room_clock_across_a_dst_change(): void
    {
        $this->room->minesConnection->forceFill(['timezone' => 'Europe/London'])->save();
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        // 25 Oct 2026: at 02:00 BST (01:00 UTC) the clocks go back to 01:00 GMT, so 01:00-01:59 shows twice.
        Carbon::setTestNow('2026-10-25 01:45:00');
        $night = $this->openShift('NIGHT', '2026-10-24', '2026-10-24 17:00:00', '2026-10-25 06:00:00');
        $face = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C1', 'blocks' => ['B1']], $this->user, '18:00');
        $this->service->place($night, $face, $this->truck1, $this->user, '19:00');

        $new = $this->service->splitFace($face, 'Parting', $this->user, '01:40');

        $this->assertSame('2026-10-25 01:40:00', $new->active_from->toDateTimeString(), 'the second 01:40 (GMT) has happened');
        $this->assertSame(['1@18:00-01:40', '2@01:40-open'], $this->history($this->truck1, $night));
        $this->assertSame('Face 1 (Coal until 01:40)', $this->service->splitOrigin($new));
        $this->assertSame('01:40 GMT, the second time the clock showed 01:40', $this->service->ambiguityNote($night, $new->active_from));

        $message = $this->refusal(fn () => $this->service->splitFace($new->fresh(), 'Dirty', $this->user, '01:50'));
        $this->assertSame('LU-05 Face 2 — Loading area 1 — Parting · C1 / B1 only started at 01:40 GMT, the second time the clock showed 01:40. Pick a later time.', $message, '01:50 has not happened a second time yet, so the first 01:50 is before the face started and the refusal says which 01:40 it means');
        $this->assertSame(1, $this->service->faces($night)->count());
        $this->assertSound($night);
    }

    public function test_a_split_at_a_time_the_clocks_skipped_is_refused(): void
    {
        $this->room->minesConnection->forceFill(['timezone' => 'Europe/London'])->save();
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        // 29 Mar 2026: at 01:00 UTC the clocks jump from 01:00 GMT to 02:00 BST, so 01:30 never happens.
        Carbon::setTestNow('2026-03-29 03:00:00');
        $night = $this->openShift('NIGHT', '2026-03-28', '2026-03-28 18:00:00', '2026-03-29 06:00:00');
        $face = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '19:00');

        $this->assertStringContainsString('LU-05 01:30 did not happen on 29 Mar in Europe/London: the clocks went forward.', $this->refusal(fn () => $this->service->splitFace($face, 'Parting', $this->user, '01:30')));
        $this->assertSame('ACTIVE', $face->fresh()->status);

        $new = $this->service->splitFace($face, 'Parting', $this->user, '02:30');
        $this->assertSame('2026-03-29 01:30:00', $new->active_from->toDateTimeString(), '02:30 BST is 01:30 UTC');
    }

    public function test_a_closed_shift_and_a_closed_face_refuse_a_split_and_a_material_correction(): void
    {
        $this->addTo($this->face, 'HT-03', RoleClass::HAUL, '09:00');
        $this->service->closeFace($this->face, 'Finished', $this->user, '11:00');
        $this->assertStringContainsString('is closed', $this->refusal(fn () => $this->service->splitFace($this->face->fresh(), 'Interburden', $this->user, '11:30')));
        $this->assertStringContainsString('is closed', $this->refusal(fn () => $this->service->editFace($this->face->fresh(), ['material' => 'Interburden'], $this->user, materialCorrection: true)));

        $open = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '09:00');
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        foreach ([fn () => $this->service->splitFace($open->fresh(), 'Parting', $this->user, '10:00'), fn () => $this->service->editFace($open->fresh(), ['material' => 'Parting'], $this->user, materialCorrection: true)] as $try) {
            $this->assertStringContainsString('SH-04', $this->refusal($try));
        }
        $this->assertSame(['ACTIVE', 'Coal'], [$open->fresh()->status, $open->fresh()->material]);
    }

    public function test_a_split_twice_in_the_same_minute_is_refused_and_a_later_minute_chains(): void
    {
        $second = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertStringContainsString('only started at 10:00. Pick a later time.', $this->refusal(fn () => $this->service->splitFace($second, 'Softs', $this->user, '10:00')));
        $this->assertStringContainsString('is closed', $this->refusal(fn () => $this->service->splitFace($this->face->fresh(), 'Softs', $this->user, '10:00')));
        $this->assertSame(2, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());

        $third = $this->service->splitFace($second->fresh(), 'Softs', $this->user, '10:01');
        $this->assertSame([3, 'Softs'], [$third->seq, $third->material]);
        $this->assertSame('Face 2 (Interburden until 10:01)', $this->service->splitOrigin($third));
        $this->assertSame(['1@07:30-10:00', '2@10:00-10:01', '3@10:01-open'], $this->history($this->truck1));
        $this->assertSound();
    }

    public function test_the_new_material_is_bounded_like_the_column_and_counted_in_characters(): void
    {
        $this->assertStringContainsString('LU-05 Pick the new material', $this->refusal(fn () => $this->service->splitFace($this->face, '', $this->user, '10:00')));
        $this->assertStringContainsString('LU-05 Pick the new material', $this->refusal(fn () => $this->service->splitFace($this->face, " \t ", $this->user, '10:00')));
        $this->assertStringContainsString('The material is 65 characters; keep it to 64 or fewer.', $this->refusal(fn () => $this->service->splitFace($this->face, str_repeat('a', 65), $this->user, '10:00')));
        $this->assertStringContainsString('The material is 65 characters; keep it to 64 or fewer.', $this->refusal(fn () => $this->service->splitFace($this->face, str_repeat('é', 65), $this->user, '10:00')));
        $this->assertStringContainsString('The material is 65 characters; keep it to 64 or fewer.', $this->refusal(fn () => $this->service->editFace($this->face, ['material' => str_repeat('a', 65)], $this->user, materialCorrection: true)));
        $this->assertStringContainsString('The material is 65 characters', $this->refusal(fn () => $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => str_repeat('a', 65)], $this->user, '09:00')));
        $this->assertSame(1, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());

        $exact = str_repeat('é', 64);
        $new = $this->service->splitFace($this->face, $exact, $this->user, '10:00');
        $this->assertSame($exact, $new->fresh()->material);

        $this->assertStringContainsString('The reason is 256 characters; keep it to 255 or fewer.', $this->refusal(fn () => $this->service->splitFace($new, 'Softs', $this->user, '10:30', str_repeat('é', 256))));
        $this->assertStringContainsString('The reason is 240 characters; with this material keep it to 228 or fewer.', $this->refusal(fn () => $this->service->splitFace($new, str_repeat('b', 5), $this->user, '10:30', str_repeat('é', 240))), 'the closing sentence must fit its column too');
        $this->service->splitFace($new->fresh(), 'Softs', $this->user, '10:30', str_repeat('é', 228));
        $this->assertSame(255, mb_strlen($new->fresh()->close_reason));
    }

    public function test_a_linked_face_hands_its_link_to_the_face_that_carries_on_only_while_the_rule_still_holds(): void
    {
        $seam = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C7', 'blocks' => ['B1', 'B2'], 'loader_ids' => [$this->loader2()->id]], $this->user, '07:00');
        $parting = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Parting', 'cut' => 'C7', 'blocks' => ['B2'], 'loader_ids' => [$this->loader2()->id]], $this->user, '07:00');
        $this->service->place($this->shift, $seam, $this->truck1, $this->user, '09:00', true);
        $this->assertSame([$parting->id], $this->service->linkedFaces($seam)->pluck('id')->all());

        $next = $this->service->splitFace($seam, 'Dirty coal', $this->user, '10:00');

        $this->assertSame([$parting->id], $this->service->linkedFaces($next)->pluck('id')->all(), 'same cut and block, the same open loading unit: still linked');
        $this->assertSame([$next->id], $this->service->linkedFaces($parting)->pluck('id')->all());
        $this->assertSame([], $this->service->linkedFaces($seam->fresh())->all(), 'the closed face is linked to nothing');
        $this->assertSame('Face 4', $this->service->alsoLoads($this->loader2(), $parting->fresh()), 'the loading unit now works the parting and the face that carries on');
        $partingStint = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $parting->id)->where('equipment_id', $this->loader2()->id)->firstOrFail();
        $this->assertSame(['07:00', null], [$partingStint->from_at->format('H:i'), $partingStint->to_at?->format('H:i')], 'the partner face is untouched');
        $this->assertSound();

        // the link rule still guards the new face: an edit that leaves the shared cut and block is refused as on any linked face
        $this->assertStringContainsString('LU-01 EX-02 also loads', $this->refusal(fn () => $this->service->editFace($next->fresh(), ['material' => 'Dirty coal', 'cut' => 'C9', 'blocks' => ['B7']], $this->user)));

        // a partner that closed before the split leaves nothing to link to
        $this->service->closeFace($parting->fresh(), 'Finished', $this->user, '10:30');
        $after = $this->service->splitFace($next->fresh(), 'Waste', $this->user, '11:00');
        $this->assertSame([], $this->service->linkedFaces($after)->all());
        $this->assertSound();
    }

    public function test_a_link_that_ended_before_the_split_is_not_resurrected_by_it(): void
    {
        $seam = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C7', 'blocks' => ['B1'], 'loader_ids' => [$this->loader2()->id]], $this->user, '07:00');
        $parting = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Parting', 'cut' => 'C7', 'blocks' => ['B1'], 'loader_ids' => [$this->loader2()->id]], $this->user, '07:00');
        // the loading unit leaves the parting at 11:00; the seam's change of material is recorded late, for 10:00
        $this->service->release(LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $parting->id)->where('equipment_id', $this->loader2()->id)->firstOrFail(), 'Parting finished', $this->user, '11:00');

        $next = $this->service->splitFace($seam, 'Dirty coal', $this->user, '10:00');

        $this->assertSame([], $this->service->linkedFaces($next)->all(), 'linked faces are derived from the open stints: the parting has none now');
        $this->assertSame(['2@07:00-10:00', '3@07:00-11:00', '4@10:00-open'], $this->history($this->loader2()));
        $this->assertSound();
    }

    public function test_a_correction_that_straddles_the_split_still_keeps_to_the_life_of_each_face(): void
    {
        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');
        $oldStint = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $this->face->id)->where('equipment_id', $this->truck1->id)->firstOrFail();
        $newStint = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->where('equipment_id', $this->truck1->id)->firstOrFail();

        $before = $this->refusal(fn () => $this->service->correctTime($newStint, LineupCorrection::ARRIVAL, '09:30', 'Clicked late', $this->user));
        $after = $this->refusal(fn () => $this->service->correctTime($newStint, LineupCorrection::ARRIVAL, '10:30', 'Clicked late', $this->user));
        $departure = $this->refusal(fn () => $this->service->correctTime($oldStint, LineupCorrection::DEPARTURE, '10:30', 'Clicked late', $this->user));
        $earlier = $this->refusal(fn () => $this->service->correctTime($oldStint, LineupCorrection::DEPARTURE, '09:30', 'Clicked late', $this->user));

        $this->assertStringContainsString('Outside the life of the face: Face 2 — Loading area 1 — Interburden · C1 / B1 ran 10:00–now.', $before);
        $this->assertStringContainsString('Outside the life of the face: Face 1 — Loading area 1 — Overburden · C1 / B1 ran 07:00–10:00.', $after);
        $this->assertStringContainsString('Outside the life of the face: Face 1', $departure);
        $this->assertStringContainsString('Outside the life of the face: Face 2', $earlier);
        $this->assertSame(['1@07:30-10:00', '2@10:00-open'], $this->history($this->truck1));

        // inside the life of a face the slice 1 rules still work, and the cut stint can still be corrected at its arrival
        $this->service->correctTime($oldStint, LineupCorrection::ARRIVAL, '07:10', 'Clicked late', $this->user);
        $this->assertSame(['1@07:10-10:00', '2@10:00-open'], $this->history($this->truck1));
        $this->service->release($newStint, 'Refuelling', $this->user, '10:40');
        $this->service->correctTime($newStint->fresh(), LineupCorrection::DEPARTURE, '10:20', 'Wrong click', $this->user);
        $this->assertSame(['1@07:10-10:00', '2@10:00-10:20'], $this->history($this->truck1));
        $this->assertSound();
    }

    public function test_a_correction_across_the_split_moment_says_why_in_the_room_clock(): void
    {
        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');
        $oldStint = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $this->face->id)->where('equipment_id', $this->truck1->id)->firstOrFail();
        $newStint = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->where('equipment_id', $this->truck1->id)->firstOrFail();
        $why = 'Face 1 changed material at 10:00 and Face 2 carries on from then, so a move across that moment cannot be corrected past it.';

        foreach ([[$newStint, LineupCorrection::ARRIVAL, '09:30'], [$newStint, LineupCorrection::ARRIVAL, '10:30'], [$oldStint, LineupCorrection::DEPARTURE, '10:30'], [$oldStint, LineupCorrection::DEPARTURE, '09:30']] as [$stint, $edge, $time]) {
            $message = $this->refusal(fn () => $this->service->correctTime($stint, $edge, $time, 'Clicked late', $this->user));
            $this->assertStringContainsString('Outside the life of the face', $message);
            $this->assertStringContainsString($why, $message, $edge.' '.$time);
        }

        $plain = $this->refusal(fn () => $this->service->correctTime($oldStint, LineupCorrection::ARRIVAL, '06:30', 'Clicked late', $this->user));
        $this->assertStringContainsString('Outside the life of the face', $plain);
        $this->assertStringNotContainsString('changed material', $plain, 'a refusal that has nothing to do with a split does not mention one');
    }

    public function test_a_split_changes_no_derived_figure_no_operator_row_and_calls_no_one(): void
    {
        $derived = fn (): array => app(ShiftDerivation::class)->unitSummaries($this->shift)->map(fn (array $u) => collect($u)->except('equipment')->all())->all();
        $before = $derived();
        $operators = ShiftOperatorAssignment::withoutGlobalScopes()->count();
        $columns = Schema::getColumnListing('lineup_faces');

        $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertSame($before, $derived(), 'the line-up is not read by the derived hours, loads and stoppages');
        $this->assertSame($operators, ShiftOperatorAssignment::withoutGlobalScopes()->count());
        $this->assertSame([], array_values(array_filter($columns, fn (string $c) => preg_match('/quantity|tonn|percent|share|load/i', $c) === 1)), 'no quantity, tonnage or percentage is stored anywhere on a face (D3)');
        $this->assertSame([], array_values(array_filter(Schema::getColumnListing('lineup_allocations'), fn (string $c) => preg_match('/quantity|tonn|percent|share|load/i', $c) === 1)));
        Http::assertNothingSent();
    }

    public function test_a_split_is_audited_with_the_reason_on_the_closed_face_and_the_new_face_names_its_origin(): void
    {
        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00', 'Hit the interburden');

        $closed = AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'updated')->latest('id')->firstOrFail();
        $this->assertSame('Material changed to Interburden: Hit the interburden at 10:00', $closed->reason);
        $this->assertSame('CLOSED', $closed->changes['status']['new']);
        $created = AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $new->id)->where('action', 'created')->firstOrFail();
        $this->assertSame($this->face->id, $created->changes['split_from_face_id']['new']);
        $audits = AuditEntry::where('entity_type', LineupAllocation::class)->get();
        $this->assertSame(3, $audits->where('action', 'created')->filter(fn (AuditEntry $e) => (int) ($e->changes['lineup_face_id']['new'] ?? 0) === $new->id)->count(), 'the carried stints are audited as created');
        $this->assertSame(3, $audits->where('action', 'updated')->filter(fn (AuditEntry $e) => ($e->changes['ended_reason']['new'] ?? null) === 'Face changed material')->count());
    }

    public function test_a_split_re_reads_the_face_under_its_locks_and_takes_them_before_reading(): void
    {
        $transactions = [];
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions[] = [];
        });
        DB::listen(function ($query) use (&$transactions): void {
            if ($transactions !== []) {
                $transactions[array_key_last($transactions)][] = $query->sql;
            }
        });

        $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertCount(1, $transactions, 'one transaction');
        $queries = $transactions[0];
        $postgres = DB::connection()->getDriverName() !== 'sqlite';
        $this->assertStringStartsWith($postgres ? 'select' : 'update "lineup_allocations" set "updated_at"', $queries[0], 'the first machine first (Postgres: its equipment row, then its stints)');
        $faceLock = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'update "lineup_faces" set "updated_at"') && str_contains($sql, '"id" = ?'));
        $lastMachineLock = collect($queries)->keys()->filter(fn (int $i) => str_starts_with($queries[$i], 'update "lineup_allocations" set "updated_at"'))->max();
        $firstWrite = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'insert') || (str_starts_with($sql, 'update') && ! str_contains($sql, 'set "updated_at"')));
        $this->assertNotFalse($faceLock, 'the face row itself is locked');
        $numbering = collect($queries)->search(fn (string $sql) => str_contains($sql, '"shift_id" = ?') && str_starts_with($sql, 'update "lineup_faces" set "updated_at"') || str_contains($sql, 'for no key update'));
        $this->assertNotFalse($numbering);
        $this->assertGreaterThan($faceLock, $numbering, 'the numbering lock comes last of the locks');
        $this->assertGreaterThan($lastMachineLock, $faceLock, 'machines first, then the face');
        $this->assertGreaterThan($faceLock, $firstWrite, 'nothing is changed before every lock is held');
    }

    public function test_a_split_is_refused_when_the_face_changed_between_the_screen_and_the_lock(): void
    {
        $fired = false;
        $extra = $this->extraTruck();
        Event::listen(TransactionBeginning::class, function () use (&$fired, $extra): void {
            if ($fired) {
                return;
            }
            $fired = true;
            // another supervisor puts a truck on the face just before this split takes its locks
            DB::table('lineup_allocations')->insert(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'lineup_face_id' => $this->face->id, 'equipment_id' => $extra->id, 'role' => 'HAUL', 'from_at' => '2026-10-15 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        });

        $message = $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00'));

        $this->assertTrue($fired);
        $this->assertStringContainsString('LU-15 Someone put a machine on Face 1', $message);
        $this->assertSame('ACTIVE', $this->face->fresh()->status);
        $this->assertSame(1, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());
    }

    public function test_a_split_is_refused_when_the_material_changed_to_the_new_one_meanwhile(): void
    {
        $stale = LineupFace::withoutGlobalScopes()->findOrFail($this->face->id);
        $this->service->editFace($this->face, ['material' => 'Interburden', 'cut' => 'C1', 'blocks' => ['B1']], $this->user, materialCorrection: true);

        $this->assertStringContainsString('LU-05 Pick the new material', $this->refusal(fn () => $this->service->splitFace($stale, 'Interburden', $this->user, '10:00')));
        $this->assertSame(1, LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());
    }

    public function test_the_seq_of_the_new_face_comes_after_the_highest_even_when_faces_were_closed(): void
    {
        $other = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '07:00');
        $this->service->closeFace($other, 'Finished', $this->user, '09:00');
        $third = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '09:00');

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');

        $this->assertSame([2, 3, 4], [$other->seq, $third->seq, $new->seq]);
    }

    public function test_the_next_shift_copies_only_the_face_that_carries_on(): void
    {
        $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');

        $proposal = $this->service->proposal($night);

        $this->assertSame(['Interburden'], collect($proposal['faces'])->map(fn (array $f) => $f['face']->material)->all());
        $this->assertSame([['EX-01'], ['HT-01', 'HT-02']], [collect($proposal['faces'][0]['loaders'])->pluck('name')->all(), collect($proposal['faces'][0]['trucks'])->pluck('name')->sort()->values()->all()]);
    }

    public function test_an_edit_reads_the_material_again_under_the_lock_and_returns_the_face_as_saved(): void
    {
        $stale = LineupFace::withoutGlobalScopes()->findOrFail($this->face->id);
        $this->service->editFace($this->face, ['material' => 'Interburden', 'cut' => 'C1', 'blocks' => ['B1']], $this->user, materialCorrection: true);

        // the screen still shows Overburden, so putting Overburden back looks like no change; under the lock it reverts a colleague's correction
        $message = $this->refusal(fn () => $this->service->editFace($stale, ['material' => 'Overburden', 'cut' => 'C1', 'blocks' => ['B1']], $this->user));
        $this->assertStringContainsString('LU-14 Face 1 — Loading area 1 — Interburden · C1 / B1 has been loading Interburden since 07:00.', $message);

        $saved = $this->service->editFace($this->face->fresh(), ['material' => 'Interburden', 'cut' => 'C4', 'blocks' => ['B1'], 'notes' => 'wet'], $this->user);
        $this->assertSame(['Interburden', 'C4', 'wet'], [$saved->material, $saved->cut, $saved->notes], 'the face that comes back is the one that was saved');
    }

    public function test_adding_a_face_takes_the_write_lock_before_it_reads_the_highest_number(): void
    {
        $transactions = [];
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions[] = [];
        });
        DB::listen(function ($query) use (&$transactions): void {
            if ($transactions !== []) {
                $transactions[array_key_last($transactions)][] = strtolower($query->sql);
            }
        });

        $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user, '09:00');

        $queries = $transactions[0];
        $postgres = DB::connection()->getDriverName() !== 'sqlite';
        $this->assertStringContainsString($postgres ? 'for no key update' : 'update "lineup_faces" set "updated_at"', $queries[0], 'the first statement of the transaction is the lock');
        $max = collect($queries)->search(fn (string $sql) => str_contains($sql, 'max("seq")'));
        $this->assertNotFalse($max);
        $this->assertGreaterThan(0, $max, 'the highest number is read after the lock');
    }

    private function loader2(): Equipment
    {
        return Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->where('name', 'EX-02')->first()
            ?? Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
    }

    private function extraTruck(): Equipment
    {
        return Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->where('name', 'HT-09')->first()
            ?? Equipment::factory()->for($this->room)->create(['name' => 'HT-09', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
    }
}
