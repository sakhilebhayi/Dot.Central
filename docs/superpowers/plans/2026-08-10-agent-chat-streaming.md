# Streaming Responses for AgentChatService Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make agent replies appear incrementally as Anthropic generates them, using Livewire's native `stream()` — no new routes, no broadcasting infrastructure.

**Architecture:** `AgentChatService::chat()` switches from one blocking `Http::post()` to a streaming request (`"stream": true`), parsing Anthropic's own Server-Sent Events as bytes arrive and calling an optional `$onChunk` callback after each text delta. `AgentChat::send()` passes a closure that calls Livewire's `$this->stream()`, and the Blade view gets one `wire:stream` target. The `Message`/`AgentUsageLog` rows are still written exactly once per turn, at the end — never progressively.

**Tech Stack:** Laravel 12 (Guzzle 7.8.2, supports streamed HTTP responses via `withOptions(['stream' => true])`), Livewire 3.6.4 (native `Component::stream()`), PHPUnit, Laravel Pint.

## Global Constraints

- `AgentChatService::chat()`'s return type changes from `?string` to `array{text: ?string, complete: bool}`. There is exactly one caller (`AgentChat::send()`), updated in this same plan — confirmed via `grep -rn "->chat(" app/ tests/` before starting, no other call sites exist.
- Anthropic's streaming SSE format (stable, documented): `event: <type>\ndata: <json>\n\n` blocks. `content_block_delta` events (with `delta.type === 'text_delta'`) carry each text fragment; `message_start` carries `message.usage.input_tokens`; `message_delta` carries `usage.output_tokens`; `message_stop` signals a normal, complete finish.
- Full spec: `docs/superpowers/specs/2026-08-10-agent-chat-streaming-design.md`. Read it before starting if anything below is ambiguous.
- Match this codebase's existing style: PHPDoc over inline comments except for genuinely non-obvious logic (the SSE parser's event-boundary handling counts), explicit return types, `Http::fake()` for all Anthropic-facing tests — never a real API call.
- The no-`ANTHROPIC_API_KEY` mock-echo path stays a single, non-streamed `$onChunk` call — see spec's "Explicitly out of scope."
- This app's browser-preview environment forces `SESSION_DRIVER=array` (found and documented during the prior chat-UI build's Task 7) — interactive login-gated live verification is not achievable here. Final verification relies on the real test suite plus whatever unauthenticated-route checks are possible, matching that same precedent; don't attempt to re-fight this in this plan.
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.

---

### Task 1: `AgentChatService` — streaming HTTP call, SSE parsing, new return shape

**Files:**
- Modify: `app/Services/AgentChatService.php`
- Test: `tests/Unit/Services/AgentChatServiceTest.php` (new directory — this is the first service-level unit test in this codebase; confirmed via `ls tests/Unit/` that no `Services` subdirectory exists yet)

**Interfaces:**
- Consumes: `Conversation`, `Message`, `AgentUsageLog` (all pre-existing, unchanged).
- Produces: `AgentChatService::chat(Conversation $conversation, string $userMessage, int $userId, ?callable $onChunk = null): array` returning `['text' => ?string, 'complete' => bool]`. `$onChunk`, when given, is called with `string $textSoFar` (the full accumulated text, not just the new fragment) after every content delta — matches `$this->stream(..., replace: true)`'s expectation in Task 2.

- [ ] **Step 1: Write the failing tests**

Create `tests/Unit/Services/AgentChatServiceTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AgentChatServiceTest`
Expected: FAIL — `chat()` still returns a plain string, no `$onChunk` parameter exists yet.

- [ ] **Step 3: Rewrite `AgentChatService`**

Replace `app/Services/AgentChatService.php` in full:

