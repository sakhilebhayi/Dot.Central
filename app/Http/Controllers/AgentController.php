<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentSkill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Basic CRUD for AI agents — team-scoped, matching ControlRoomController's
 * established convention exactly (plain controller, no Livewire; Livewire
 * is reserved for the chat screen itself, see AgentChat).
 */
class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $agents = $request->user()->currentTeam
            ? $request->user()->currentTeam->agents()->withCount('conversations')->latest()->get()
            : collect();

        return view('agents.index', compact('agents'));
    }

    public function create(): View
    {
        $skills = AgentSkill::orderBy('name')->get();
        $knowledge = auth()->user()->currentTeam
            ? auth()->user()->currentTeam->agentKnowledge()->latest()->get()
            : collect();

        return view('agents.create', compact('skills', 'knowledge'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer', 'exists:agent_skills,id'],
            'knowledge' => ['nullable', 'array'],
            'knowledge.*' => ['integer', 'exists:agent_knowledge,id'],
        ]);

        abort_unless($request->user()->currentTeam, 403, 'You need a team before creating an agent.');

        $agent = $request->user()->currentTeam->agents()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'model' => $validated['model'] ?? 'claude-sonnet-4-6',
        ]);

        $agent->skills()->sync($validated['skills'] ?? []);
        $agent->knowledge()->sync($validated['knowledge'] ?? []);

        return redirect()->route('agents.show', $agent);
    }

    public function show(Request $request, Agent $agent): View
    {
        $agent->load('skills');
        $conversations = $agent->conversations()->where('user_id', $request->user()->id)->latest()->get();

        return view('agents.show', compact('agent', 'conversations'));
    }

    public function edit(Agent $agent): View
    {
        $agent->load('skills', 'knowledge');
        $skills = AgentSkill::orderBy('name')->get();
        $knowledge = auth()->user()->currentTeam
            ? auth()->user()->currentTeam->agentKnowledge()->latest()->get()
            : collect();

        return view('agents.edit', compact('agent', 'skills', 'knowledge'));
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer', 'exists:agent_skills,id'],
            'knowledge' => ['nullable', 'array'],
            'knowledge.*' => ['integer', 'exists:agent_knowledge,id'],
        ]);

        $agent->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'model' => $validated['model'] ?? 'claude-sonnet-4-6',
            'is_active' => $request->boolean('is_active'),
        ]);

        $agent->skills()->sync($validated['skills'] ?? []);
        $agent->knowledge()->sync($validated['knowledge'] ?? []);

        return redirect()->route('agents.show', $agent);
    }
}
