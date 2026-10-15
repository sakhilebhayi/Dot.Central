<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\MinesConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EquipmentPagesTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role, ?ControlRoom $room = null): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $room?->users()->attach($user);

        return $user;
    }

    public function test_the_ledger_shows_states_and_dashes_for_missing_values(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($room)->verified()->create(['provider_cadence_seconds' => 900]);
        Equipment::factory()->for($room)->heard(60)->create(['name' => 'HT-07', 'mines_status' => 'active', 'engine_hours' => 1234.5, 'engine_hours_recorded_at' => now()->subMinutes(2)]);
        Equipment::factory()->for($room)->heard(2000)->create(['name' => 'HT-08', 'mines_status' => 'idle', 'engine_hours' => null]);
        Equipment::factory()->for($room)->create(['name' => 'EX-09', 'mines_status' => 'active', 'last_heard_at' => null]);
        $operator = $this->member($owner->currentTeam, 'operator', $room);

        $response = $this->actingAs($operator)->get(route('control-rooms.equipment.index', $room))->assertOk();

        $response->assertSee('HT-07')->assertSee('Operating')->assertSee('1 234.5');
        $response->assertSee('HT-08')->assertSee('Stale');
        $response->assertSee('EX-09')->assertSee('Unknown');
        $this->assertStringNotContainsString('EX-09 0.0', $response->getContent());
    }

    public function test_the_unit_page_shows_provenance_and_readings(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($room)->verified()->create();
        $equipment = Equipment::factory()->for($room)->heard(60)->create(['name' => 'HT-07', 'model' => 'B50E', 'serial_number' => 'SN7']);
        EquipmentMetric::factory()->for($equipment)->create(['operating_hours' => 1200.0, 'recorded_at' => now()->subHour()]);
        EquipmentMetric::factory()->for($equipment)->create(['operating_hours' => 1201.5, 'recorded_at' => now()->subMinutes(5)]);

        $this->actingAs($owner)->get(route('control-rooms.equipment.show', [$room, $equipment]))
            ->assertOk()
            ->assertSee('HT-07')->assertSee('B50E')->assertSee('SN7')
            ->assertSee('dot_mines.metrics')
            ->assertSee('1 201.5');
    }

    public function test_an_unassigned_operator_cannot_see_the_rooms_equipment(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $outsider = $this->member($owner->currentTeam, 'operator');

        $this->actingAs($outsider)->get(route('control-rooms.equipment.index', $room))->assertForbidden();
    }
}
