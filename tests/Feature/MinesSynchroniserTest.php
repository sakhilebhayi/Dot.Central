<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\IntegrationSyncRun;
use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The synchroniser against faked Dot.Mines responses shaped like the real
 * API. Every failure mode leaves the cursor untouched and the run recorded.
 */
class MinesSynchroniserTest extends TestCase
{
    use RefreshDatabase;

    private function connection(array $attributes = []): MinesConnection
    {
        $room = ControlRoom::factory()->create();

        return MinesConnection::factory()->for($room)->create(array_merge([
            'base_url' => 'http://mines.test',
            'api_token' => 'test-token',
            'status' => 'unverified',
            'sync_cursor' => null,
        ], $attributes));
    }

    /** @return array<string, mixed> */
    private function fleetRow(int $id, string $name, string $status = 'active', int $version = 10): array
    {
        return ['id' => $id, 'name' => $name, 'machine_type' => 'haul_truck', 'status' => $status, 'allocation_state' => null,
            'latitude' => -26.1, 'longitude' => 28.2, 'fuel_level' => 55.0, 'engine_hours' => null, 'payload' => null,
            'last_seen_at' => '2026-10-15T09:58:00+00:00', 'sync_version' => $version];
    }

    /** @return array<string, mixed> */
    private function machineResource(int $id, string $name, string $status = 'active'): array
    {
        return ['id' => $id, 'name' => $name, 'machine_type' => 'haul_truck', 'model' => 'B50E', 'manufacturer' => 'Bell',
            'registration_number' => null, 'serial_number' => 'SN'.$id, 'year_of_manufacture' => 2021, 'status' => $status,
            'capacity' => 50, 'fuel_capacity' => 600, 'hours_meter' => null, 'operating_hours' => null, 'odometer' => null,
            'total_distance_km' => null, 'mine_area_id' => 3, 'notes' => null,
            'location' => ['latitude' => -26.1, 'longitude' => 28.2, 'updated_at' => '2026-10-15T09:57:30+00:00'],
            'created_at' => '2026-01-01T00:00:00+00:00', 'updated_at' => '2026-10-15T09:58:00+00:00',
            'mine_area' => ['id' => 3, 'name' => 'North Pit', 'status' => 'active']];
    }

