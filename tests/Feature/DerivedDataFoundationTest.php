<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\ControlRoomSetting;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
use App\Models\MinesConnection;
use App\Models\ProductionRecord;
use App\Models\User;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * docs/dot-central/12-derived-operations-design.md §2: everything an
 * operator used to type is now derived from what Dot.Mines reports.
 */
class DerivedDataFoundationTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): MinesConnection
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();

        return MinesConnection::factory()->for($room)->create(['base_url' => 'http://mines.test', 'api_token' => 't', 'status' => 'verified', 'sync_cursor' => 0]);
    }

    private function metric(float $hours, string $recordedAt, ?int $loads = null, bool $engine = true, ?float $idle = null): array
    {
        return ['id' => 1, 'operating_hours' => $hours, 'idle_hours' => $idle, 'fuel_level' => 40.0, 'speed' => null,
            'raw_data' => ['engine_running' => $engine, 'load_count' => $loads, 'cumulative_payload' => 1000, 'odometer' => 5.5],
            'recorded_at' => $recordedAt, 'created_at' => '2026-10-15T10:01:00+00:00'];
    }

    public function test_schema_exists_and_hour_readings_are_gone(): void
    {
        foreach (['equipment_metrics', 'equipment_status_events', 'production_records', 'control_room_settings', 'shift_types', 'downtime_reasons'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
        $this->assertFalse(Schema::hasTable('equipment_hour_readings'));
    }

    public function test_a_new_room_gets_default_settings_shift_types_and_reasons(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $this->actingAs($owner)->post(route('control-rooms.store'), ['name' => 'Fresh Room']);
        $room = ControlRoom::where('name', 'Fresh Room')->firstOrFail();

        $this->assertSame(2, $room->shiftTypes()->count());
        $this->assertSame('06:00', $room->shiftTypes()->where('code', 'DAY')->first()->starts_at);
        $this->assertTrue($room->shiftTypes()->where('code', 'NIGHT')->first()->crosses_midnight);
        $this->assertGreaterThanOrEqual(8, $room->downtimeReasons()->count());
        $this->assertSame(240, $room->settings()->get('idle_minutes_threshold'));
        $this->assertSame(30, ControlRoomSetting::for($room)->get('offline_minutes'));
    }

    public function test_metrics_history_is_fetched_and_deduped(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 1]);
        Http::fake(['mines.test/api/v1/machines/1/metrics*' => Http::response(['data' => [
            $this->metric(1201.0, '2026-10-15T10:00:00+00:00', 120, true, 30.5),
            $this->metric(1200.0, '2026-10-15T09:00:00+00:00', 118, true, 30.0),
            $this->metric(1199.5, '2026-10-15T08:00:00+00:00', null, false, 29.5),
        ]])]);

        app(MinesSynchroniser::class)->refreshEngineHours($connection);
        app(MinesSynchroniser::class)->refreshEngineHours($connection);

        $this->assertSame(3, EquipmentMetric::where('equipment_id', $equipment->id)->count());
        Http::assertSent(fn ($r) => str_contains($r->url(), 'hours_back=24') && str_contains($r->url(), 'limit=200'));

        $equipment->refresh();
        $this->assertSame(1201.0, $equipment->engine_hours);
        $this->assertSame('2026-10-15 10:00:00', $equipment->engine_hours_recorded_at->format('Y-m-d H:i:s'));
        $newest = EquipmentMetric::where('equipment_id', $equipment->id)->orderByDesc('recorded_at')->first();
        $this->assertSame(120, $newest->load_count);
        $this->assertTrue($newest->engine_running);
        $this->assertSame(30.5, $newest->idle_hours);
        $engineEvents = EquipmentStatusEvent::where('equipment_id', $equipment->id)->where('field', 'engine_running')->orderBy('observed_at')->get();
        $this->assertCount(2, $engineEvents, 'first observation (off) and the off→on transition at 09:00');
        $this->assertSame(['off', 'on'], [$engineEvents[1]->from_value, $engineEvents[1]->to_value]);
        $this->assertSame('2026-10-15 09:00:00', $engineEvents[1]->observed_at->format('Y-m-d H:i:s'));
    }

    public function test_status_changes_seen_by_sync_become_events(): void
    {
        $connection = $this->connection();
        $room = $connection->controlRoom;
        $fleet = fn (string $status) => ['id' => 7, 'name' => 'HT-07', 'machine_type' => 'haul_truck', 'status' => $status, 'allocation_state' => 'occupying', 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => '2026-10-15T09:58:00+00:00', 'sync_version' => 1];
        $page = fn (array $rows, array $production = []) => ['version' => 1, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => $rows, 'production' => $production, 'reference' => []], 'deleted' => [], 'has_more' => false];

        Http::fake([
            'mines.test/api/v1/sync*' => Http::sequence()
                ->push($page([$fleet('active')], [['id' => 55, 'machine_id' => 7, 'mine_area_id' => 1, 'record_date' => '2026-10-15', 'shift' => 'day', 'quantity_produced' => '437.00', 'unit' => 'tonnes', 'status' => 'recorded', 'sync_version' => 1]]))
                ->push($page([$fleet('offline')], [['id' => 55, 'machine_id' => 7, 'mine_area_id' => 1, 'record_date' => '2026-10-15', 'shift' => 'day', 'quantity_produced' => '480.00', 'unit' => 'tonnes', 'status' => 'recorded', 'sync_version' => 2]])),
            'mines.test/api/v1/machines?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
            'mines.test/api/v1/alerts?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        app(MinesSynchroniser::class)->run($connection, 'manual');
        $connection->refresh()->forceFill(['sync_cursor' => 0])->save();
        app(MinesSynchroniser::class)->run($connection, 'manual');

        $equipment = Equipment::where('mines_machine_id', 7)->firstOrFail();
        $events = EquipmentStatusEvent::where('equipment_id', $equipment->id)->where('field', 'mines_status')->orderBy('id')->get();
        $this->assertCount(2, $events, 'first observation (null→active) and the change (active→offline)');
        $this->assertSame('active', $events[1]->from_value);
        $this->assertSame('offline', $events[1]->to_value);
        $this->assertSame('sync', $events[1]->source);

        $record = ProductionRecord::where('mines_record_id', 55)->firstOrFail();
        $this->assertSame($equipment->id, $record->equipment_id);
        $this->assertSame(480.0, $record->quantity);
        $this->assertSame('day', $record->shift);
        $this->assertSame(1, ProductionRecord::count(), 'upserted on mines_record_id, not duplicated');
    }
}
