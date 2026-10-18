<?php

namespace Tests\Feature\Memory;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\AlertWorkflow;
use App\Services\Audits;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Services\Rules\ExceptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Every row of the mapping table in the design (spec §5): the control
 * room acts through its real services, the audit row is translated, and
 * the outbox holds the loop record Memory will receive.
 */
class AuditLoopMapperTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $operator;

    private User $supervisor;

    private Equipment $truck;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 12:00:00');
        Queue::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok']);
        MemoryConnection::factory()->create(['team_id' => $owner->currentTeam->id]);
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-16', 'starts_at' => '2026-10-16 06:00:00', 'ends_at' => '2026-10-16 18:00:00']);
        $this->operator = $this->member($owner->currentTeam, 'operator');
        $this->supervisor = $this->member($owner->currentTeam, 'supervisor');
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'mines_machine_id' => 701]);
        MemoryOutboxRow::query()->delete();
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    /** @return array<string, mixed> */
    private function payload(string $stage, int $index = 0): array
    {
        $rows = MemoryOutboxRow::where('stage', $stage)->orderBy('id')->get();
        $this->assertGreaterThan($index, $rows->count(), "expected a '{$stage}' row");

        return $rows[$index]->payload;
    }

    public function test_exception_lifecycle_maps_to_observation_decision_action_and_outcome(): void
    {
        $exception = OperationalException::factory()->for($this->room)->create(['shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'rule_code' => 'TEL-01', 'severity' => 'CRITICAL']);
        $event = $this->payload('event');
        $this->assertSame('dot-central:exception:TEL-01', $event['signature']);
        $this->assertSame(['equipment', '701', 'HT-01'], [$event['subject_type'], $event['subject_id'], $event['subject_label']]);
        $this->assertSame('dot-central:exception:'.$exception->id, $event['loop_id']);

        $this->actingAs($this->operator);
        app(ExceptionService::class)->explain($exception, $this->operator, 'Tracker removed in workshop');
        $decision = $this->payload('decision');
        $this->assertSame('Tracker removed in workshop', $decision['recommendation']);
        $this->assertEquals([1.0, 0.9, 'approve', true], [$decision['confidence'], $decision['risk'], $decision['autonomy_level'], $decision['requires_approval']]);
        $this->assertSame($this->operator->id, $decision['user_id']);

        app(ExceptionService::class)->decide($exception->fresh(), $this->supervisor, 'APPROVED', 'Agreed');
        $action = $this->payload('action');
        $this->assertSame(['exception.approve', 'approved', 'succeeded'], [$action['action_kind'], $action['approval_status'], $action['execution_status']]);

        $exception->fresh()->forceFill(['status' => 'RESOLVED', 'resolution' => 'Condition cleared'])->save();
        $outcome = $this->payload('outcome');
        $this->assertSame(['improved', 'exception.status', 'RESOLVED'], [$outcome['verdict'], $outcome['measure'], $outcome['value_after']]);
    }

    public function test_rejected_and_dismissed_exceptions(): void
    {
        $a = OperationalException::factory()->for($this->room)->create(['status' => 'EXPLAINED']);
        app(ExceptionService::class)->decide($a, $this->supervisor, 'REJECTED', 'Not acceptable');
        $this->assertSame(['exception.reject', 'rejected'], [$this->payload('action')['action_kind'], $this->payload('action')['approval_status']]);

        $b = OperationalException::factory()->for($this->room)->create();
        app(ExceptionService::class)->dismiss($b, $this->supervisor, 'Duplicate');
        $this->assertSame(['inconclusive', 'DISMISSED'], [$this->payload('outcome')['verdict'], $this->payload('outcome')['value_after']]);
    }

    public function test_alert_actions_carry_the_push_result_and_resolve_records_an_outcome(): void
    {
        $alert = Alert::factory()->for($this->room)->create(['source' => 'dot_mines', 'external_id' => '55', 'severity' => 'critical', 'equipment_id' => $this->truck->id, 'triggered_at' => now()->subMinutes(30), 'source_triggered_at' => now()->subMinutes(45)]);
        Http::fake(['mines.test/api/v1/alerts/55/acknowledge' => Http::response(['data' => ['id' => 55]]), 'mines.test/api/v1/alerts/55/resolve' => Http::response(['message' => 'forbidden'], 403)]);

        app(AlertWorkflow::class)->acknowledge($alert, $this->operator, true);
        $ack = $this->payload('action');
        $this->assertSame(['alert.acknowledge', 'dot-mines', 'succeeded', 'dot-central:alert:critical'], [$ack['action_kind'], $ack['executor_platform'], $ack['execution_status'], $ack['signature']]);

        app(AlertWorkflow::class)->resolve($alert->fresh(), $this->operator, 'Fixed on site', true);
        $resolve = $this->payload('action', 1);
        $this->assertSame(['alert.resolve', 'refused'], [$resolve['action_kind'], $resolve['execution_status']]);
        $outcome = $this->payload('outcome');
        $this->assertSame(['improved', 'alert.time_to_clear_minutes', 45], [$outcome['verdict'], $outcome['measure'], $outcome['value_after']]);
        $this->assertSame('dot-central:alert:'.$alert->id, $outcome['loop_id']);
    }

    public function test_alert_escalate_and_assign_are_actions_and_system_alerts_are_ignored(): void
    {
        $alert = Alert::factory()->for($this->room)->create(['source' => 'dot_mines', 'external_id' => '56', 'severity' => 'warning']);
        app(AlertWorkflow::class)->escalate($alert, $this->operator, 'No response');
        app(AlertWorkflow::class)->assign($alert->fresh(), $this->operator, $this->supervisor, 'Please look');
        $this->assertSame(['alert.escalate', 'alert.assign'], MemoryOutboxRow::where('stage', 'action')->orderBy('id')->get()->map(fn ($r) => $r->payload['action_kind'])->all());

        $before = MemoryOutboxRow::count();
        $system = Alert::factory()->for($this->room)->create(['source' => 'system', 'external_id' => 'connection:1:x', 'severity' => 'warning']);
        app(AlertWorkflow::class)->escalate($system, $this->operator, 'noise');
        $this->assertSame($before, MemoryOutboxRow::count());
    }

    public function test_downtime_classification_is_a_decision_and_an_action(): void
    {
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('counts_against_availability', true)->firstOrFail();
        $this->actingAs($this->operator);
        DowntimeClassification::create(['control_room_id' => $this->room->id, 'equipment_id' => $this->truck->id, 'shift_id' => $this->shift->id, 'downtime_reason_id' => $reason->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'note' => 'Tyre change', 'classified_by' => $this->operator->id]);

        $decision = $this->payload('decision');
        $this->assertSame([$reason->name, 'execute', false, 0.5], [$decision['recommendation'], $decision['autonomy_level'], $decision['requires_approval'], $decision['risk']]);
        $this->assertSame('dot-central:downtime:'.$reason->code, $decision['signature']);
        $this->assertSame('downtime.classify', $this->payload('action')['action_kind']);
        $this->assertSame('701', $decision['subject_id']);
        $this->assertNotSame($decision['event_id'], $this->payload('action')['event_id']);
    }

    public function test_lineup_confirmation_and_operator_assignment(): void
    {
        $lineup = ShiftLineup::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'status' => 'CONFIRMED', 'version' => 1, 'confirmed_by' => $this->supervisor->id, 'confirmed_at' => now()]);
        MemoryOutboxRow::query()->delete();
        app(Audits::class)->record('approve', $lineup, ['version' => ['old' => 0, 'new' => 1]], 'Line-up confirmed (version 1)', $this->room, $this->supervisor);
        $decision = $this->payload('decision');
        $this->assertSame(['line-up v1', 'shift', (string) $this->shift->id], [$decision['recommendation'], $decision['subject_type'], $decision['subject_id']]);
        $this->assertSame('lineup.confirm', $this->payload('action')['action_kind']);

        MemoryOutboxRow::query()->delete();
        $op = Operator::factory()->for($this->room)->create();
        ShiftOperatorAssignment::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'operator_id' => $op->id, 'from_at' => now(), 'assigned_by' => $this->operator->id, 'pushed_to_source' => true, 'push_note' => 'pushed to Dot.Mines']);
        $assign = $this->payload('action');
        $this->assertSame(['operator.assign', 'dot-mines', 'succeeded', '701'], [$assign['action_kind'], $assign['executor_platform'], $assign['execution_status'], $assign['subject_id']]);

        MemoryOutboxRow::query()->delete();
        ShiftOperatorAssignment::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'operator_id' => $op->id, 'from_at' => now()->addMinute(), 'assigned_by' => $this->operator->id, 'pushed_to_source' => false, 'push_note' => 'not in Dot.Mines: added locally']);
        $this->assertSame(['dot-central', 'pending'], [$this->payload('action')['executor_platform'], $this->payload('action')['execution_status']]);
    }

    public function test_lineup_confirmation_reports_faces_machines_placed_and_on_standby(): void
    {
        $lineup = ShiftLineup::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'status' => 'CONFIRMED', 'version' => 1, 'confirmed_by' => $this->supervisor->id, 'confirmed_at' => now()]);
        $face = LineupFace::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'seq' => 1, 'kind' => 'LOADING', 'material' => 'Overburden', 'status' => 'ACTIVE', 'active_from' => $this->shift->starts_at]);
        LineupAllocation::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'lineup_face_id' => $face->id, 'equipment_id' => $this->truck->id, 'role' => 'HAUL', 'from_at' => $this->shift->starts_at]);
        $dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'mines_machine_id' => 702]);
        $standby = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        LineupStandby::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $dozer->id, 'downtime_reason_id' => $standby->id, 'from_at' => $this->shift->starts_at]);
        MemoryOutboxRow::query()->delete();

        app(Audits::class)->record('approve', $lineup, ['version' => ['old' => 0, 'new' => 1]], 'Line-up confirmed (version 1)', $this->room, $this->supervisor);

        $detail = $this->payload('decision')['detail'];
        $this->assertSame([1, 1, 1, 1], [$detail['version'], $detail['faces'], $detail['placed'], $detail['standby']]);
        $this->assertSame('Line-up confirmed (version 1)', $detail['reason'], 'the audit context is kept alongside');
    }

    public function test_shift_start_close_approve_and_reopen(): void
    {
        MemoryOutboxRow::query()->delete();
        app(Audits::class)->record('updated', $this->shift, ['status' => ['old' => null, 'new' => 'OPEN']], 'Shift started', $this->room, $this->operator);
        $this->assertSame(['dot-central:shift:started', 'shift'], [$this->payload('event')['signature'], $this->payload('event')['subject_type']]);

        // No previous shift: the first close is inconclusive.
        $this->shift->unitSummaries()->create(['equipment_id' => $this->truck->id, 'stoppage_minutes' => 90, 'state' => 'DOWN', 'freshness' => 'fresh']);
        app(Audits::class)->record('updated', $this->shift, ['status' => ['old' => 'OPEN', 'new' => 'CLOSED']], 'Shift closed and handover issued', $this->room, $this->operator);
        $first = $this->payload('outcome');
        $this->assertSame(['inconclusive', 'shift.stoppage_hours', null, 1.5], [$first['verdict'], $first['measure'], $first['value_before'], $first['value_after']]);
        $this->shift->forceFill(['status' => 'CLOSED'])->save();

        $next = Shift::factory()->for($this->room)->for($this->shift->shiftType, 'shiftType')->create(['shift_date' => '2026-10-17', 'starts_at' => '2026-10-17 06:00:00', 'ends_at' => '2026-10-17 18:00:00']);
        $next->unitSummaries()->create(['equipment_id' => $this->truck->id, 'stoppage_minutes' => 30, 'state' => 'DOWN', 'freshness' => 'fresh']);
        MemoryOutboxRow::query()->delete();
        app(Audits::class)->record('updated', $next, ['status' => ['old' => 'OPEN', 'new' => 'CLOSED']], 'Shift closed and handover issued', $this->room, $this->operator);
        $second = $this->payload('outcome');
        $this->assertSame(['improved', 1.5, 0.5], [$second['verdict'], $second['value_before'], $second['value_after']]);

        MemoryOutboxRow::query()->delete();
        app(Audits::class)->record('approve', $next, ['status' => ['old' => 'CLOSED', 'new' => 'APPROVED']], 'Looks right', $this->room, $this->supervisor);
        app(Audits::class)->record('override', $next, ['status' => ['old' => 'APPROVED', 'new' => 'REOPENED']], 'Figures corrected', $this->room, $this->supervisor);
        $this->assertSame(['shift.approve', 'shift.reopen'], MemoryOutboxRow::where('stage', 'action')->orderBy('id')->get()->map(fn ($r) => $r->payload['action_kind'])->all());
    }

    public function test_nothing_is_queued_for_a_team_without_a_memory_connection(): void
    {
        MemoryConnection::query()->delete();
        OperationalException::factory()->for($this->room)->create();
        app(AlertWorkflow::class)->escalate(Alert::factory()->for($this->room)->create(['source' => 'dot_mines', 'external_id' => '9']), $this->operator, 'x');

        $this->assertSame(0, MemoryOutboxRow::count());
    }

    public function test_a_line_up_time_correction_is_an_action_on_the_machine(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        $stint = $service->place($this->shift, $face, $this->truck, $this->operator, '10:30');
        MemoryOutboxRow::query()->delete();

        $service->correctTime($stint, LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->operator);

        $action = $this->payload('action');
        $this->assertSame(['lineup.correct_time', 'dot-central', 'succeeded', 'dot-central:lineup:time-corrected'], [$action['action_kind'], $action['executor_platform'], $action['execution_status'], $action['signature']]);
        $this->assertSame(['equipment', '701', 'dot-central:shift:'.$this->shift->id], [$action['subject_type'], $action['subject_id'], $action['loop_id']]);
        $this->assertSame([LineupCorrection::ARRIVAL, 30], [$action['detail']['edge'], $action['detail']['minutes']]);
        $this->assertStringContainsString('corrected from 10:30 to 10:00: The move was clicked late', (string) $action['detail']['reason']);
        $this->assertSame(1, MemoryOutboxRow::where('stage', 'action')->count());
    }

    public function test_a_material_split_is_an_action_on_the_shift(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        MemoryOutboxRow::query()->delete();

        $service->splitFace($face, 'Interburden', $this->operator, '10:00', 'Hit the interburden');

        $action = $this->payload('action');
        $this->assertSame(['lineup.split_face', 'dot-central:lineup:face-split', 'shift', (string) $this->shift->id], [$action['action_kind'], $action['signature'], $action['subject_type'], $action['subject_id']]);
        $this->assertSame([$face->id, 'Overburden', 'Interburden'], [$action['detail']['split_from_face_id'], $action['detail']['material_before'], $action['detail']['material_after']]);
        $this->assertSame(['dot-central', 'succeeded', '2026-10-16T10:00:00+00:00'], [$action['executor_platform'], $action['execution_status'], $action['detail']['at']]);
        $this->assertSame('Material changed to Interburden: Hit the interburden', $action['detail']['reason'], 'the operator\'s own words travel with the record');
        $this->assertSame(1, MemoryOutboxRow::where('stage', 'action')->count(), 'ordinary face rows map to nothing');
        $this->assertSame(1, MemoryOutboxRow::count(), 'closing the old face and moving its stints add nothing else');
    }

    public function test_correcting_the_material_of_a_split_face_in_place_is_not_a_second_split(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        $next = $service->splitFace($face, 'Interburden', $this->operator, '10:00');
        MemoryOutboxRow::query()->delete();

        $service->editFace($next, ['material' => 'Softs'], $this->operator, materialCorrection: true);
        $service->closeFace($next->fresh(), 'Finished', $this->operator, '11:00');

        $this->assertSame(0, MemoryOutboxRow::count(), 'only the creation of the face that carries on is a split');
    }

    public function test_an_ordinary_face_and_a_material_correction_in_place_map_to_nothing(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        $service->editFace($face, ['material' => 'Interburden'], $this->operator, materialCorrection: true);
        $service->closeFace($face->fresh(), 'Finished', $this->operator, '09:00');

        $this->assertSame(0, MemoryOutboxRow::count());
    }

    public function test_the_loop_records_keep_their_own_detail_next_to_the_audit_context(): void
    {
        $exception = OperationalException::factory()->for($this->room)->create(['shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'rule_code' => 'TEL-01', 'severity' => 'CRITICAL', 'title' => 'No telemetry', 'message' => 'HT-01 sent nothing for an hour']);
        $detail = $this->payload('event')['detail'];
        $this->assertEqualsCanonicalizing(['audit_id', 'control_room_id', 'shift_id', 'reason', 'title', 'message', 'outcome', 'severity', 'raised_by'], array_keys($detail));
        $this->assertSame(['No telemetry', 'HT-01 sent nothing for an hour', 'CRITICAL'], [$detail['title'], $detail['message'], $detail['severity']]);

        MemoryOutboxRow::query()->delete();
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('counts_against_availability', true)->firstOrFail();
        $this->actingAs($this->operator);
        DowntimeClassification::create(['control_room_id' => $this->room->id, 'equipment_id' => $this->truck->id, 'shift_id' => $this->shift->id, 'downtime_reason_id' => $reason->id, 'started_at' => now()->subHour(), 'ended_at' => now(), 'note' => 'Tyre change', 'classified_by' => $this->operator->id]);
        foreach (['decision', 'action'] as $stage) {
            $detail = $this->payload($stage)['detail'];
            $this->assertEqualsCanonicalizing(['audit_id', 'control_room_id', 'shift_id', 'reason', 'reason_name', 'category', 'counts_against_availability', 'note', 'started_at', 'ended_at'], array_keys($detail), $stage);
            $this->assertSame([$reason->name, true, 'Tyre change'], [$detail['reason_name'], $detail['counts_against_availability'], $detail['note']]);
        }

        MemoryOutboxRow::query()->delete();
        $op = Operator::factory()->for($this->room)->create();
        ShiftOperatorAssignment::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'operator_id' => $op->id, 'from_at' => now(), 'assigned_by' => $this->operator->id, 'pushed_to_source' => true, 'push_note' => 'pushed to Dot.Mines']);
        $detail = $this->payload('action')['detail'];
        $this->assertEqualsCanonicalizing(['audit_id', 'control_room_id', 'shift_id', 'reason', 'push_note', 'operator_id'], array_keys($detail));
        $this->assertSame(['pushed to Dot.Mines', $op->id, $this->shift->id], [$detail['push_note'], $detail['operator_id'], $detail['shift_id']]);
    }
}
