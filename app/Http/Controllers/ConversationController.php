<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A conversation is always created explicitly (store) and opened by ID
 * (show) — no "guess which one is most recent" logic anywhere in this
 * path. See AgentChat::mount(), which relies on always receiving a real
 * Conversation for exactly this reason.
 */
class ConversationController extends Controller
{
    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $conversation = $agent->conversations()->create([
            'user_id' => $request->user()->id,
            'title' => 'Chat with '.$agent->name.' — '.now()->format('M j, H:i'),
        ]);

        return redirect()->route('agents.chat', [$agent, $conversation]);
    }

    public function show(Agent $agent, Conversation $conversation): View
    {
        abort_unless($conversation->agent_id === $agent->id, 404);

        return view('agents.chat', compact('agent', 'conversation'));
    }
}
