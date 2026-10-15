<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Operator;
use App\Models\User;
use Database\Seeders\LocalPilotSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The local pilot seeder gives a fresh database a working control room and one user per role, idempotently (issue I-27). */
class LocalPilotSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_pilot_room_with_users_and_operators_and_is_idempotent(): void
    {
        $this->seed(LocalPilotSeeder::class);
        $this->seed(LocalPilotSeeder::class);

        $room = ControlRoom::withoutGlobalScopes()->where('name', 'Pilot Control Room')->firstOrFail();
        $this->assertSame(1, ControlRoom::withoutGlobalScopes()->where('name', 'Pilot Control Room')->count());
        $this->assertSame(2, $room->shiftTypes()->count());
        $this->assertGreaterThan(10, $room->downtimeReasons()->count());
        $this->assertSame(2, Operator::withoutGlobalScopes()->where('control_room_id', $room->id)->count());

        $supervisor = User::where('email', LocalPilotSeeder::SUPERVISOR)->firstOrFail();
        $operator = User::where('email', LocalPilotSeeder::OPERATOR)->firstOrFail();
        $admin = User::where('email', LocalPilotSeeder::ADMIN)->firstOrFail();
        $this->assertTrue($supervisor->can('supervise', $room));
        $this->assertTrue($operator->can('operate', $room));
        $this->assertFalse($operator->can('supervise', $room));
        $this->assertTrue($admin->can('manage', $room));
        $this->assertNull($room->minesConnection, 'the Dot.Mines token is minted by a human, never seeded');

        $this->actingAs($supervisor)->get('/dashboard')->assertOk()->assertSee('Pilot Control Room');
    }
}
