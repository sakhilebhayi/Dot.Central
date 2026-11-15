<x-app-layout>
    {{-- Agents landing (route agents.hub). Same ruled-sheet language as the Overview. This page is a catalogue, not a
         control-room dashboard: agents are the current team's (Agent carries the team scope), skills are shared
         (AgentSkill has no owner), and conversations, messages and tokens are the signed-in person's own. The copy says
         exactly that: the six tiles it replaces read as team-wide figures. --}}
    <div class="dot-page ov ov-hub" data-ov-root>
        <h1 class="sr-only">Agent Hub Dashboard</h1>
        <section class="ov-row ov-head ov-head--hub" aria-labelledby="ag-headline">
            <div class="ov-head__main ov-a1">
                <h2 class="ov-head__headline" id="ag-headline">{{ $totalAgents }} {{ \Illuminate\Support\Str::plural('agent', $totalAgents) }}, {{ $activeAgents }} active.</h2>
                <p class="ov-head__sub">These are the agents of {{ auth()->user()->currentTeam?->name ?? 'your team' }}. Skills are shared by every team. Conversations, messages and tokens count only your own.</p>
            </div>
            <div class="ov-head__action ov-a2">
                <a href="{{ route('agents.create') }}" class="ov-btn ov-btn--primary" data-primary-action="new-agent">New agent</a>
            </div>
        </section>

        <div class="ov-split ov-a3">
            <section class="ov-queue" aria-labelledby="ag-title">
                <div class="ov-pane-head">
                    <h2 class="ov-pane-title" id="ag-title">Agents <span class="ov-mono">{{ str_pad((string) $agents->count(), 2, '0', STR_PAD_LEFT) }}</span></h2>
                    <a class="ov-link ov-pane-link" href="{{ route('agents.index') }}">Manage all agents</a>
                </div>
                @if($agents->isEmpty())
                <div class="ov-empty">
                    <p><strong>No agents yet.</strong> An agent is a Claude-backed persona with a system prompt and a set of skills. Create one and it appears here.</p>
                </div>
                @else
                <div class="ov-table-wrap">
                <table class="ov-table" aria-label="Agents">
                    <thead><tr><th scope="col">Agent</th><th scope="col" class="ov-hide-s">Model</th><th scope="col">Status</th><th scope="col" class="ov-num ov-hide-m">Your conversations</th><th scope="col" class="ov-num"><span class="sr-only">Open</span></th></tr></thead>
                    <tbody>
                    @foreach($agents as $agent)
                        <tr>
                            <td><div class="ov-q-title">{{ $agent->name }}</div>@if($agent->description)<div class="ov-q-sub">{{ $agent->description }}</div>@endif</td>
                            <td class="ov-mono ov-hide-s">{{ $agent->model ?? '—' }}</td>
                            <td><span class="ov-tag {{ $agent->is_active ? 'ov-tag--ok' : '' }}">{{ $agent->is_active ? 'Active' : 'Inactive' }}</span></td>
                            <td class="ov-num ov-hide-m">{{ str_pad((string) $agent->conversations_count, 2, '0', STR_PAD_LEFT) }}</td>
                            <td class="ov-num"><a class="ov-link" href="{{ route('agents.show', $agent) }}" aria-label="Open {{ $agent->name }}">Open</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                </div>
                @endif
                <div class="ov-key ov-key--totals">Yours: <span class="ov-mono">{{ number_format($totalConversations) }}</span> conversations · <span class="ov-mono">{{ number_format($totalMessages) }}</span> messages · <span class="ov-mono">{{ number_format($totalTokens) }}</span> tokens used</div>
            </section>
            <section class="ov-detail" aria-labelledby="sk-title">
                <div class="ov-pane-head"><h2 class="ov-pane-title" id="sk-title">Skills <span class="ov-mono">{{ str_pad((string) $totalSkills, 2, '0', STR_PAD_LEFT) }}</span></h2></div>
                @if($skills->isEmpty())
                <div class="ov-empty"><p><strong>No skills defined yet.</strong> A skill is attached to an agent on the agent page.</p></div>
                @else
                <dl class="ov-kv ov-kv--flush">
                    @foreach($skills as $skill)<dt>{{ $skill->name }}</dt><dd class="ov-mono">{{ str_pad((string) $skill->agents_count, 2, '0', STR_PAD_LEFT) }} {{ \Illuminate\Support\Str::plural('agent', $skill->agents_count) }}</dd>@endforeach
                </dl>
                <p class="ov-readonly">Skills are attached to agents on the agent page.</p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
