<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Agents\AgentChat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_persists_it_and_shows_the_agents_reply(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello! How can I help?']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
            ], 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        Livewire::actingAs($user)
            ->test(AgentChat::class, ['agent' => $agent, 'conversation' => $conversation])
            ->set('message', 'Hi there')
            ->call('send')
            ->assertSet('message', '')
            ->assertSet('error', null);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi there',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello! How can I help?',
        ]);
    }

    public function test_a_failed_api_call_sets_a_plain_language_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        Livewire::actingAs($user)
            ->test(AgentChat::class, ['agent' => $agent, 'conversation' => $conversation])
            ->set('message', 'Hi there')
            ->call('send')
            ->assertSet('error', 'The agent failed to respond. Please try again.');
    }

    /**
     * abort_unless()'s 404 doesn't bubble up to PHPUnit as a raw exception
     * here — Livewire's initial-mount test path runs through the app's
     * real exception handler, so a mismatched agent/conversation renders
     * the same branded 404 page a real request would get. assertNotFound()
     * proves that, rather than expectException(), which found nothing to
     * catch on the first attempt at this test (confirmed by isolating the
     * call outside PHPUnit's own output-compacting wrapper).
     */
    public function test_mounting_with_a_conversation_belonging_to_a_different_agent_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agentA = Agent::factory()->for($user->currentTeam)->create();
        $agentB = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agentA)->create();

        $this->actingAs($user);

        Livewire::test(AgentChat::class, ['agent' => $agentB, 'conversation' => $conversation])
            ->assertNotFound();
    }
}
