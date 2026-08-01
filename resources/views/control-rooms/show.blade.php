<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:#f4f4f5;margin:0;">{{ $controlRoom->name }}</h1>
            <a href="{{ route('control-rooms.edit', $controlRoom) }}" style="font-size:0.75rem;color:#71717a;text-decoration:none;">Edit →</a>
        </div>
        <p style="font-size:0.8rem;color:#71717a;margin:0 0 2rem;">
            Mines site: {{ $controlRoom->mines_site_ref ?? '—' }} &middot;
            {{ $controlRoom->is_active ? 'Active' : 'Inactive' }}
        </p>

        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:1.5rem;">

            {{-- Dispatch decisions --}}
            <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Dispatch Decisions</h2>

                <form method="POST" action="{{ route('control-rooms.dispatch-decisions.store', $controlRoom) }}" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <select name="workflow_type" required style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;">
                        @foreach($workflowTypes as $type)
                            <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                        @endforeach
                    </select>
                    <input type="datetime-local" name="decided_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="text" name="summary" placeholder="Summary (optional)" style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Log Decision</button>
                </form>

                @forelse($controlRoom->dispatchDecisions as $decision)
                    <div style="border-top:1px solid rgba(255,255,255,0.06);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:#f4f4f5;font-weight:600;">#{{ $decision->sequence }} — {{ ucfirst($decision->workflow_type) }}</div>
                            <div style="font-size:0.68rem;color:#71717a;">{{ $decision->decided_at->format('M j, H:i') }}@if($decision->summary) — {{ $decision->summary }}@endif</div>
                        </div>
                        <form method="POST" action="{{ route('dispatch-decisions.destroy', $decision) }}" onsubmit="return confirm('Delete this decision?');">
                            @csrf @method('DELETE')
                            <button type="submit" style="background:none;border:none;color:#71717a;cursor:pointer;font-size:0.7rem;">✕</button>
                        </form>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:#71717a;">No decisions logged yet.</p>
                @endforelse
            </div>

            {{-- Alerts --}}
            <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Alerts</h2>

                <form method="POST" action="{{ route('control-rooms.alerts.store', $controlRoom) }}" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <select name="severity" required style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;">
                        @foreach($severities as $severity)
                            <option value="{{ $severity }}">{{ ucfirst($severity) }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="title" placeholder="Title" required style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="datetime-local" name="triggered_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Raise Alert</button>
                </form>

                @forelse($controlRoom->alerts as $alert)
                    <div style="border-top:1px solid rgba(255,255,255,0.06);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:#f4f4f5;font-weight:600;">{{ ucfirst($alert->severity) }} — {{ $alert->title }}</div>
                            <div style="font-size:0.68rem;color:#71717a;">{{ $alert->triggered_at->format('M j, H:i') }} @if($alert->cleared_at) — cleared @endif</div>
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
                                <button type="submit" style="background:none;border:none;color:#71717a;cursor:pointer;font-size:0.7rem;">✕</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:#71717a;">No alerts yet.</p>
                @endforelse
            </div>

            {{-- Operator sessions --}}
            <div style="background:#141416;border:1px solid rgba(255,255,255,0.07);border-radius:0.875rem;padding:1.25rem;">
                <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:#f4f4f5;margin:0 0 1rem;">Operator Sessions</h2>

                <form method="POST" action="{{ route('control-rooms.operator-sessions.store', $controlRoom) }}" style="margin-bottom:1.25rem;display:flex;flex-direction:column;gap:0.5rem;">
                    @csrf
                    <input type="number" name="user_id" placeholder="User ID" required style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="text" name="shift_label" placeholder="Shift label (e.g. day)" required style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <input type="datetime-local" name="started_at" required value="{{ now()->format('Y-m-d\TH:i') }}" style="background:#1c1c1f;border:1px solid rgba(255,255,255,0.1);color:#f4f4f5;border-radius:0.4rem;padding:0.45rem 0.6rem;font-size:0.78rem;" />
                    <button type="submit" style="background:#e11d48;color:#fff;border:none;border-radius:0.4rem;padding:0.5rem;font-size:0.75rem;font-weight:700;cursor:pointer;">Start Session</button>
                </form>

                @forelse($controlRoom->operatorSessions as $session)
                    <div style="border-top:1px solid rgba(255,255,255,0.06);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
                        <div>
                            <div style="font-size:0.78rem;color:#f4f4f5;font-weight:600;">{{ $session->operator->name ?? 'User #'.$session->user_id }} — {{ $session->shift_label }}</div>
                            <div style="font-size:0.68rem;color:#71717a;">{{ $session->started_at->format('M j, H:i') }} @if($session->ended_at) → {{ $session->ended_at->format('H:i') }} @endif</div>
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
                                <button type="submit" style="background:none;border:none;color:#71717a;cursor:pointer;font-size:0.7rem;">✕</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p style="font-size:0.75rem;color:#71717a;">No operator sessions yet.</p>
                @endforelse
            </div>

        </div>
    </div>
</x-app-layout>
