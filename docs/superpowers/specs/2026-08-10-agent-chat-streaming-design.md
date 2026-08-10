# Streaming Responses for AgentChatService — Design Spec

## Context

`wiki.md` §7's roadmap has named "Add streaming responses to `AgentChatService`" as an open item since the AI-agent domain's wiki entries began. Today, `AgentChatService::chat()` makes one blocking `Http::post()` call to Anthropic's Messages API and waits for the full response before returning — the chat UI (`AgentChat`, `resources/views/livewire/agents/agent-chat.blade.php`, both shipped 2026-08-10) shows a "typing…" indicator for the entire duration, then the complete reply appears at once.

**A framing correction made during this design, not assumed going in:** the initial brief for this work assumed Livewire 3 (this app's frontend stack) had no native support for incremental content delivery, and considered either a dedicated non-Livewire SSE endpoint or Reverb/Echo broadcasting as the two viable mechanisms. Neither assumption held up:

- Livewire 3.6.4 (the version actually installed here, confirmed via `composer.json`) ships a native streaming feature — `Component::stream($to, $content, $replace)`, backed by `Livewire\Features\SupportStreaming`, which opens a `text/event-stream` response and pushes content to a `wire:stream`-targeted element in the browser, entirely within Livewire's own request lifecycle. No new routes, no new infrastructure.
- Reverb/Echo broadcasting was checked directly, not assumed unavailable: `laravel/reverb` is a `composer.json` dependency, but `config/broadcasting.php` doesn't exist, no JS Echo client exists anywhere in `resources/js`, and no code anywhere references `Broadcast`/`ShouldBroadcast`/`Echo`. It's an installed-but-entirely-unused dependency, not real infrastructure — ruled out for this pass on that basis, not deprioritized as a style preference.

This changes the shape of the work substantially: the "how do tokens get to the browser" question is settled by Livewire's own native mechanism, not a real architectural fork.

## Goal

Make replies appear incrementally as Anthropic generates them, using Livewire's native `stream()`, with no new infrastructure and no change to `AgentChatService`'s persistence guarantees (a `Message` row and an `AgentUsageLog` row are still written exactly once per turn).

## Design

### 1. Data flow

`AgentChatService::chat()` keeps its role (persist the user message, call Anthropic, persist the assistant message, log usage) but its internals change: instead of one blocking `Http::post()`, it sends `"stream": true` in Anthropic's request payload and reads the response body incrementally via Laravel's HTTP client (`Http::withOptions(['stream' => true])`), parsing Anthropic's own Server-Sent Events as bytes arrive rather than waiting for the full JSON body.

Anthropic's streaming format (a stable, documented part of the Messages API): a sequence of `event: <type>\ndata: <json>\n\n` blocks. The relevant ones:
- `message_start` — carries `message.usage.input_tokens`.
- `content_block_delta` — carries one `delta.text` fragment at a time; these are what accumulate into the reply.
- `message_delta` — carries `usage.output_tokens` (the running/final output token count).
- `message_stop` — signals a normal, complete finish.

Signature changes: `chat(Conversation $conversation, string $userMessage, int $userId, ?callable $onChunk = null)`. `$onChunk`, when given, is called with the accumulated text so far after every `content_block_delta`. Return type changes from a plain `?string` to `['text' => ?string, 'complete' => bool]` — "got a full reply" and "got a partial reply before the connection dropped" are two different real outcomes the caller needs to distinguish now, which a bare nullable string can't express. There is exactly one caller today (`AgentChat::send()`), updated in this same change, so this is not a real compatibility concern despite being a signature change.

The no-`ANTHROPIC_API_KEY` mock-echo fallback (existing, deliberate dev-mode behavior) is unchanged in effect: it still returns instantly, but now calls `$onChunk` once with the full mock text before returning `['text' => $mockReply, 'complete' => true]`, so the same code path in `AgentChat::send()` and the same `wire:stream` target in the view are exercised in dev/no-key environments too — no separate non-streaming branch to maintain.

### 2. Component & view changes

`AgentChat::send()` builds the callback it passes as `$onChunk`:

```php
$service->chat($this->conversation, $userMessage, auth()->id(), function (string $textSoFar) {
    $this->stream(to: 'reply', content: $textSoFar, replace: true);
});
```

On return, `send()` checks `complete`: if `false`, sets `$this->error` to the existing plain-language message (`'The agent failed to respond. Please try again.'` for a total failure, or a new, distinct message for a partial one — see Error Handling below) — alongside whatever text was accumulated, which is still persisted as a real `Message` row either way. The `Message` row is created once, at the end of `chat()`, with whatever text was accumulated (full or partial) — never progressively during streaming. A page refresh mid-stream loses the in-progress reply (nothing was saved yet); the user re-sends. This is a deliberate simplification, not an oversight: progressive persistence would mean a DB write per token for a genuinely rare interaction (refreshing during an active, usually few-second generation).

The Blade view gets one addition: a `wire:stream="reply"` target inside the message list, in the position where the in-progress assistant bubble renders. Livewire replaces its content live as `stream()` fires. Once `send()` returns and Livewire does its next full re-render, `conversationMessages()` (unchanged, already re-fetched via the existing `unset($this->conversationMessages)`) supplies the real persisted message, which takes over from the ephemeral streamed content. The existing `wire:loading`/"typing…" indicator (`wire:target="send"`) stays as-is, but now functions as the state shown only before the first token arrives, not for the whole duration — no code change needed there since it's driven by Livewire's own request lifecycle, which still spans the full `send()` call.

### 3. Error handling

Three distinct outcomes from `chat()`, all already covered by the return shape above:

- **Immediate failure** (bad API key, rate limit, Anthropic unreachable, fails before any `content_block_delta` arrives) — matches today's behavior exactly: `['text' => null, 'complete' => false]`. `send()` sets the existing generic error message. Nothing beyond the user's own message is persisted, same as today.
- **Mid-stream failure** (connection drops, or Anthropic sends an `error` event after some real content already arrived) — new outcome: `['text' => $accumulatedSoFar, 'complete' => false]`. The partial text is persisted as the assistant `Message` (it's real model output, not fabricated — showing it is more honest than discarding it) and `$this->error` is set to a distinct message: `'The response was interrupted. What was generated is shown above — please try again if you'd like the rest.'`.
- **Normal completion** (`message_stop` received) — `['text' => $fullReply, 'complete' => true]`, `$this->error` stays `null`, matches today's success path.

### 4. Testing

- `Http::fake()` supports faking a streamed response body directly (feed it a real, multi-event SSE payload string as the fake response body) — no new test infrastructure needed.
- New `tests/Unit/Services/AgentChatServiceTest.php` (doesn't exist today — this service has only ever been tested indirectly through `AgentChatTest`'s Livewire tests): full-stream success accumulates and returns the complete text with `complete: true`; a truncated/interrupted fake stream returns the partial text with `complete: false`; an immediate-failure fake response (non-2xx before any stream content) returns `['text' => null, 'complete' => false]`; the no-API-key mock-echo path still calls `$onChunk` once and returns `complete: true`.
- `tests/Feature/Livewire/AgentChatTest.php` (existing, from the 2026-08-10 chat-UI build): update the success-case fake to a real SSE payload instead of a single JSON blob; add a mid-stream-interruption case asserting the partial text is both visible (via `assertSet` or the persisted `Message`) and paired with the new distinct interrupted-response error message.

## Explicitly out of scope

- A "stop generating" / cancel button — adds a real abort-the-live-HTTP-connection mechanism for a case (long-running generation) this chat feature doesn't typically hit; can be added later without touching this design's shape.
- Progressive/partial `Message` persistence for refresh-survival — see §2; a deliberate simplification, not a gap.
- Any change to `AgentUsageLog` writing — still one row per turn, using the token counts Anthropic reports in `message_start`/`message_delta`, same as today's single-response parsing, just read from two streamed events instead of one final JSON body.
- Streaming for the mock-echo (no-API-key) path — it calls `$onChunk` once with the full text; there is nothing external to genuinely stream from in that mode, and simulating fake incremental delivery there would add complexity for a dev-only fallback path with no real latency to hide.
