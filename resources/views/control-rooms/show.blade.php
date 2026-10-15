<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-primary);margin:0;">{{ $controlRoom->name }}</h1>
            @can('update', $controlRoom)
            <a href="{{ route('control-rooms.edit', $controlRoom) }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">Edit →</a>
            @endcan
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 2rem;display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
            <span>{{ $controlRoom->is_active ? 'Active' : 'Inactive' }}</span>
            <span>&middot;</span>
            @if($controlRoom->minesConnection)
                <span>Dot.Mines: team #{{ $controlRoom->minesConnection->mines_team_id ?? '—' }} · <span class="dot-badge dot-badge-accent">{{ $controlRoom->minesConnection->status }}</span></span>
            @else
                <span>Dot.Mines: not connected</span>
            @endif
            @can('manage', $controlRoom)
                <a href="{{ route('control-rooms.connection.edit', $controlRoom) }}" style="color:var(--accent);text-decoration:none;">Configure connection →</a>
            @endcan
        </p>
        @if (session('status'))
            <div style="margin:-1rem 0 1.5rem;color:#22c55e;font-size:0.8rem;">{{ session('status') }}</div>
        @endif

        @if($pendingProposals->isNotEmpty())
        <div style="background:var(--card-bg);border:1px solid #f59e0b;border-radius:0.875rem;padding:1.25rem;margin-bottom:1.5rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Awaiting Review</h2>
            @foreach($pendingProposals as $proposal)
                <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                    <div>
                        <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">{{ $proposal->operatorSession->operator->name ?? 'User #'.$proposal->operatorSession->user_id }} — {{ $proposal->operatorSession->shift_label }}</div>
                        <div style="font-size:0.68rem;color:var(--text-muted);">No dispatch activity for {{ $proposal->hours_silent }}h</div>
                    </div>
                    <div style="display:flex;gap:0.4rem;">
                        <form method="POST" action="{{ route('stale-session-proposals.end', $proposal) }}">
                            @csrf @method('PATCH')
                            <button type="submit" style="background:none;border:none;color:#e11d48;cursor:pointer;font-size:0.68rem;">End Session</button>
                        </form>
                        <form method="POST" action="{{ route('stale-session-proposals.dismiss', $proposal) }}">
                            @csrf @method('PATCH')
                            <button type="submit" style="background:none;border:none;color:#22c55e;cursor:pointer;font-size:0.68rem;">Dismiss</button>
                        </form>
                    </div>
                </div>
            @endforeach
        </div>
        @endif

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;">

            {{-- Dispatch decisions --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Dispatch Decisions</h2>

                <form method="POST" action="{{ route('control-rooms.dispatch-decisions.store', $controlRoom) }}" onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.innerText='Logging…';},0);" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <select name="workflow_type" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;">
                        @foreach($workflowTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    <input type="datetime-local" name="decided_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="text" name="summary" placeholder="Summary (optional)" style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Log Decision</button>
                </form>

                @forelse($controlRoom->dispatchDecisions as $decision)
                    <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">#{{ $decision->sequence }} — {{ ucfirst($decision->workflow_type) }}</div>
                            <div style="font-size:0.68rem;color:var(--text-muted);">{{ $decision->decided_at->format('M j, H:i') }}@if($decision->summary) — {{ $decision->summary }}@endif</div>
                        </div>
                        <form method="POST" action="{{ route('dispatch-decisions.destroy', $decision) }}" onsubmit="return confirm('Delete this decision?');">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.7rem;">✕</button>
                        </form>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:var(--text-muted);">No decisions logged yet.</p>
                @endforelse
            </div>

            {{-- Alerts --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Alerts</h2>

                <form method="POST" action="{{ route('control-rooms.alerts.store', $controlRoom) }}" onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.innerText='Raising…';},0);" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <select name="severity" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;">
                        @foreach($severities as $severity)
                            <option value="{{ $severity }}">{{ ucfirst($severity) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="title" placeholder="Title" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="datetime-local" name="triggered_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Raise Alert</button>
                </form>

                @forelse($controlRoom->alerts as $alert)
                    <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">{{ ucfirst($alert->severity) }} — {{ $alert->title }}</div>
                            <div style="font-size:0.68rem;color:var(--text-muted);">{{ $alert->triggered_at->format('M j, H:i') }} @if($alert->cleared_at) — cleared @endif</div>
                        </div>
                        <div style="display:flex;gap:0.4rem;">
                            @unless($alert->cleared_at)
                            <form method="POST" action="{{ route('alerts.update', $alert) }}">
                                @csrf @method('PATCH')
                                <button type="submit" style="background:none;border:none;color:#22c55e;cursor:pointer;font-size:0.68rem;">Clear</button>
                            </form>
                            @endunless
                            <form method="POST" action="{{ route('alerts.destroy', $alert) }}" onsubmit="return confirm('Delete this alert?');">
                                @csrf @method('DELETE')
                                <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.7rem;">✕</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:var(--text-muted);">No alerts yet.</p>
                @endforelse
            </div>

            {{-- Operator sessions --}}
            <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Operator Sessions</h2>

                <form method="POST" action="{{ route('control-rooms.operator-sessions.store', $controlRoom) }}" onsubmit="var b=this.querySelector('button[type=submit]');setTimeout(function(){b.disabled=true;b.innerText='Starting…';},0);" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <select name="user_id" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;">
                        @foreach($members as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="shift_label" placeholder="Shift label (e.g. day)" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="datetime-local" name="started_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Start Session</button>
                </form>

                @forelse($controlRoom->operatorSessions as $session)
                    <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">{{ $session->operator->name ?? 'User #'.$session->user_id }} — {{ $session->shift_label }}</div>
                            <div style="font-size:0.68rem;color:var(--text-muted);">{{ $session->started_at->format('M j, H:i') }} @if($session->ended_at) → {{ $session->ended_at->format('H:i') }} @endif</div>
                        </div>
                        <div style="display:flex;gap:0.4rem;">
                            @unless($session->ended_at)
                            <form method="POST" action="{{ route('operator-sessions.update', $session) }}">
                                @csrf @method('PATCH')
                                <button type="submit" style="background:none;border:none;color:#22c55e;cursor:pointer;font-size:0.68rem;">End</button>
                            </form>
                            @endunless
                            <form method="POST" action="{{ route('operator-sessions.destroy', $session) }}" onsubmit="return confirm('Delete this session?');">
                                @csrf @method('DELETE')
                                <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.7rem;">✕</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:var(--text-muted);">No operator sessions yet.</p>
                @endforelse
            </div>

        </div>
        {{-- Members (site scope) --}}
        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;padding:1.25rem;margin-top:1.5rem;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 0.25rem;">Members</h2>
            <p style="font-size:0.72rem;color:var(--text-muted);margin:0 0 1rem;">Operators and supervisors see only the rooms they are assigned to. Managers and administrators see every room of the team.</p>

            @can('manage', $controlRoom)
                @if($assignableUsers->isNotEmpty())
                <form method="POST" action="{{ route('control-rooms.members.store', $controlRoom) }}" style="display:flex;gap:0.5rem;margin-bottom:1rem;">
                    @csrf
                    <select name="user_id" required style="background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;flex:1;">
                        @foreach($assignableUsers as $candidate)
                            <option value="{{ $candidate->id }}">{{ $candidate->name }} ({{ $candidate->email }})</option>
                        @endforeach
                    </select>
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem 0.9rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Assign</button>
                </form>
                @endif
            @endcan

            @forelse($members as $member)
                <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                    <div>
                        <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">{{ $member->name }}</div>
                        <div style="font-size:0.68rem;color:var(--text-muted);">{{ $member->roleOn($controlRoom->team)?->label() ?? 'No role' }}</div>
                    </div>
                    @can('manage', $controlRoom)
                    <form method="POST" action="{{ route('control-rooms.members.destroy', [$controlRoom, $member]) }}" onsubmit="return confirm('Remove this member from the control room?');">
                        @csrf @method('DELETE')
                        <button type="submit" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:0.7rem;">Remove</button>
                    </form>
                    @endcan
                </div>
            @empty
                <p style="font-size:0.75rem;color:var(--text-muted);">Nobody is assigned to this room yet.</p>
            @endforelse
        </div>
    </div>
</x-app-layout>
