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

    public function chat(Conversation $conversation, string $userMessage, int $userId): ?string
    {
        $agent = $conversation->agent;

        // Persist user message
        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        if (! $this->isConfigured()) {
            $reply = $this->mockReply($agent, $userMessage);
            Message::create([
                'conversation_id' => $conversation->id,
                'role' => 'assistant',
                'content' => $reply,
            ]);

            return $reply;
        }

        // Build messages array from conversation history
        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        $response = Http::withToken($this->apiKey)
            ->withHeaders(['anthropic-version' => '2023-06-01'])
            ->timeout(30)
            ->post('https://api.anthropic.com/v1/messages', [
                'model' => $agent->model,
                'max_tokens' => 1024,
                'system' => $agent->system_prompt,
                'messages' => $history,
            ]);

        if (! $response->successful()) {
            Log::error('AgentChat API error', ['status' => $response->status(), 'agent' => $agent->slug]);

            return null;
        }

        $reply = $response->json('content.0.text', '');
        $inputTokens = $response->json('usage.input_tokens', 0);
        $outTokens = $response->json('usage.output_tokens', 0);

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $reply,
            'tokens_used' => $outTokens,
        ]);

        AgentUsageLog::create([
            'user_id' => $userId,
            'agent_id' => $agent->id,
            'tokens_input' => $inputTokens,
            'tokens_output' => $outTokens,
        ]);

        return $reply;
    }

    private function mockReply(Agent $agent, string $userMessage): string
    {
        return "Hi! I'm {$agent->name}. You said: \"{$userMessage}\". (AI responses require ANTHROPIC_API_KEY to be configured.)";
    }
}
