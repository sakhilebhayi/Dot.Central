<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use App\Services\AgentChatService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentChatServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Builds a real Anthropic-shaped SSE body so the parser is tested
     * against the actual documented event format, not a simplified stand-in.
     */
    private function sseBody(array $textDeltas, int $inputTokens = 10, int $outputTokens = 5, bool $endWithStop = true): string
    {
        $body = "event: message_start\n";
        $body .= 'data: '.json_encode(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => $inputTokens]]])."\n\n";

        foreach ($textDeltas as $delta) {
            $body .= "event: content_block_delta\n";
            $body .= 'data: '.json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => $delta]])."\n\n";
        }

        $body .= "event: message_delta\n";
        $body .= 'data: '.json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => $outputTokens]])."\n\n";

        if ($endWithStop) {
            $body .= "event: message_stop\n";
            $body .= 'data: '.json_encode(['type' => 'message_stop'])."\n\n";
        }

        return $body;
    }

    public function test_a_complete_stream_accumulates_text_and_reports_complete_true(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody(['Hello', ', ', 'world!']), 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $chunks = [];
        $result = app(AgentChatService::class)->chat($conversation, 'Hi', $user->id, function (string $textSoFar) use (&$chunks) {
            $chunks[] = $textSoFar;
        });

        $this->assertSame(['text' => 'Hello, world!', 'complete' => true], $result);
        $this->assertSame(['Hello', 'Hello, ', 'Hello, world!'], $chunks);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello, world!',
            'tokens_used' => 5,
        ]);
        $this->assertDatabaseHas('agent_usage_logs', [
            'user_id' => $user->id,
            'agent_id' => $agent->id,
            'tokens_input' => 10,
            'tokens_output' => 5,
        ]);
    }

    public function test_a_stream_that_ends_without_message_stop_returns_partial_text_and_complete_false(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody(['Partial', ' answer'], endWithStop: false), 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $result = app(AgentChatService::class)->chat($conversation, 'Hi', $user->id);

        $this->assertSame(['text' => 'Partial answer', 'complete' => false], $result);

        // Partial text is still real output -- persisted, not discarded.
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Partial answer',
        ]);
    }

    public function test_an_immediate_failure_before_any_content_returns_null_text_and_complete_false(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('', 500)]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $result = app(AgentChatService::class)->chat($conversation, 'Hi', $user->id);

        $this->assertSame(['text' => null, 'complete' => false], $result);
        $this->assertDatabaseMissing('messages', ['conversation_id' => $conversation->id, 'role' => 'assistant']);
    }

    public function test_the_mock_echo_fallback_still_calls_onchunk_once_and_reports_complete_true(): void
    {
        config(['services.anthropic.api_key' => '']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['name' => 'Test Agent']);
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $chunks = [];
        $result = app(AgentChatService::class)->chat($conversation, 'Hi', $user->id, function (string $textSoFar) use (&$chunks) {
            $chunks[] = $textSoFar;
        });

        $this->assertTrue($result['complete']);
        $this->assertNotNull($result['text']);
        $this->assertCount(1, $chunks);
        $this->assertSame($result['text'], $chunks[0]);
    }
}
