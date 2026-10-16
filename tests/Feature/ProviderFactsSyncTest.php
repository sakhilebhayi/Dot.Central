<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dot.Mines now states liveness, engine hours, the fuel unit and the
 * provider's alert time (I-03, I-04, I-06, I-07); Dot.Central takes them
 * when present and keeps deriving when they are absent.
 */
class ProviderFactsSyncTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]];
    }

    public function test_sync_takes_dot_mines_liveness_hours_unit_cadence_and_alert_time(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $room = ControlRoom::factory()->create();
        $connection = MinesConnection::factory()->for($room)->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'status' => 'verified', 'sync_cursor' => 0, 'provider_cadence_seconds' => 300]);

        Http::fake([
            'mines.test/api/v1/sync?*' => Http::response(['version' => 5, 'server_time' => now()->toIso8601String(), 'changes' => [
                'fleet' => [
                    ['id' => 7, 'name' => 'HT-07', 'machine_type' => 'bell', 'equipment_type' => 'adt', 'status' => 'active', 'allocation_state' => null, 'latitude' => -26.1, 'longitude' => 28.2, 'fuel_level' => 61.0, 'fuel_level_unit' => 'percent', 'engine_hours' => null, 'operating_hours' => 1234.5, 'operating_hours_recorded_at' => '2026-10-15T11:50:00+00:00', 'payload' => null, 'last_seen_at' => '2026-10-15T11:58:00+00:00', 'last_heard_at' => '2026-10-15T11:55:00+00:00', 'provider_cadence_seconds' => 900, 'stale' => false, 'sync_version' => 4],
                    ['id' => 8, 'name' => 'HT-08', 'machine_type' => 'cat', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => '2026-10-15T11:58:00+00:00', 'sync_version' => 5],
                ],
                'production' => [], 'reference' => [], 'operators' => [], 'operator_assignments' => [],
            ], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response($this->empty()),
            'mines.test/api/v1/alerts?*' => Http::response(['data' => [
                ['id' => 99, 'type' => 'engine', 'title' => 'Caution code E204', 'description' => null, 'priority' => 'high', 'status' => 'active', 'machine_id' => 7, 'mine_area_id' => null, 'triggered_at' => '2026-10-15T11:59:00+00:00', 'source_triggered_at' => '2026-10-15T09:30:00+00:00', 'acknowledged_at' => null, 'resolved_at' => null, 'metadata' => []],
            ], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 100, 'from' => 1, 'to' => 1]]),
            'mines.test/api/v1/geofences?*' => Http::response($this->empty()),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'manual');
        $this->assertSame('succeeded', $run->status);

        $truck = Equipment::withoutGlobalScopes()->where('mines_machine_id', 7)->firstOrFail();
        $this->assertSame('2026-10-15 11:55:00', $truck->last_heard_at->toDateTimeString(), 'Dot.Mines\' own last-heard clock wins over the local recompute');
        $this->assertSame('percent', $truck->fuel_level_unit);
        $this->assertSame(1234.5, $truck->engine_hours);
        $this->assertSame('2026-10-15 11:50:00', $truck->engine_hours_recorded_at->toDateTimeString());
        $this->assertSame(1, EquipmentMetric::where('equipment_id', $truck->id)->count(), 'the hour-meter reading lands as a metric row, no per-machine metrics call needed');
        $this->assertSame(900, $connection->fresh()->provider_cadence_seconds, 'the connection learns the provider\'s declared cadence');

        $other = Equipment::withoutGlobalScopes()->where('mines_machine_id', 8)->firstOrFail();
        $this->assertNull($other->last_heard_at, 'nothing is invented for a machine Dot.Mines says nothing about');

        $alert = Alert::withoutGlobalScopes()->where('external_id', '99')->firstOrFail();
        $this->assertSame('2026-10-15 09:30:00', $alert->source_triggered_at->toDateTimeString());
        $this->assertSame('2026-10-15 11:59:00', $alert->triggered_at->toDateTimeString());
    }
}
