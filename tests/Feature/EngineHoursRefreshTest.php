<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Engine hours live in Dot.Mines metrics (operating_hours), not on the
 * machine resource. They are refreshed in small batches inside the API
 * rate budget and never coerced to zero.
 */
class EngineHoursRefreshTest extends TestCase
{
    use RefreshDatabase;

    private function connection(): MinesConnection
    {
        return MinesConnection::factory()->for(ControlRoom::factory())->create(['base_url' => 'http://mines.test', 'api_token' => 't', 'status' => 'verified']);
    }

    private function metric(float $hours, string $recordedAt, ?float $fuel = 40.0): array
    {
        return ['id' => 1, 'operating_hours' => $hours, 'idle_hours' => 3.0, 'fuel_level' => $fuel, 'speed' => null, 'recorded_at' => $recordedAt, 'created_at' => '2026-10-15T10:01:00+00:00'];
    }

    public function test_only_the_batch_is_requested_oldest_first_and_hours_are_stored_with_their_clock(): void
    {
        config(['mines.hours_batch' => 2]);
        $connection = $this->connection();
        $room = $connection->controlRoom;
        $never = Equipment::factory()->for($room)->create(['mines_machine_id' => 1, 'engine_hours_recorded_at' => null]);
        $old = Equipment::factory()->for($room)->create(['mines_machine_id' => 2, 'engine_hours_recorded_at' => now()->subHours(5)]);
        Equipment::factory()->for($room)->create(['mines_machine_id' => 3, 'engine_hours_recorded_at' => now()->subMinutes(5)]);

        Http::fake([
            'mines.test/api/v1/machines/1/metrics*' => Http::response(['data' => [$this->metric(1200.5, '2026-10-15T10:00:00+00:00')]]),
            'mines.test/api/v1/machines/2/metrics*' => Http::response(['data' => [$this->metric(88.0, '2026-10-15T09:30:00+00:00')]]),
        ]);

        $updated = app(MinesSynchroniser::class)->refreshEngineHours($connection);

        $this->assertSame(2, $updated);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/machines/3/metrics'));

        $never->refresh();
        $this->assertSame(1200.5, $never->engine_hours);
        $this->assertSame('2026-10-15 10:00:00', $never->engine_hours_recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame(40.0, $never->fuel_level);
        $this->assertSame('2026-10-15 10:00:00', $never->last_heard_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $never->metrics()->count());
        $this->assertSame(88.0, $old->refresh()->engine_hours);
    }

    public function test_a_null_operating_hours_leaves_the_fields_untouched(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 1, 'engine_hours' => 500.0, 'engine_hours_recorded_at' => now()->subDay()]);
        Http::fake(['mines.test/api/v1/machines/1/metrics*' => Http::response(['data' => [['id' => 9, 'operating_hours' => null, 'fuel_level' => null, 'recorded_at' => '2026-10-15T10:00:00+00:00']]])]);

        $updated = app(MinesSynchroniser::class)->refreshEngineHours($connection);

        $this->assertSame(0, $updated);
        $this->assertSame(500.0, $equipment->refresh()->engine_hours);
        $this->assertSame(1, EquipmentMetric::count(), 'the row is kept for history, with null hours');
        $this->assertNull(EquipmentMetric::first()->operating_hours);
    }

    public function test_a_repeated_reading_is_not_inserted_twice(): void
    {
        $connection = $this->connection();
        $equipment = Equipment::factory()->for($connection->controlRoom)->create(['mines_machine_id' => 1]);
        Http::fake(['mines.test/api/v1/machines/1/metrics*' => Http::response(['data' => [$this->metric(10.0, '2026-10-15T10:00:00+00:00')]])]);

        app(MinesSynchroniser::class)->refreshEngineHours($connection);
        $equipment->refresh()->update(['engine_hours_recorded_at' => null]);
        app(MinesSynchroniser::class)->refreshEngineHours($connection);

        $this->assertSame(1, EquipmentMetric::count());
    }

    public function test_a_rate_limit_stops_the_batch_but_keeps_what_was_saved(): void
    {
        config(['mines.hours_batch' => 3]);
        $connection = $this->connection();
        $room = $connection->controlRoom;
        Equipment::factory()->for($room)->create(['mines_machine_id' => 1]);
        Equipment::factory()->for($room)->create(['mines_machine_id' => 2]);
        Equipment::factory()->for($room)->create(['mines_machine_id' => 3]);
        Http::fake([
            'mines.test/api/v1/machines/1/metrics*' => Http::response(['data' => [$this->metric(1.0, '2026-10-15T10:00:00+00:00')]]),
            'mines.test/api/v1/machines/2/metrics*' => Http::response(['message' => 'Too many requests', 'retry_after' => 120], 429),
            'mines.test/api/v1/machines/3/metrics*' => Http::response(['data' => [$this->metric(3.0, '2026-10-15T10:00:00+00:00')]]),
        ]);

        $updated = app(MinesSynchroniser::class)->refreshEngineHours($connection);

        $this->assertSame(1, $updated);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/machines/3/metrics'));
    }

    public function test_the_schedule_registers_the_refresh(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('mines:refresh-hours');
    }
}
