<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OverviewAlertsReportsAdminTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $owner;

    private User $operator;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($this->owner->currentTeam)->create(['name' => 'North Room']);
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'machine_type' => 'haul_truck', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(3)]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 05:30:00', 'operating_hours' => 1000.0, 'idle_hours' => 10.0, 'load_count' => 500]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 11:55:00', 'operating_hours' => 1005.0, 'idle_hours' => 10.5, 'load_count' => 522]);
        $this->operator = $this->member($this->owner->currentTeam, 'operator');
        $this->supervisor = $this->member($this->owner->currentTeam, 'supervisor');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    public function test_the_landing_page_is_the_operational_overview_and_the_agent_hub_moved(): void
    {
        OperationalException::factory()->for($this->room)->create(['shift_id' => $this->shift->id, 'title' => 'EX-09 has no usable telemetry']);
        Alert::factory()->for($this->room)->create(['severity' => 'critical', 'title' => 'Low fuel on HT-01']);

        $this->actingAs($this->operator)->get('/dashboard')->assertOk()
            ->assertSee('Operational Overview')->assertSee('North Room')->assertSee('Day shift')
            ->assertSee('Operating')->assertSee('HT-01')->assertSee('EX-09 has no usable telemetry')->assertSee('Low fuel on HT-01')
            ->assertSee('Equipment by type')->assertSee('haul truck')->assertSee('Explain')
            ->assertSee('wire:poll', false);

        $this->actingAs($this->operator)->get(route('agents.hub'))->assertOk()->assertSee('Agent Hub Dashboard');
    }

    public function test_alert_workflow_acknowledge_assign_escalate_resolve(): void
    {
        $alert = Alert::factory()->for($this->room)->create(['severity' => 'critical', 'source' => 'dot_mines', 'external_id' => '77', 'title' => 'Low fuel on HT-01']);
        Http::fake(['mines.test/api/v1/alerts/77/*' => Http::response(['data' => ['id' => 77, 'status' => 'acknowledged'], 'message' => 'ok'])]);

        $this->actingAs($this->operator)->get(route('alerts.index'))->assertOk()->assertSee('Low fuel on HT-01')->assertSee('Acknowledge');

        $this->actingAs($this->operator)->post(route('alerts.acknowledge', $alert))->assertRedirect();
        $this->assertSame($this->operator->id, $alert->fresh()->acknowledged_by);
        $this->assertDatabaseHas('alert_events', ['alert_id' => $alert->id, 'action' => 'acknowledged', 'user_id' => $this->operator->id]);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/acknowledge'));

        $this->actingAs($this->operator)->post(route('alerts.assign', $alert), ['assigned_to' => $this->supervisor->id, 'note' => 'Please check'])->assertRedirect();
        $this->assertSame($this->supervisor->id, $alert->fresh()->assigned_to);

        $this->actingAs($this->operator)->post(route('alerts.escalate', $alert), ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('alerts.escalate', $alert), ['note' => 'Still unresolved after 1 h'])->assertRedirect();
        $this->assertSame(1, $alert->fresh()->escalation_level);

        $this->actingAs($this->supervisor)->post(route('alerts.resolve', $alert), ['note' => 'Refuelled', 'push' => 1])->assertRedirect();
        $this->assertNotNull($alert->fresh()->cleared_at);
        $this->assertSame('Refuelled', $alert->fresh()->resolution_note);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/v1/alerts/77/resolve'));
        $this->assertDatabaseHas('audit_log', ['entity_type' => Alert::class, 'entity_id' => $alert->id, 'action' => 'ack']);
    }

    public function test_manual_alerts_are_a_supervisor_action(): void
    {
        $payload = ['severity' => 'warning', 'title' => 'Radio outage', 'triggered_at' => now()->format('Y-m-d\TH:i')];
        $this->actingAs($this->operator)->post(route('control-rooms.alerts.store', $this->room), $payload)->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('control-rooms.alerts.store', $this->room), $payload)->assertRedirect();
        $this->assertSame('manual', Alert::where('title', 'Radio outage')->firstOrFail()->source);
    }

    public function test_a_refused_push_to_dot_mines_is_reported_not_hidden(): void
    {
        Http::fake(['http://mines.test/api/v1/alerts/78/resolve' => Http::response(['message' => 'forbidden'], 403)]);
        $alert = Alert::factory()->for($this->room)->create(['source' => 'dot_mines', 'external_id' => '78', 'severity' => 'warning', 'title' => 'Caution code']);

        $response = $this->actingAs($this->supervisor)->from(route('alerts.index'))->post(route('alerts.resolve', $alert), ['note' => 'Cleared', 'push' => 1]);

        $response->assertRedirect(route('alerts.index'))->assertSessionHas('warning', fn (string $m) => str_contains($m, 'NOT updated'));
        $this->assertDatabaseHas('alert_events', ['alert_id' => $alert->id, 'action' => 'resolved', 'pushed_to_source' => false]);
        $this->assertNotNull($alert->fresh()->cleared_at);
        $this->assertDatabaseHas('audit_log', ['entity_type' => Alert::class, 'entity_id' => $alert->id, 'action' => 'updated']);
        $this->assertStringContainsString('Dot.Mines not updated', (string) AuditEntry::where('entity_type', Alert::class)->where('entity_id', $alert->id)->where('action', 'updated')->latest('id')->value('reason'));
    }

    public function test_reports_render_from_persisted_records_and_export_is_gated_and_audited(): void
    {
        foreach (['shift', 'hours', 'production', 'downtime', 'handover', 'equipment'] as $report) {
            $this->actingAs($this->operator)->get(route('reports.index', ['r' => $report, 'shift' => $this->shift->id, 'room' => $this->room->id]))->assertOk()->assertSee('Reports');
        }
        $this->actingAs($this->operator)->get(route('reports.index', ['r' => 'hours', 'shift' => $this->shift->id]))->assertOk()->assertSee('HT-01')->assertSee('1 005.0');

        $this->actingAs($this->operator)->get(route('reports.index', ['r' => 'hours', 'shift' => $this->shift->id, 'export' => 'csv']))->assertForbidden();

        $response = $this->actingAs($this->supervisor)->get(route('reports.index', ['r' => 'hours', 'shift' => $this->shift->id, 'export' => 'csv']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('HT-01', $response->streamedContent());
        $this->assertDatabaseHas('audit_log', ['action' => 'generate', 'entity_type' => 'report', 'user_id' => $this->supervisor->id]);
    }

    public function test_administrators_manage_shift_types_reasons_and_thresholds(): void
    {
        $this->actingAs($this->operator)->get(route('control-rooms.admin', $this->room))->assertForbidden();
        $this->actingAs($this->owner)->get(route('control-rooms.admin', $this->room))->assertOk()->assertSee('Day shift')->assertSee('Breakdown — mechanical')->assertSee('offline_minutes');

        $this->actingAs($this->owner)->post(route('control-rooms.admin.shift-types', $this->room), ['code' => 'MID', 'name' => 'Mid shift', 'starts_at' => '12:00', 'ends_at' => '20:00'])->assertRedirect();
        $this->assertDatabaseHas('shift_types', ['control_room_id' => $this->room->id, 'code' => 'MID', 'crosses_midnight' => 0]);

        $this->actingAs($this->owner)->post(route('control-rooms.admin.reasons', $this->room), ['code' => 'CRUSHER', 'name' => 'Waiting on crusher', 'category' => 'OPERATIONAL_DELAY'])->assertRedirect();
        $this->assertDatabaseHas('downtime_reasons', ['control_room_id' => $this->room->id, 'code' => 'CRUSHER']);

        $this->actingAs($this->owner)->post(route('control-rooms.admin.settings', $this->room), ['offline_minutes' => 45, 'stale_minutes' => 60, 'idle_minutes_threshold' => 240, 'zero_loads_hours' => 5, 'classify_after_minutes' => 60, 'unack_minutes' => 15, 'engine_hours_budget' => 10, 'reason' => 'Site standard'])->assertRedirect();
        $this->assertSame(45, $this->room->settings()->get('offline_minutes'));
        $this->assertDatabaseHas('audit_log', ['entity_type' => ControlRoom::class, 'entity_id' => $this->room->id, 'action' => 'updated', 'reason' => 'Site standard']);

        $reason = DowntimeReason::where('code', 'CRUSHER')->firstOrFail();
        $this->actingAs($this->owner)->post(route('control-rooms.admin.reasons.toggle', [$this->room, $reason]))->assertRedirect();
        $this->assertFalse($reason->fresh()->is_active);
    }
}
