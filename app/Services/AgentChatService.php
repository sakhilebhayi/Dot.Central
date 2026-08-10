<?php

namespace App\Services;

use App\Models\Agent;
use App\Models\AgentKnowledge;
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
     * @param  ?callable(string $textSoFar): void  $onChunk  Called with the full
     *                                                       accumulated reply text after every new fragment -- not just the new
     *                                                       fragment -- to match Livewire's stream(..., replace: true) usage.
     * @return array{text: ?string, complete: bool} `text` is null only when
     *                                              nothing was generated at all (immediate failure); a mid-stream
     *                                              interruption still returns whatever partial text was accumulated,
     *                                              with complete: false.
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

        $systemPrompt = $this->buildSystemPrompt($agent, $userMessage);

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        $result = $this->streamAnthropic([
            'model' => $agent->model,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
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
     * Appends up to 3 assigned AgentKnowledge documents whose title or
     * content matches a word from the user's message, ranked by number of
     * distinct matched words. Retrieval failure is never a chat failure --
     * if the search itself throws, log it and fall back to the agent's
     * plain system_prompt rather than blocking the conversation.
     */
    private function buildSystemPrompt(Agent $agent, string $userMessage): string
    {
        try {
            $words = array_unique(array_filter(
                array_map('strtolower', preg_split('/\s+/', trim($userMessage))),
                fn ($w) => mb_strlen($w) >= 3
            ));

            if (empty($words)) {
                return $agent->system_prompt;
            }

            // $agent->knowledge() is itself directly query-buildable (all
            // Eloquent relations proxy query-builder methods) -- calling
            // ->newQuery() here would have been a real bug: it returns a
            // fresh, unrelated query against AgentKnowledge's base table,
            // silently dropping the BelongsToMany pivot join/filtering and
            // searching every team's documents, not just this agent's
            // assigned ones. Caught in this plan's own self-review, not
            // written this way originally -- worth the explicit note so a
            // future edit doesn't reintroduce it.
            $matches = $agent->knowledge()
                ->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere(fn ($sub) => $sub
                            ->whereRaw('LOWER(agent_knowledge.title) LIKE ?', ['%'.$word.'%'])
                            ->orWhereRaw('LOWER(agent_knowledge.content) LIKE ?', ['%'.$word.'%']));
                    }
                })
                ->get()
                ->map(function (AgentKnowledge $doc) use ($words) {
                    $haystack = strtolower($doc->title.' '.$doc->content);
                    $doc->matchCount = collect($words)->filter(fn ($w) => str_contains($haystack, $w))->count();

                    return $doc;
                })
                ->filter(fn ($doc) => $doc->matchCount > 0)
                ->sortByDesc('matchCount')
                ->take(3);

            if ($matches->isEmpty()) {
                return $agent->system_prompt;
            }

            $excerpts = $matches->map(fn ($doc) => "---\n{$doc->title}\n{$doc->content}\n---")->implode("\n");

            return $agent->system_prompt."\n\nRelevant reference material:\n\n".$excerpts;
        } catch (\Throwable $e) {
            Log::warning('AgentKnowledge retrieval failed', ['agent' => $agent->slug, 'error' => $e->getMessage()]);

            return $agent->system_prompt;
        }
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
