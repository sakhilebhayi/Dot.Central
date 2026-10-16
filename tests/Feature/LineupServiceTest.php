<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The line-up service: faces, placements, standby, operators, copy from
 * the previous shift, checks and confirmation (13-shift-lineup-design.md).
 */
class LineupServiceTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck1;

    private Equipment $truck2;

    private Equipment $dozer;

    private Operator $op1;

    private Operator $op2;

    private LineupService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = $this->member($owner->currentTeam, 'operator');
        $this->shift = $this->openShift('DAY', '2026-10-15', '2026-10-15 06:00:00', '2026-10-15 18:00:00');
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck1 = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'role_class' => RoleClass::SUPPORT, 'mines_status' => 'active']);
        $this->op1 = Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'One']);
        $this->op2 = Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'Two']);
        $this->service = app(LineupService::class);
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    private function openShift(string $code, string $date, string $start, string $end, string $status = 'OPEN'): Shift
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', $code)->firstOrFail();

        return Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'status' => $status]);
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

    public function test_placing_moving_and_releasing_machines_on_a_timed_shift(): void
    {
        $face1 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'cut' => 'c1', 'blocks' => ['b2', 'b1'], 'loader_ids' => [$this->loader->id]], $this->user);
        $this->assertSame('Face 1 — Loading area 1 — Overburden · C1 / B2 B1', $face1->label());
        $this->assertSame('LOADER', LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->firstOrFail()->role);

        $a = $this->service->place($this->shift, $face1, $this->truck1, $this->user, '10:20');
        $this->assertSame('HAUL', $a->role);
        $this->assertSame('2026-10-15 10:20:00', $a->from_at->toDateTimeString());

        $face2 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'AREA', 'activity' => 'Dozing'], $this->user);
        $this->assertStringContainsString('LU-03', $this->refusal(fn () => $this->service->place($this->shift, $face2, $this->truck2, $this->user, null, true)), 'a truck never goes to a work area');

        $support = $this->service->place($this->shift, $face2, $this->dozer, $this->user);
        $this->assertSame('SUPPORT', $support->role);

        $face3 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);
        $message = $this->refusal(fn () => $this->service->place($this->shift, $face3, $this->truck1, $this->user));
        $this->assertStringContainsString('LU-01', $message);
        $this->assertStringContainsString('Face 1', $message);
        $moved = $this->service->place($this->shift, $face3, $this->truck1, $this->user, '11:00', true);
        $old = $a->fresh();
        $this->assertSame('2026-10-15 11:00:00', $old->to_at->toDateTimeString());
        $this->assertStringContainsString('Moved to Face 3', $old->ended_reason);
        $this->assertSame($face3->id, $moved->lineup_face_id);

        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->place($this->shift, $face1, $this->truck2, $this->user, '19:00')), 'outside the shift');
        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->place($this->shift, $face1, $this->truck2, $this->user, '14:00')), 'in the future');
        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->release($moved, '', $this->user)), 'a reason is required once timed');

        $this->service->release($moved, 'Sent to the workshop', $this->user);
        $this->assertNotNull($moved->fresh()->to_at);
        $pool = $this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name);
        $this->assertSame('FREE', $pool['HT-01']['state']);
        $this->assertSame('PLACED', $pool['EX-01']['state']);
        $this->assertSame(3, AuditEntry::where('entity_type', LineupFace::class)->where('action', 'created')->count());
    }

    public function test_planning_before_the_shift_starts_applies_from_shift_start_and_a_move_replaces(): void
    {
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $this->assertTrue($this->service->isPlanning($night));

        $face1 = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);
        $face2 = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Softs'], $this->user);
        $a = $this->service->place($night, $face1, $this->truck1, $this->user, '10:20');
        $this->assertSame('2026-10-15 18:00:00', $a->from_at->toDateTimeString(), 'planning ignores the typed time');

        $this->service->place($night, $face2, $this->truck1, $this->user, null, true);
        $this->assertNull(LineupAllocation::withoutGlobalScopes()->find($a->id), 'a planning move replaces the row');
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->count());

        $this->service->release(LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->firstOrFail(), null, $this->user);
        $this->assertSame(0, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->count());
    }

    public function test_a_machine_dot_mines_reports_stopped_is_down_and_cannot_be_placed(): void
    {
        EquipmentStatusEvent::create(['equipment_id' => $this->truck2->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 09:00:00', 'source' => 'sync']);
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);

        $message = $this->refusal(fn () => $this->service->place($this->shift, $face, $this->truck2, $this->user));
        $this->assertStringContainsString('LU-02', $message);
        $this->assertStringContainsString('maintenance', $message);
        $this->assertSame('DOWN', $this->service->pool($this->shift)->first(fn ($r) => $r['equipment']->id === $this->truck2->id)['state']);

        $this->service->place($this->shift, $face, $this->truck2, $this->user, '08:30');
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck2->id)->count(), 'placing at a time before it stopped is allowed');
    }

    public function test_standby_reasons_take_a_machine_off_and_placing_ends_them(): void
    {
        $standbyReason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'NO_OPERATOR')->firstOrFail();
        $breakdown = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'BREAKDOWN')->firstOrFail();
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);
        $this->service->place($this->shift, $face, $this->truck1, $this->user, '10:00');

        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck1, $breakdown, $this->user)), 'breakdowns are derived, not typed');
        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck1, $standbyReason, $this->user, '09:00')), 'before it arrived');

        $standby = $this->service->setStandby($this->shift, $this->truck1, $standbyReason, $this->user, '11:30');
        $this->assertNotNull(LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->firstOrFail()->to_at);
        $this->assertSame('STANDBY', $this->service->pool($this->shift)->first(fn ($r) => $r['equipment']->id === $this->truck1->id)['state']);
        $this->assertStringContainsString('LU-05', $this->refusal(fn () => $this->service->setStandby($this->shift, $this->truck1, $standbyReason, $this->user)));

        $this->service->place($this->shift, $face, $this->truck1, $this->user);
        $this->assertNotNull($standby->fresh()->to_at, 'placing ends the standby');
    }

    public function test_one_operator_per_machine_and_one_machine_per_operator(): void
    {
        $this->service->assignOperator($this->shift, $this->truck1, $this->op1, $this->user);
        $message = $this->refusal(fn () => $this->service->assignOperator($this->shift, $this->truck2, $this->op1, $this->user));
        $this->assertStringContainsString('LU-04', $message);
        $this->assertStringContainsString('HT-01', $message);

        $this->service->assignOperator($this->shift, $this->truck2, $this->op1, $this->user, true);
        $pool = $this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name);
        $this->assertNull($pool['HT-01']['operator'], 'moving the operator clears the other machine');
        $this->assertSame($this->op1->id, $pool['HT-02']['operator']->id);

        $this->service->assignOperator($this->shift, $this->truck2, $this->op2, $this->user);
        $this->assertSame($this->op2->id, $this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name)['HT-02']['operator']->id, 'a new operator relieves the old');

        $inactive = Operator::factory()->for($this->room)->create(['is_active' => false]);
        $this->assertStringContainsString('LU-04', $this->refusal(fn () => $this->service->assignOperator($this->shift, $this->truck1, $inactive, $this->user)));

        $this->service->clearOperator($this->shift, $this->truck2, $this->user);
        $this->assertNull($this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name)['HT-02']['operator']);
    }

    public function test_copy_from_the_previous_shift_recreates_faces_places_machines_not_down_and_fills_operators(): void
    {
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->user);
        $this->service->place($this->shift, $face, $this->truck1, $this->user);
        $this->service->place($this->shift, $face, $this->truck2, $this->user);
        $area = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'AREA', 'activity' => 'Dozing'], $this->user);
        $this->service->place($this->shift, $area, $this->dozer, $this->user);
        $this->service->assignOperator($this->shift, $this->truck1, $this->op1, $this->user);
        $this->service->assignOperator($this->shift, $this->loader, $this->op2, $this->user);
        $this->service->confirm($this->shift, $this->user);

        Carbon::setTestNow('2026-10-15 18:30:00');
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        EquipmentStatusEvent::create(['equipment_id' => $this->truck2->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'offline', 'observed_at' => '2026-10-15 17:00:00', 'source' => 'sync']);

        $result = $this->service->copyFromPrevious($night, $this->user);

        $this->assertSame(2, $result['faces']);
        $this->assertSame(3, $result['placed'], 'loader, one truck and the dozer; the offline truck is skipped');
        $this->assertSame(2, $result['operators']);
        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('HT-02', $result['skipped'][0]);
        $this->assertStringContainsString('LU-02', $result['skipped'][0]);
        $faces = $this->service->faces($night);
        $this->assertSame(['Coal', 'Dozing'], $faces->map(fn (LineupFace $f) => $f->what())->all());
        $this->assertSame($this->shift->id, $this->service->lineup($night)->copied_from_shift_id);
        $pool = $this->service->pool($night)->keyBy(fn ($r) => $r['equipment']->name);
        $this->assertSame('DOWN', $pool['HT-02']['state']);
        $this->assertSame($this->op1->id, $pool['HT-01']['operator']->id);
        $this->assertStringContainsString('LU-01', $this->refusal(fn () => $this->service->copyFromPrevious($night, $this->user)));
    }

    public function test_checks_and_confirmation(): void
    {
        $this->assertStringContainsString('LU-06', $this->refusal(fn () => $this->service->confirm($this->shift, $this->user)), 'nothing to confirm');

        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'loader_ids' => [$this->loader->id]], $this->user);
        $codes = $this->service->checks($this->shift)->pluck('code')->countBy()->all();
        $this->assertSame(1, $codes['LU-06'], 'loader without trucks');
        $this->assertSame(3, $codes['LU-09'], 'three free machines once the shift has started');
        $this->assertSame(1, $codes['LU-10'], 'loader without operator');

        $this->room->settings()->set('trucks_per_loader', 1);
        $this->service->place($this->shift, $face, $this->truck1, $this->user);
        $this->service->place($this->shift, $face, $this->truck2, $this->user);
        $codes = $this->service->checks($this->shift)->pluck('code')->countBy()->all();
        $this->assertArrayNotHasKey('LU-06', $codes);
        $this->assertSame(1, $codes['LU-07'], 'two trucks for one loader with a ceiling of one');
        $this->assertSame(1, $codes['LU-09'], 'only the dozer is free now');

        $lineup = $this->service->confirm($this->shift, $this->user);
        $this->assertTrue($lineup->isConfirmed());
        $this->assertSame(1, $lineup->version);
        $this->assertDatabaseHas('audit_log', ['entity_type' => ShiftLineup::class, 'entity_id' => $lineup->id, 'action' => 'approve', 'shift_id' => $this->shift->id]);
        $this->assertSame(2, $this->service->confirm($this->shift, $this->user)->version, 're-confirm bumps the version');
    }

    public function test_a_closed_shift_refuses_every_change(): void
    {
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $this->shift->refresh();

        $this->assertStringContainsString('SH-04', $this->refusal(fn () => $this->service->place($this->shift, $face, $this->truck1, $this->user)));
        $this->assertStringContainsString('SH-04', $this->refusal(fn () => $this->service->assignOperator($this->shift, $this->truck1, $this->op1, $this->user)));
        $this->assertStringContainsString('SH-04', $this->refusal(fn () => $this->service->confirm($this->shift, $this->user)));
    }

    public function test_an_assignment_of_a_dot_mines_operator_is_pushed_through_its_eligibility_gate(): void
    {
        $this->truck1->forceFill(['mines_machine_id' => 7])->save();
        $this->op1->forceFill(['mines_operator_id' => 3])->save();
        Http::fake([
            'mines.test/api/v1/machines/7/operator-assignments' => Http::sequence()
                ->push(['data' => ['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'shift' => 'day', 'assigned_at' => now()->toIso8601String(), 'unassigned_at' => null], 'message' => 'ok'], 201)
                ->push(['message' => 'The given data was invalid.', 'errors' => ['operator_id' => ['Operator Two holds no Articulated Dump Truck licence.']]], 422)
                ->push(['data' => ['id' => 55, 'unassigned_at' => now()->toIso8601String()], 'message' => 'ok']),
        ]);

        $assignment = $this->service->assignOperator($this->shift, $this->truck1, $this->op1, $this->user);
        $this->assertTrue($assignment->pushed_to_source);
        $this->assertSame('pushed to Dot.Mines', $assignment->push_note);
        $this->assertSame(3, $this->truck1->fresh()->mines_operator_id);

        $this->op2->forceFill(['mines_operator_id' => 4])->save();
        $message = $this->refusal(fn () => $this->service->assignOperator($this->shift, $this->truck1, $this->op2, $this->user));
        $this->assertStringContainsString('LU-04', $message);
        $this->assertStringContainsString('holds no Articulated Dump Truck licence', $message);
        $this->assertSame($this->op1->id, $this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name)['HT-01']['operator']->id, 'a refused push leaves the previous operator in place');

        $local = Operator::factory()->for($this->room)->create(['first_name' => 'Local', 'last_name' => 'Only']);
        $this->service->assignOperator($this->shift, $this->truck2, $local, $this->user);
        $this->assertSame('not in Dot.Mines: added locally', ShiftOperatorAssignment::withoutGlobalScopes()->where('equipment_id', $this->truck2->id)->whereNull('to_at')->firstOrFail()->push_note);

        $this->service->clearOperator($this->shift, $this->truck1, $this->user);
        Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/v1/machines/7/operator-assignments'));
        $this->assertNull($this->truck1->fresh()->mines_operator_id);
    }

    public function test_fill_operators_prefers_who_dot_mines_has_on_the_machine_now(): void
    {
        $this->op1->forceFill(['mines_operator_id' => 3])->save();
        $this->truck1->forceFill(['mines_operator_id' => 3, 'mines_operator_assignment_id' => 55])->save();
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal'], $this->user);
        $this->service->place($this->shift, $face, $this->truck1, $this->user);

        $this->assertSame(1, $this->service->fillOperators($this->shift, $this->user));
        $row = $this->service->pool($this->shift)->keyBy(fn ($r) => $r['equipment']->name)['HT-01'];
        $this->assertSame($this->op1->id, $row['operator']->id);
        $this->assertSame($this->op1->id, $row['mines_operator']->id);
        $this->assertSame('already current in Dot.Mines', $row['assignment']->push_note);
    }
}
