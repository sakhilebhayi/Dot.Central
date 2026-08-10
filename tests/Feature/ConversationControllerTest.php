<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_conversation_and_redirects_to_the_chat_screen(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->post(route('agents.conversations.store', $agent));

        $conversation = Conversation::where('agent_id', $agent->id)->where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('agents.chat', [$agent, $conversation]));
    }

    public function test_show_renders_the_chat_screen_for_an_existing_conversation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $this->actingAs($user)
            ->get(route('agents.chat', [$agent, $conversation]))
            ->assertOk()
            ->assertSee('Support Bot');
    }

    public function test_a_conversation_url_that_does_not_match_its_agent_404s(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agentA = Agent::factory()->for($user->currentTeam)->create();
        $agentB = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agentA)->create();

        $this->actingAs($user)
            ->get(route('agents.chat', [$agentB, $conversation]))
            ->assertNotFound();
    }

    public function test_another_users_conversation_404s(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create();
        $conversation = Conversation::factory()->for($owner)->for($agent)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('agents.chat', [$agent, $conversation]))
            ->assertNotFound();
    }
}