```php
<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentUsageLog;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AgentChatService
{
    private string $apiKey;

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.api_key', '');
    }

    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * @param ?callable(string $textSoFar): void $onChunk Called with the full
     *   accumulated reply text after every new fragment -- not just the new
     *   fragment -- to match Livewire's stream(..., replace: true) usage.
     * @return array{text: ?string, complete: bool} `text` is null only when
     *   nothing was generated at all (immediate failure); a mid-stream
     *   interruption still returns whatever partial text was accumulated,
     *   with complete: false.
     */
    public function chat(Conversation $conversation, string $userMessage, int $userId, ?callable $onChunk = null): array
    {
        $agent = $conversation->agent;

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (! $this->isConfigured()) {
            $reply = $this->mockReply($agent, $userMessage);
            if ($onChunk) {
                $onChunk($reply);
            }
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
            ]);

            return ['text' => $reply, 'complete' => true];
        }

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        $result = $this->streamAnthropic([
            'model' => $agent->model,
            'max_tokens' => 1024,
            'system' => $agent->system_prompt,
            'messages' => $history,
        ], $onChunk);

        if ($result['text'] === null) {
            Log::error('AgentChat API error', ['agent' => $agent->slug]);

            return ['text' => null, 'complete' => false];
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $result['text'],
            'tokens_used' => $result['output_tokens'],
        ]);

        AgentUsageLog::create([
            'user_id' => $userId,
            'agent_id' => $agent->id,
            'tokens_input' => $result['input_tokens'],
            'tokens_output' => $result['output_tokens'],
        ]);

        return ['text' => $result['text'], 'complete' => $result['complete']];
    }

    /**
     * Sends a streaming request to Anthropic and parses the response's
     * Server-Sent Events as bytes arrive, rather than waiting for the full
     * body. Anthropic's stable, documented SSE format: `event: <type>\n
     * data: <json>\n\n` blocks. content_block_delta carries each text
     * fragment; message_start carries input token usage; message_delta
     * carries output token usage; message_stop signals a normal finish.
     *
     * @return array{text: ?string, complete: bool, input_tokens: int, output_tokens: int}
     */
    private function streamAnthropic(array $payload, ?callable $onChunk): array
    {
        $response = Http::withToken($this->apiKey)
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->withOptions(['stream' => true])
            ->timeout(60)
            ->post('https://api.anthropic.com/v1/messages', [...$payload, 'stream' => true]);

        if (! $response->successful()) {
            return ['text' => null, 'complete' => false, 'input_tokens' => 0, 'output_tokens' => 0];
        }

        $text = '';
        $inputTokens = 0;
        $outputTokens = 0;
        $complete = false;

        try {
            $body = $response->toPsrResponse()->getBody();
            $buffer = '';

            while (! $body->eof()) {
                $buffer .= $body->read(1024);

                while (($pos = strpos($buffer, "\n\n")) !== false) {
                    $eventBlock = substr($buffer, 0, $pos);
                    $buffer = substr($buffer, $pos + 2);

                    [$eventType, $data] = $this->parseSseBlock($eventBlock);

                    if ($eventType === 'content_block_delta' && ($data['delta']['type'] ?? null) === 'text_delta') {
                        $text .= $data['delta']['text'];
                        if ($onChunk) {
                            $onChunk($text);
                        }
                    } elseif ($eventType === 'message_start') {
                        $inputTokens = $data['message']['usage']['input_tokens'] ?? 0;
                    } elseif ($eventType === 'message_delta') {
                        $outputTokens = $data['usage']['output_tokens'] ?? $outputTokens;
                    } elseif ($eventType === 'message_stop') {
                        $complete = true;
                    } elseif ($eventType === 'error') {
                        break 2;
                    }
                }
            }
        } catch (\Throwable $e) {
            // Connection dropped mid-stream -- keep whatever text was
            // accumulated so far (real output, not fabricated) rather than
            // discarding it; complete stays false either way.
            Log::warning('AgentChat stream interrupted', ['error' => $e->getMessage()]);
        }

        return [
            'text' => $text !== '' ? $text : null,
            'complete' => $complete,
            'input_tokens' => $inputTokens,
            'output_tokens' => $outputTokens,
        ];
    }

    /**
     * @return array{0: ?string, 1: ?array} [eventType, decodedData]
     */
    private function parseSseBlock(string $block): array
    {
        $eventType = null;
        $data = null;

        foreach (explode("\n", $block) as $line) {
            if (str_starts_with($line, 'event: ')) {
                $eventType = substr($line, 7);
            } elseif (str_starts_with($line, 'data: ')) {
                $data = json_decode(substr($line, 6), true);
            }
        }

        return [$eventType, $data];
    }

    private function mockReply(Agent $agent, string $userMessage): string
    {
        return "Hi! I'm {$agent->name}. You said: \"{$userMessage}\". (AI responses require ANTHROPIC_API_KEY to be configured.)";
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentChatServiceTest`
Expected: PASS, all 4 tests.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AgentChatService.php tests/Unit/Services/AgentChatServiceTest.php
git commit -m "feat: stream AgentChatService responses from Anthropic"
```

---

### Task 2: `AgentChat` + view — wire up streaming

**Files:**
- Modify: `app/Livewire/Agents/AgentChat.php`
- Modify: `resources/views/livewire/agents/agent-chat.blade.php`
- Modify: `tests/Feature/Livewire/AgentChatTest.php`

**Interfaces:**
- Consumes: `AgentChatService::chat()`'s new signature and return shape (Task 1).
- Produces: no new public interface — `send()`'s external behavior (what tests observe: `$error`, persisted `Message` rows) changes per the spec's three outcomes.

- [ ] **Step 1: Update the failing tests**

Modify `tests/Feature/Livewire/AgentChatTest.php` — replace the whole file:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AgentChatTest`
Expected: FAIL — `send()` doesn't pass a callback or handle the new return shape yet.

