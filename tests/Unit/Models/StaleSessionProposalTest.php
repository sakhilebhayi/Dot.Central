<?php

namespace Tests\Unit\Models;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleSessionProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_session_a_control_room_and_a_resolver(): void
    {
        $team = Team::factory()->create();
        $controlRoom = ControlRoom::factory()->for($team)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create();
        $resolver = User::factory()->create();

        $proposal = StaleSessionProposal::factory()
            ->for($session, 'operatorSession')
            ->for($controlRoom)
            ->create(['status' => 'pending']);

        $this->assertTrue($proposal->operatorSession->is($session));
        $this->assertTrue($proposal->controlRoom->is($controlRoom));
        $this->assertNull($proposal->resolver);

        $proposal->update(['status' => 'ended', 'resolved_at' => now(), 'resolved_by' => $resolver->id]);
        $this->assertTrue($proposal->fresh()->resolver->is($resolver));
    }

    public function test_it_is_scoped_to_the_current_teams_control_rooms(): void
    {
        $userA = User::factory()->withPersonalTeam()->create();
        $userB = User::factory()->withPersonalTeam()->create();
        $roomA = ControlRoom::factory()->for($userA->currentTeam)->create();
        $roomB = ControlRoom::factory()->for($userB->currentTeam)->create();

        StaleSessionProposal::factory()->for($roomA)
            ->for(OperatorSession::factory()->for($roomA), 'operatorSession')->create();
        StaleSessionProposal::factory()->for($roomB)
            ->for(OperatorSession::factory()->for($roomB), 'operatorSession')->create();

        $this->actingAs($userA);

        $this->assertSame(1, StaleSessionProposal::count());
    }
}
