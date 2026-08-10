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

    public Conversation $conversation;

    #[Validate('required|string|max:4000')]
    public string $message = '';

    public bool $sending = false;

    public ?string $error = null;

    /**
     * Both an agent and an already-existing conversation are always
     * provided by the caller (see ConversationController::show) — every
     * conversation is created explicitly via ConversationController::store
     * before this component is ever mounted, so there is no ambiguous
     * "find or create the most recent one" case to handle here.
     */
    public function mount(Agent $agent, Conversation $conversation): void
    {
        abort_unless($conversation->agent_id === $agent->id, 404);

        $this->agent = $agent;
        $this->conversation = $conversation;
    }

    /**
     * Deliberately not named messages() — Livewire reserves that exact
     * method name on every component for custom validation messages
     * (alongside rules()/validationAttributes()). A #[Computed]
     * messages() here silently collides with it: the instant #[Validate]
     * fires on a property update, Livewire calls messages() expecting an
     * array and gets this Collection instead, crashing with
     * "array_merge(): Argument #1 must be of type array, ... Collection
     * given" deep inside Livewire's own validation internals. Found live,
     * via this component's own test, not by inspection — the pre-existing
     * code had this exact collision and nothing had ever exercised the
     * validation path before.
     */
    #[Computed]
    public function conversationMessages(): Collection
    {
        return $this->conversation->messages()->orderBy('created_at')->get();
    }

    public function send(): void
    {
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
        unset($this->conversationMessages);
    }

    public function render(): View
    {
        return view('livewire.agents.agent-chat');
    }
}