- [ ] **Step 3: Update `AgentChat::send()`**

In `app/Livewire/Agents/AgentChat.php`, replace the `send()` method:

```php
    public function send(): void
    {
        $this->validate();

        $this->sending = true;
        $this->error = null;
        $userMessage = $this->message;
        $this->message = '';

        $service = app(AgentChatService::class);
        $result = $service->chat($this->conversation, $userMessage, auth()->id(), function (string $textSoFar) {
            $this->stream(to: 'reply', content: $textSoFar, replace: true);
        });

        if ($result['text'] === null) {
            $this->error = 'The agent failed to respond. Please try again.';
        } elseif (! $result['complete']) {
            $this->error = "The response was interrupted. What was generated is shown above — please try again if you'd like the rest.";
        }

        $this->sending = false;
        unset($this->conversationMessages);
    }
```

- [ ] **Step 4: Add the `wire:stream` target to the view**

In `resources/views/livewire/agents/agent-chat.blade.php`, inside the scrollable message list, directly after the `@forelse($this->conversationMessages as $msg) ... @endforelse` block and before the existing `wire:loading` "is typing…" indicator, add:

```blade
        <div wire:stream="reply" style="max-width:70%;align-self:flex-start;background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.9rem;padding:0.65rem 0.9rem;color:var(--text-primary);font-size:0.85rem;line-height:1.5;white-space:pre-wrap;"></div>
```

This element is always present (empty by default), matching Livewire's documented `wire:stream` pattern — an empty bubble with no visible border/background content renders as effectively invisible until `stream()` fills it, so no conditional wrapper is needed.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentChatTest`
Expected: PASS, all 4 tests.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Agents/AgentChat.php resources/views/livewire/agents/agent-chat.blade.php tests/Feature/Livewire/AgentChatTest.php
git commit -m "feat: stream agent replies into the chat view via Livewire's native stream()"
```

---

### Task 3: Final verification + wiki.md update

**Files:**
- Modify: `wiki.md`

- [ ] **Step 1: Run the full backend suite**

Run: `php artisan test --compact`
Expected: PASS, all tests (report the real total — this plan adds 8 new tests: 4 in `AgentChatServiceTest`, and `AgentChatTest` grows from 3 to 4; confirm the actual count from the output).

- [ ] **Step 2: Pint, repo-wide**

Run: `vendor/bin/pint --format agent`
Expected: no changes needed beyond what Tasks 1-2 already fixed.

- [ ] **Step 3: Verification, honestly scoped to what's achievable**

This environment's browser-preview launcher forces `SESSION_DRIVER=array` (documented in the prior chat-UI build's wiki.md entry), so interactive login-gated verification of the actual visual streaming behavior is not achievable here — don't attempt to re-fight this. What to actually do: confirm the full test suite (Step 1) genuinely exercises the real SSE-parsing code path (not a simplified stand-in) by re-reading `AgentChatServiceTest`'s fake response bodies and confirming they match Anthropic's real documented event format field-for-field. If a real `ANTHROPIC_API_KEY` happens to be available in this environment, optionally start the dev server and manually verify one real streamed reply renders progressively — but do not block completion on this being possible, and do not fabricate a "verified live" claim if it wasn't.

- [ ] **Step 4: Update `wiki.md`**

Read §2 (Architecture) and §7 (Roadmap)'s current text before editing, to match established voice.

Update:
- §2: `AgentChatService::chat()`'s description sentence (currently describes one blocking HTTP call) — update to describe the streaming behavior and the new `(text, complete)` return shape.
- §7 Roadmap: remove `- [ ] Add streaming responses to \`AgentChatService\`` and replace with a closed `- [x]` entry summarizing what was built, referencing the spec/plan file paths, matching the existing closed-item format.
- Bump the frontmatter `version:` (currently `0.10.0`) and add a Change Log row with today's date: what was found (the framing correction — Livewire's native `stream()` existed all along, Reverb was confirmed unused rather than assumed), what was built, the real test count from Step 1, and the honest verification-scope note from Step 3.

- [ ] **Step 5: Commit**

```bash
git add wiki.md
git commit -m "docs: update wiki.md for streaming AgentChatService responses"
```

Do not push without explicit confirmation — matches this platform's established pattern.
