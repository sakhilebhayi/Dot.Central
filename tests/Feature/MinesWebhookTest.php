<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\IntegrationWebhookDelivery;
use App\Models\MinesConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dot.Mines signs each delivery: X-Mines-Signature: t=<unix>,v1=hmac_sha256(t.body, secret).
 * Deliveries are stored before they are applied, deduped on X-Mines-Delivery,
 * and never make Dot.Mines retry a poison payload.
 */
class MinesWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'whsec_testsecret';

    private function connection(array $attributes = []): MinesConnection
    {
        return MinesConnection::factory()->for(ControlRoom::factory())->create(array_merge(['webhook_secret' => self::SECRET, 'status' => 'verified'], $attributes));
    }

    private function deliver(MinesConnection $connection, string $event, array $data, array $overrides = [])
    {
        $body = json_encode(['event' => $event, 'occurred_at' => now()->toIso8601String(), 'data' => $data], JSON_UNESCAPED_SLASHES);
        $t = $overrides['t'] ?? time();
        $signature = $overrides['signature'] ?? ('t='.$t.',v1='.hash_hmac('sha256', $t.'.'.$body, $overrides['secret'] ?? self::SECRET));

        return $this->call('POST', route('webhooks.mines', $connection), [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_MINES_EVENT' => $event,
            'HTTP_X_MINES_DELIVERY' => $overrides['delivery'] ?? 'd-1',
            'HTTP_X_MINES_SIGNATURE' => $signature,
        ], $body);
    }

    private function alertData(int $id = 99, int $machineId = 7): array
    {
        return ['id' => $id, 'type' => 'fuel', 'title' => 'Low fuel', 'description' => 'Fuel at 8%', 'priority' => 'critical', 'status' => 'active', 'machine_id' => $machineId, 'mine_area_id' => null, 'triggered_at' => '2026-10-15T09:00:00+00:00', 'acknowledged_at' => null, 'resolved_at' => null, 'acknowledged_by' => null, 'resolved_by' => null, 'metadata' => null, 'created_at' => '2026-10-15T09:00:00+00:00', 'updated_at' => '2026-10-15T09:00:00+00:00'];
    }

    public function test_a_valid_alert_delivery_creates_the_alert_and_records_the_delivery(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 7]);

        $this->deliver($connection, 'alert.triggered', $this->alertData())->assertOk()->assertJson(['status' => 'applied']);

        $alert = Alert::where('source', 'dot_mines')->where('external_id', '99')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame($equipment->id, $alert->equipment_id);
        $delivery = IntegrationWebhookDelivery::firstOrFail();
        $this->assertSame('applied', $delivery->status);
        $this->assertSame('d-1', $delivery->delivery_id);
    }

    public function test_tampered_bodies_and_skewed_timestamps_are_rejected(): void
    {
        $connection = $this->connection();

        $this->deliver($connection, 'alert.triggered', $this->alertData(), ['secret' => 'wrong'])->assertStatus(400);
        $this->deliver($connection, 'alert.triggered', $this->alertData(), ['t' => time() - 3600])->assertStatus(400);
        $this->deliver($connection, 'alert.triggered', $this->alertData(), ['signature' => 'garbage'])->assertStatus(400);

        $this->assertSame(0, Alert::count());
        $this->assertSame(0, IntegrationWebhookDelivery::count());
    }

    public function test_replays_are_ignored_and_unknown_or_disabled_connections_are_refused(): void
    {
        $connection = $this->connection();
        Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 7]);

        $this->deliver($connection, 'alert.triggered', $this->alertData())->assertOk();
        $this->deliver($connection, 'alert.triggered', $this->alertData())->assertOk()->assertJson(['status' => 'ignored']);
        $this->assertSame(1, Alert::count());

        $this->call('POST', '/webhooks/mines/999999', [], [], [], ['HTTP_X_MINES_SIGNATURE' => 't=1,v1=x'], '{}')->assertNotFound();

        $disabled = $this->connection(['status' => 'disabled']);
        $this->deliver($disabled, 'alert.triggered', $this->alertData(), ['delivery' => 'd-2'])->assertStatus(202)->assertJson(['status' => 'ignored']);

        $noSecret = $this->connection(['webhook_secret' => null]);
        $this->deliver($noSecret, 'alert.triggered', $this->alertData(), ['delivery' => 'd-3'])->assertStatus(503);
    }

    public function test_machine_offline_updates_the_mirror_and_unknown_events_are_ignored(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 7, 'mines_status' => 'active']);

        $machine = ['id' => 7, 'name' => 'HT-07', 'machine_type' => 'haul_truck', 'status' => 'offline', 'location' => ['latitude' => null, 'longitude' => null, 'updated_at' => null]];
        $this->deliver($connection, 'machine.offline', ['machine' => $machine, 'reason' => 'Connection lost', 'last_seen_at' => '2026-10-15T09:30:00+00:00'])->assertOk();
        $this->assertSame('offline', $equipment->refresh()->mines_status);
        $this->assertSame('2026-10-15 09:30:00', $equipment->last_seen_at->format('Y-m-d H:i:s'));

        $this->deliver($connection, 'something.new', ['x' => 1], ['delivery' => 'd-9'])->assertOk()->assertJson(['status' => 'ignored']);
    }

    public function test_an_applier_failure_is_recorded_without_asking_dot_mines_to_retry(): void
    {
        $connection = $this->connection();

        // An alert payload without an id cannot be applied.
        $this->deliver($connection, 'alert.triggered', ['title' => 'no id'])->assertOk()->assertJson(['status' => 'failed']);

        $delivery = IntegrationWebhookDelivery::firstOrFail();
        $this->assertSame('failed', $delivery->status);
        $this->assertNotNull($delivery->error);
    }

    public function test_an_alert_for_a_machine_not_yet_mirrored_fetches_it_once_and_links_the_alert(): void
    {
        $connection = $this->connection(['base_url' => 'http://mines.test', 'api_token' => 'tok']);
        Http::fake([
            'mines.test/api/v1/machines/7' => Http::response(['data' => ['id' => 7, 'name' => 'HT-07', 'machine_type' => 'bell', 'model' => 'B50E', 'manufacturer' => 'Bell', 'status' => 'active', 'capacity' => 50, 'mine_area_id' => null, 'location' => ['latitude' => null, 'longitude' => null, 'updated_at' => null], 'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-10-15T09:58:00+00:00']]),
            'mines.test/api/v1/machines/8' => Http::response(['message' => 'down'], 503),
        ]);

        $this->deliver($connection, 'alert.triggered', $this->alertData(99, 7))->assertOk()->assertJson(['status' => 'applied']);
        $equipment = Equipment::withoutGlobalScopes()->where('mines_machine_id', 7)->firstOrFail();
        $this->assertSame('HT-07', $equipment->name);
        $this->assertSame($equipment->id, Alert::where('external_id', '99')->firstOrFail()->equipment_id);

        $this->deliver($connection, 'alert.triggered', $this->alertData(100, 8), ['delivery' => 'd-2'])->assertOk()->assertJson(['status' => 'applied']);
        $this->assertNull(Alert::where('external_id', '100')->firstOrFail()->equipment_id, 'a failed fetch leaves the alert unlinked until the next sync');
        $this->assertSame(1, Equipment::withoutGlobalScopes()->count());
    }

    public function test_operator_events_update_who_dot_mines_has_on_the_machine(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 7]);
        $assigned = ['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'shift' => 'day', 'assigned_at' => '2026-10-15T06:00:00+00:00', 'unassigned_at' => null, 'reason' => null, 'was_override' => false, 'override_reason' => null];

        $this->deliver($connection, 'operator.assigned', $assigned)->assertOk()->assertJson(['status' => 'applied']);
        $this->assertSame(3, $equipment->fresh()->mines_operator_id);

        $this->deliver($connection, 'operator.unassigned', ['unassigned_at' => '2026-10-15T18:00:00+00:00', 'reason' => 'End of shift'] + $assigned, ['delivery' => 'd-2'])->assertOk()->assertJson(['status' => 'applied']);
        $this->assertNull($equipment->fresh()->mines_operator_id);

        $this->deliver($connection, 'operator.assigned', ['machine_id' => 999] + $assigned, ['delivery' => 'd-3'])->assertOk()->assertJson(['status' => 'ignored']);
    }
}
