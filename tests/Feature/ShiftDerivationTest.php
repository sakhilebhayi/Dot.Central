<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
use App\Models\MinesConnection;
use App\Models\ProductionRecord;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Services\Shifts\ShiftDerivation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Everything the prototype's operator typed is derived from the mirror
 * (docs/dot-central/12-derived-operations-design.md §3).
 */
class ShiftDerivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_unit_summary_is_differenced_from_metrics_within_the_window(): void
    {
        Carbon::setTestNow('2026-10-15 19:00:00');
        $room = ControlRoom::factory()->create();
        MinesConnection::factory()->for($room)->verified()->create(['timezone' => 'UTC', 'provider_cadence_seconds' => 900]);
        $type = ShiftType::factory()->for($room)->create(); // 06:00–18:00
        $shift = Shift::factory()->for($room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $truck = Equipment::factory()->for($room)->create(['name' => 'HT-01', 'machine_type' => 'haul_truck', 'mines_status' => 'active', 'last_heard_at' => '2026-10-15 17:50:00']);

        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 05:30:00', 'operating_hours' => 1000.0, 'idle_hours' => 100.0, 'load_count' => 500, 'engine_running' => false]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 09:00:00', 'operating_hours' => 1003.0, 'idle_hours' => 100.5, 'load_count' => 512, 'engine_running' => true]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 17:50:00', 'operating_hours' => 1009.5, 'idle_hours' => 101.5, 'load_count' => 541, 'engine_running' => true]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 18:30:00', 'operating_hours' => 1010.0, 'idle_hours' => 101.6, 'load_count' => 543, 'engine_running' => true]);
        ProductionRecord::factory()->for($room)->create(['equipment_id' => $truck->id, 'record_date' => '2026-10-15', 'shift' => 'day', 'quantity' => 437.0, 'unit' => 'tonnes']);
        ProductionRecord::factory()->for($room)->create(['equipment_id' => $truck->id, 'record_date' => '2026-10-15', 'shift' => 'night', 'quantity' => 99.0, 'unit' => 'tonnes']);
        // offline from 10:00 to 11:30 observed by sync
        EquipmentStatusEvent::create(['equipment_id' => $truck->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'offline', 'observed_at' => '2026-10-15 10:00:00', 'source' => 'sync']);
        EquipmentStatusEvent::create(['equipment_id' => $truck->id, 'field' => 'mines_status', 'from_value' => 'offline', 'to_value' => 'active', 'observed_at' => '2026-10-15 11:30:00', 'source' => 'sync']);

        $summary = app(ShiftDerivation::class)->unitSummaries($shift)->firstWhere('equipment_id', $truck->id);

        $this->assertSame(1000.0, $summary['opening_hours']);
        $this->assertSame('2026-10-15 05:30:00', $summary['opening_at']->format('Y-m-d H:i:s'));
        $this->assertSame(1009.5, $summary['closing_hours'], 'the last reading inside the window, not the one after it');
        $this->assertSame(9.5, $summary['run_hours']);
        $this->assertSame(1.5, $summary['idle_hours']);
        $this->assertSame(41, $summary['loads']);
        $this->assertSame(437.0, $summary['production_quantity'], 'only the record whose shift matches');
        $this->assertSame('tonnes', $summary['production_unit']);
        $this->assertSame(90, $summary['stoppage_minutes']);
        $this->assertSame('operating', $summary['state']);
    }

    public function test_missing_readings_stay_null_and_stoppages_clip_to_the_window(): void
    {
        Carbon::setTestNow('2026-10-15 12:00:00');
        $room = ControlRoom::factory()->create();
        MinesConnection::factory()->for($room)->verified()->create();
        $type = ShiftType::factory()->for($room)->create();
        $shift = Shift::factory()->for($room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $dozer = Equipment::factory()->for($room)->create(['name' => 'DZ-01', 'machine_type' => 'dozer', 'mines_status' => 'offline', 'last_heard_at' => null]);
        EquipmentStatusEvent::create(['equipment_id' => $dozer->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'offline', 'observed_at' => '2026-10-15 04:00:00', 'source' => 'sync']);

        $summary = app(ShiftDerivation::class)->unitSummaries($shift)->firstWhere('equipment_id', $dozer->id);

        $this->assertNull($summary['opening_hours']);
        $this->assertNull($summary['run_hours']);
        $this->assertNull($summary['loads']);
        $this->assertSame(360, $summary['stoppage_minutes'], 'offline since before the shift, clipped at shift start and at now');
        $this->assertSame('unknown', $summary['state']);
    }
}
