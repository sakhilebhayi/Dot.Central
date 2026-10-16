<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Site scoping: operators and supervisors only see control rooms they are
 * assigned to; managers and administrators see every room of the team.
 */
class ControlRoomAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user;
    }

    public function test_assigned_operator_sees_the_room_and_unassigned_operator_does_not(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create(['name' => 'North Room']);
        $other = ControlRoom::factory()->for($team)->create(['name' => 'South Room']);

        $assigned = $this->member($team, 'operator');
        $room->users()->attach($assigned);
        $unassigned = $this->member($team, 'operator');

        $this->actingAs($assigned)->get(route('control-rooms.index'))
            ->assertOk()->assertSee('North Room')->assertDontSee('South Room');
        $this->actingAs($assigned)->get(route('control-rooms.show', $room))->assertOk();
        $this->actingAs($assigned)->get(route('control-rooms.show', $other))->assertForbidden();

        $this->actingAs($unassigned)->get(route('control-rooms.index'))
            ->assertOk()->assertDontSee('North Room');
        $this->actingAs($unassigned)->get(route('control-rooms.show', $room))->assertForbidden();
    }

    public function test_manager_sees_every_room_without_assignment(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create(['name' => 'North Room']);
        $manager = $this->member($team, 'manager');

        $this->actingAs($manager)->get(route('control-rooms.index'))->assertOk()->assertSee('North Room');
        $this->actingAs($manager)->get(route('control-rooms.show', $room))->assertOk();
    }

    public function test_administrator_can_assign_and_unassign_team_members(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        $operator = $this->member($team, 'operator');

        $this->actingAs($owner)
            ->post(route('control-rooms.members.store', $room), ['user_id' => $operator->id])
            ->assertRedirect(route('control-rooms.show', $room));
        $this->assertTrue($room->fresh()->users->contains($operator));

        $this->actingAs($owner)
            ->delete(route('control-rooms.members.destroy', [$room, $operator]))
            ->assertRedirect(route('control-rooms.show', $room));
        $this->assertFalse($room->fresh()->users->contains($operator));
    }

    public function test_only_team_members_can_be_assigned(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $stranger = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)
            ->post(route('control-rooms.members.store', $room), ['user_id' => $stranger->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_operators_cannot_manage_members(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        $operator = $this->member($team, 'operator');
        $room->users()->attach($operator);

        $this->actingAs($operator)
            ->post(route('control-rooms.members.store', $room), ['user_id' => $operator->id])
            ->assertForbidden();
    }

    public function test_creating_a_room_assigns_its_creator(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)->post(route('control-rooms.store'), ['name' => 'Created Room']);

        $room = ControlRoom::where('name', 'Created Room')->firstOrFail();
        $this->assertTrue($room->users->contains($owner));
    }
}