    private function paginated(array $rows): array
    {
        return ['data' => $rows, 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($rows), 'per_page' => 100, 'from' => 1, 'to' => count($rows)]];
    }

    public function test_a_successful_run_mirrors_machines_alerts_and_advances_the_cursor(): void
    {
        $connection = $this->connection();

        Http::fake([
            'mines.test/api/v1/user' => Http::response(['id' => 2, 'name' => 'Demo', 'email' => 'demo@example.test', 'current_team_id' => 2]),
            'mines.test/api/v1/sync?since=0*' => Http::response(['version' => 5, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [$this->fleetRow(7, 'HT-07', 'active', 4), $this->fleetRow(8, 'HT-08', 'idle', 5)], 'production' => [], 'reference' => [['id' => 3, 'name' => 'North Pit', 'status' => 'active', 'sync_version' => 2]]], 'deleted' => [], 'has_more' => true]),
            'mines.test/api/v1/sync?since=5*' => Http::response(['version' => 9, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [$this->fleetRow(9, 'EX-09', 'maintenance', 9)], 'production' => [], 'reference' => []], 'deleted' => [['entity_type' => 'machines', 'entity_id' => 8, 'sync_version' => 9]], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response($this->paginated([$this->machineResource(7, 'HT-07'), array_diff_key($this->machineResource(9, 'EX-09', 'maintenance'), ['mine_area' => 1])])),
            'mines.test/api/v1/alerts?*' => Http::response($this->paginated([
                ['id' => 99, 'type' => 'fuel', 'title' => 'Low fuel', 'description' => 'Fuel at 8%', 'priority' => 'high', 'status' => 'active', 'machine_id' => 7, 'mine_area_id' => 3, 'triggered_at' => '2026-10-15T09:00:00+00:00', 'acknowledged_at' => null, 'resolved_at' => null, 'acknowledged_by' => null, 'resolved_by' => null, 'metadata' => ['external_id' => 'bell:123'], 'created_at' => '2026-10-15T09:00:00+00:00', 'updated_at' => '2026-10-15T09:00:00+00:00'],
            ])),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'manual');

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(0, $run->cursor_before);
        $this->assertSame(9, $run->cursor_after);
        $this->assertSame(3, $run->fetched_machines);
        $this->assertSame(1, $run->retired);
        $this->assertSame(1, $run->alerts_upserted);

        $connection->refresh();
        $this->assertSame('verified', $connection->status);
        $this->assertSame(9, $connection->sync_cursor);
        $this->assertNotNull($connection->last_success_at);
        $this->assertSame(2, $connection->mines_team_id);

        $ht07 = Equipment::where('mines_machine_id', 7)->firstOrFail();
        $this->assertSame('HT-07', $ht07->name);
        $this->assertSame('active', $ht07->mines_status);
        $this->assertSame('B50E', $ht07->model);
        $this->assertSame('North Pit', $ht07->mine_area_name);
        $this->assertSame('North Pit', Equipment::where('mines_machine_id', 9)->firstOrFail()->mine_area_name, 'area learned from the reference scope names machines whose resource lacks mine_area');
        $this->assertSame('2026-10-15 09:57:30', $ht07->location_recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 09:57:30', $ht07->last_heard_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 09:58:00', $ht07->last_seen_at->format('Y-m-d H:i:s'));
        $this->assertNull($ht07->engine_hours, 'engine_hours in /sync reads a dead column and must not be stored');
        $this->assertSame(4, $ht07->sync_version);

        $this->assertNotNull(Equipment::where('mines_machine_id', 8)->firstOrFail()->retired_at);
        $this->assertSame('maintenance', Equipment::where('mines_machine_id', 9)->firstOrFail()->mines_status);

        $alert = Alert::where('source', 'dot_mines')->where('external_id', '99')->firstOrFail();
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('high', $alert->priority);
        $this->assertSame($ht07->id, $alert->equipment_id);
        $this->assertSame('2026-10-15 09:00:00', $alert->source_triggered_at->format('Y-m-d H:i:s'));
        $this->assertNull($alert->cleared_at);

        $audit = AuditEntry::where('action', 'import')->where('entity_type', IntegrationSyncRun::class)->firstOrFail();
        $this->assertTrue($audit->automatic);
        $this->assertSame($connection->control_room_id, $audit->control_room_id);
    }

    public function test_a_second_run_does_not_duplicate_alerts_and_clears_resolved_ones(): void
    {
        $connection = $this->connection(['status' => 'verified', 'sync_cursor' => 9]);
        $room = $connection->controlRoom;
        $equipment = Equipment::factory()->for($room)->create(['mines_machine_id' => 7]);
        Alert::factory()->for($room)->create(['source' => 'dot_mines', 'external_id' => '99', 'severity' => 'warning', 'equipment_id' => $equipment->id]);

        Http::fake([
            'mines.test/api/v1/sync?since=9*' => Http::response(['version' => 9, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [], 'production' => [], 'reference' => []], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/alerts?*' => Http::response($this->paginated([
                ['id' => 99, 'type' => 'fuel', 'title' => 'Low fuel', 'description' => null, 'priority' => 'critical', 'status' => 'resolved', 'machine_id' => 7, 'mine_area_id' => null, 'triggered_at' => '2026-10-15T09:00:00+00:00', 'acknowledged_at' => null, 'resolved_at' => '2026-10-15T10:00:00+00:00', 'acknowledged_by' => null, 'resolved_by' => 2, 'metadata' => null, 'created_at' => '2026-10-15T09:00:00+00:00', 'updated_at' => '2026-10-15T10:00:00+00:00'],
            ])),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'schedule');

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, Alert::where('source', 'dot_mines')->count());
        $alert = Alert::where('external_id', '99')->firstOrFail();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('2026-10-15 10:00:00', $alert->cleared_at->format('Y-m-d H:i:s'));
        Http::assertNotSent(fn ($r) => str_contains($r->url(), '/api/v1/user'));
    }

    public function test_a_rejected_credential_marks_the_connection_and_raises_one_system_alert(): void
    {
        $connection = $this->connection(['status' => 'verified', 'sync_cursor' => 3]);
        Http::fake(['mines.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $first = app(MinesSynchroniser::class)->run($connection, 'schedule');
        $second = app(MinesSynchroniser::class)->run($connection->fresh(), 'schedule');

        $this->assertSame('failed', $first->status);
        $this->assertSame('CredentialRejected', class_basename($first->error_class));
        $this->assertSame('skipped', $second->status, 'an unusable connection is not retried');

        $connection->refresh();
        $this->assertSame('credential_invalid', $connection->status);
        $this->assertSame(3, $connection->sync_cursor);
        $this->assertSame(1, $connection->consecutive_failures);
        $this->assertSame(1, Alert::where('source', 'system')->where('control_room_id', $connection->control_room_id)->count());
        $this->assertStringNotContainsString('test-token', (string) $connection->last_error);
    }

    public function test_a_rate_limit_is_waited_out_once(): void
    {
        $connection = $this->connection(['status' => 'verified', 'sync_cursor' => 0]);
        Http::fake([
            'mines.test/api/v1/sync*' => Http::sequence()
                ->push(['message' => 'Too many requests', 'retry_after' => 1], 429)
                ->push(['version' => 1, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [], 'production' => [], 'reference' => []], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response($this->paginated([])),
            'mines.test/api/v1/alerts?*' => Http::response($this->paginated([])),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'schedule');

        $this->assertSame('succeeded', $run->status);
        $this->assertSame(1, $run->cursor_after);
    }

    public function test_an_outage_fails_the_run_and_keeps_the_cursor(): void
    {
        $connection = $this->connection(['status' => 'verified', 'sync_cursor' => 12]);
        Http::fake(['mines.test/*' => Http::response('oops', 503)]);

        $run = app(MinesSynchroniser::class)->run($connection, 'schedule');

        $this->assertSame('failed', $run->status);
        $this->assertSame(12, $connection->fresh()->sync_cursor);
        $this->assertSame('verified', $connection->fresh()->status);
        $this->assertSame(1, $connection->fresh()->consecutive_failures);
        $this->assertNotNull($connection->fresh()->last_failure_at);
    }

    public function test_alert_failure_after_a_good_fleet_pull_is_partial_and_keeps_the_new_cursor(): void
    {
        $connection = $this->connection(['status' => 'verified', 'sync_cursor' => 0]);
        Http::fake([
            'mines.test/api/v1/sync*' => Http::response(['version' => 4, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [$this->fleetRow(7, 'HT-07')], 'production' => [], 'reference' => []], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response($this->paginated([$this->machineResource(7, 'HT-07')])),
            'mines.test/api/v1/alerts?*' => Http::response('<html>', 200, ['Content-Type' => 'text/html']),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'schedule');

        $this->assertSame('partial', $run->status);
        $this->assertSame(4, $connection->fresh()->sync_cursor);
        $this->assertSame(1, Equipment::count());
    }

    public function test_disabled_connections_are_skipped(): void
    {
        $connection = $this->connection(['status' => 'disabled']);
        Http::fake();

        $run = app(MinesSynchroniser::class)->run($connection, 'schedule');

        $this->assertSame('skipped', $run->status);
        Http::assertNothingSent();
    }
}
