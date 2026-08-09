<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleSessionProposalTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);
        $proposal = StaleSessionProposal::factory()
            ->for($session, 'operatorSession')
            ->for($controlRoom)
            ->create(['hours_silent' => 9, 'status' => 'pending']);

        return compact('owner', 'controlRoom', 'session', 'proposal');
    }

    public function test_a_team_member_can_end_the_session(): void
    {
        ['owner' => $owner, 'session' => $session, 'proposal' => $proposal] = $this->makeProposal();

        $response = $this->actingAs($owner)
            ->patch(route('stale-session-proposals.end', $proposal));

        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('ended', $proposal->fresh()->status);
        $this->assertSame($owner->id, $proposal->fresh()->resolved_by);
        $response->assertRedirect(route('control-rooms.show', $proposal->controlRoom));
    }

    public function test_a_team_member_can_dismiss_the_proposal(): void
    {
        ['owner' => $owner, 'session' => $session, 'proposal' => $proposal] = $this->makeProposal();

        $this->actingAs($owner)->patch(route('stale-session-proposals.dismiss', $proposal));

        $this->assertNull($session->fresh()->ended_at);
        $this->assertSame('dismissed', $proposal->fresh()->status);
    }

    public function test_a_user_from_a_different_team_gets_a_404(): void
    {
        // HasControlRoomTeamScope (see its own docblock) makes a
        // route-model-bound record belonging to another team invisible
        // before StaleSessionProposalController's abort_unless() check
        // ever runs -- a 404, not a 403, matching the identical documented
        // behavior already established for Alert/OperatorSession.
        ['session' => $session, 'proposal' => $proposal] = $this->makeProposal();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->patch(route('stale-session-proposals.end', $proposal))
            ->assertNotFound();

        $this->assertNull($session->fresh()->ended_at);
        $this->assertSame('pending', $proposal->fresh()->status);
    }
}
