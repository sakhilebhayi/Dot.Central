<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** I-29 schema (15-lineup-corrections-design.md §8): additive, SQLite and Postgres safe. */
class LineupCorrectionsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrections_table_and_face_split_column_exist(): void
    {
        foreach (['control_room_id', 'shift_id', 'lineup_allocation_id', 'partner_allocation_id', 'equipment_id', 'edge', 'old_at', 'new_at', 'reason', 'corrected_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('lineup_corrections', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('lineup_faces', 'split_from_face_id'));
    }

    public function test_a_correction_belongs_to_its_stint_machine_and_room_and_a_face_knows_what_it_split_from(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        app(RoomProvisioner::class)->provision($room);
        $type = ShiftType::where('control_room_id', $room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00']);
        $unit = Equipment::factory()->for($room)->create(['name' => 'HT-01']);
        $old = LineupFace::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'seq' => 1, 'kind' => 'LOADING', 'material' => 'Overburden', 'status' => 'CLOSED', 'active_from' => '2026-10-15 06:00:00', 'closed_at' => '2026-10-15 12:00:00']);
        $new = LineupFace::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'seq' => 2, 'kind' => 'LOADING', 'material' => 'Interburden', 'status' => 'ACTIVE', 'active_from' => '2026-10-15 12:00:00', 'split_from_face_id' => $old->id]);
        $stint = LineupAllocation::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'lineup_face_id' => $new->id, 'equipment_id' => $unit->id, 'role' => 'HAUL', 'from_at' => '2026-10-15 12:00:00']);

        $correction = LineupCorrection::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'lineup_allocation_id' => $stint->id, 'equipment_id' => $unit->id, 'edge' => LineupCorrection::ARRIVAL, 'old_at' => '2026-10-15 12:30:00', 'new_at' => '2026-10-15 12:00:00', 'reason' => 'Clicked late', 'corrected_by' => $owner->id]);

        $this->assertTrue($correction->allocation->is($stint));
        $this->assertSame('HT-01', $correction->equipment->name);
        $this->assertTrue($correction->controlRoom->is($room));
        $this->assertSame(30, (int) $correction->old_at->diffInMinutes($correction->new_at, true));
        $this->assertTrue($new->splitFrom->is($old));
        $this->assertSame([$correction->id], $stint->corrections()->pluck('id')->all());
    }
}
