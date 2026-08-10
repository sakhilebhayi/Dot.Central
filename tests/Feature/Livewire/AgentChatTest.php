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

    private function sseBody(array $textDeltas, bool $endWithStop = true): string
    {
        $body = "event: message_start\n";
        $body .= 'data: '.json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 12]]])."\n\n";

        foreach ($textDeltas as $delta) {
            $body .= "event: content_block_delta\n";
            $body .= 'data: '.json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]])."\n\n";
        }

        $body .= "event: message_delta\n";
        $body .= 'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 8]])."\n\n";

        if ($endWithStop) {
            $body .= "event: message_stop\n";
            $body .= 'data: '.json_encode(['type' => 'message_stop'])."\n\n";
        }

        return $body;
    }

    public function test_sending_a_message_persists_it_and_shows_the_agents_reply(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody(['Hello! ', 'How can I help?']), 200)]);
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
        Http::fake(['api.anthropic.com/*' => Http::response('', 500)]);
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

    public function test_a_mid_stream_interruption_keeps_the_partial_reply_and_sets_a_distinct_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response($this->sseBody(['Partial', ' answer'], endWithStop: false), 200)]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        Livewire::actingAs($user)
            ->test(AgentChat::class, ['agent' => $agent, 'conversation' => $conversation])
            ->set('message', 'Hi there')
            ->call('send')
            ->assertSet('error', "The response was interrupted. What was generated is shown above — please try again if you'd like the rest.");

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Partial answer',
        ]);
    }

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
