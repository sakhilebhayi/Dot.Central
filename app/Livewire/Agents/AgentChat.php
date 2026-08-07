<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\AgentChatService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AgentChat extends Component
{
    public Agent $agent;

    public ?Conversation $conversation = null;

    #[Validate('required|string|max:4000')]
    public string $message = '';

    public bool $sending = false;

    public ?string $error = null;

    public function mount(Agent $agent): void
    {
        $this->agent = $agent;
    }

    public function startOrContinue(): void
    {
        $this->conversation = Conversation::firstOrCreate(
            [
                'user_id' => auth()->id(),
                'agent_id' => $this->agent->id,
            ],
            ['title' => 'Chat with '.$this->agent->name]
        );
    }

    #[Computed]
    public function messages(): Collection
    {
        if (! $this->conversation) {
            return collect();
        }

        return $this->conversation->messages()->orderBy('created_at')->get();
    }

    public function send(): void
    {
        if (! $this->conversation) {
            $this->startOrContinue();
        }

        $this->validate();

        $this->sending = true;
        $this->error = null;
        $userMessage = $this->message;
        $this->message = '';

        $service = app(AgentChatService::class);
        $reply = $service->chat($this->conversation, $userMessage, auth()->id());

        if ($reply === null) {
            $this->error = 'The agent failed to respond. Please try again.';
        }

        $this->sending = false;
        unset($this->messages);
    }

    public function newConversation(): void
    {
        $this->conversation = Conversation::create([
            'user_id' => auth()->id(),
            'agent_id' => $this->agent->id,
            'title' => 'Chat with '.$this->agent->name.' — '.now()->format('M j, H:i'),
        ]);
        unset($this->messages);
    }

    public function render(): View
    {
        return view('livewire.agents.agent-chat');
    }
}
