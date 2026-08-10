<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentKnowledge;
use App\Models\AgentSkill;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for the AI-agent domain's CRUD — mirrors
 * ControlRoomTest's shape exactly, since AgentController follows the
 * same plain-controller, team-scoped convention.
 */
class AgentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/agents')->assertRedirect('/login');
    }

    public function test_index_shows_the_current_teams_agents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);

        $this->actingAs($user)
            ->get('/agents')
            ->assertOk()
            ->assertSee('Support Bot');
    }

    public function test_user_can_create_an_agent_with_assigned_skills(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $skill = AgentSkill::factory()->create(['name' => 'Research']);

        $response = $this->actingAs($user)->post('/agents', [
            'name' => 'Research Assistant',
            'description' => 'Helps with research tasks.',
            'system_prompt' => 'You are a research assistant.',
            'model' => 'claude-sonnet-4-6',
            'skills' => [$skill->id],
        ]);

        $this->assertDatabaseHas('agents', [
            'name' => 'Research Assistant',
            'team_id' => $user->currentTeam->id,
        ]);

        $agent = Agent::where('name', 'Research Assistant')->firstOrFail();
        $this->assertTrue($agent->skills->contains($skill));
        $response->assertRedirect(route('agents.show', $agent));
    }

    public function test_user_can_create_an_agent_with_assigned_knowledge(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = AgentKnowledge::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->post('/agents', [
            'name' => 'Support Bot',
            'system_prompt' => 'You are a support agent.',
            'model' => 'claude-sonnet-4-6',
            'knowledge' => [$doc->id],
        ]);

        $agent = Agent::where('name', 'Support Bot')->firstOrFail();
        $this->assertTrue($agent->knowledge->contains($doc));
        $response->assertRedirect(route('agents.show', $agent));
    }

    public function test_creating_an_agent_without_a_current_team_is_forbidden(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($user)->post('/agents', [
            'name' => 'Orphan Agent',
            'system_prompt' => 'You are an assistant.',
        ])->assertForbidden();
    }

    public function test_user_can_update_an_agent_including_deactivating_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['is_active' => true]);

        $this->actingAs($user)->put(route('agents.update', $agent), [
            'name' => 'Renamed Agent',
            'system_prompt' => 'You are still an assistant.',
            'model' => 'claude-sonnet-4-6',
            'is_active' => false,
        ]);

        $agent->refresh();
        $this->assertSame('Renamed Agent', $agent->name);
        $this->assertFalse($agent->is_active);
    }

    public function test_show_page_lists_this_users_past_conversations_with_the_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);
        Conversation::factory()->for($user)->for($agent)->create(['title' => 'Debugging session']);

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('Support Bot')
            ->assertSee('Debugging session');
    }

    /**
     * As of HasTeamScope, an agent belonging to another team is invisible
     * to implicit route-model binding before any explicit check runs —
     * 404, not 403, matching ControlRoomTest's identical assertion for
     * the same reason.
     */
    public function test_a_user_cannot_view_an_agent_belonging_to_another_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('agents.show', $agent))
            ->assertNotFound();
    }
}
