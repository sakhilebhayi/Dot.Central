<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\EquipmentHourReading;
use App\Models\IntegrationSyncRun;
use App\Models\MinesConnection;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class EquipmentMirrorSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_equipment_mirror_holds_provenance_and_is_unique_per_room_and_machine(): void
    {
        foreach (['mines_machine_id', 'mines_status', 'location_recorded_at', 'engine_hours', 'engine_hours_recorded_at', 'last_seen_at', 'last_heard_at', 'sync_version', 'retired_at', 'raw'] as $column) {
            $this->assertTrue(Schema::hasColumn('equipment', $column), $column);
        }

        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        Equipment::factory()->for($room)->create(['mines_machine_id' => 7]);

        $this->expectException(QueryException::class);
        Equipment::factory()->for($room)->create(['mines_machine_id' => 7]);
    }

    public function test_hour_readings_are_unique_per_equipment_and_time(): void
    {
        $reading = EquipmentHourReading::factory()->create(['recorded_at' => '2026-10-15 10:00:00']);
        $this->assertSame($reading->equipment_id, $reading->equipment->id);

        $this->expectException(QueryException::class);
        EquipmentHourReading::factory()->for($reading->equipment)->create(['recorded_at' => '2026-10-15 10:00:00']);
    }

    public function test_sync_runs_belong_to_a_connection(): void
    {
        $run = IntegrationSyncRun::factory()->create();
        $this->assertInstanceOf(MinesConnection::class, $run->connection);
        $this->assertSame('running', $run->status);
    }

    public function test_alerts_carry_source_and_dedupe_on_external_id(): void
    {
        foreach (['source', 'external_id', 'priority', 'equipment_id', 'metadata', 'source_triggered_at'] as $column) {
            $this->assertTrue(Schema::hasColumn('alerts', $column), $column);
        }

        $room = ControlRoom::factory()->create();
        Alert::factory()->for($room)->create(['source' => 'dot_mines', 'external_id' => '99']);
        $this->assertSame('manual', Alert::factory()->for($room)->create()->source);

        $this->expectException(QueryException::class);
        Alert::factory()->for($room)->create(['source' => 'dot_mines', 'external_id' => '99']);
    }

    public function test_room_relations(): void
    {
        $room = ControlRoom::factory()->create();
        Equipment::factory()->for($room)->count(2)->create();
        $this->assertCount(2, $room->equipment);
    }
}
