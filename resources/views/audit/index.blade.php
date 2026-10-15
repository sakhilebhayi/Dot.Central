<x-app-layout>
    <x-slot name="header">Audit Log</x-slot>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:1rem;margin-bottom:1.5rem;flex-wrap:wrap;">
            <div>
                <h1 style="font-family:var(--font-display);font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Audit Log</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Append-only record of every operational change: who, when, what changed, and why.</p>
            </div>
            @can('export', \App\Models\AuditEntry::class)
            <a href="{{ route('audit.index', array_merge($filters, ['export' => 'csv'])) }}" class="dot-btn dot-btn-ghost">
                <span class="material-symbols-rounded" style="font-size:16px;">download</span> Export CSV
            </a>
            @endcan
        </div>

        <form method="GET" action="{{ route('audit.index') }}" class="dot-card" style="padding:1rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0.75rem;margin-bottom:1.5rem;">
            <select name="control_room_id" class="dot-input">
                <option value="">All control rooms</option>
                @foreach($controlRooms as $room)
                    <option value="{{ $room->id }}" @selected(($filters['control_room_id'] ?? null) == $room->id)>{{ $room->name }}</option>
                @endforeach
            </select>
            <select name="action" class="dot-input">
                <option value="">All actions</option>
                @foreach($actions as $action)
                    <option value="{{ $action }}" @selected(($filters['action'] ?? null) === $action)>{{ $action }}</option>
                @endforeach
            </select>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="dot-input" aria-label="From date" />
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="dot-input" aria-label="To date" />
            <button type="submit" class="dot-btn dot-btn-primary">Filter</button>
        </form>

        <div class="dot-card" style="overflow:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:0.78rem;">
                <thead>
                    <tr style="text-align:left;color:var(--text-muted);font-size:0.75rem;text-transform:uppercase;letter-spacing:0.08em;">
                        <th style="padding:0.75rem 1rem;">When</th>
                        <th style="padding:0.75rem 1rem;">Who</th>
                        <th style="padding:0.75rem 1rem;">Control room</th>
                        <th style="padding:0.75rem 1rem;">Entity</th>
                        <th style="padding:0.75rem 1rem;">Action</th>
                        <th style="padding:0.75rem 1rem;">Changes</th>
                        <th style="padding:0.75rem 1rem;">Reason</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($entries as $entry)
                    <tr style="border-top:1px solid var(--divider);vertical-align:top;">
                        <td style="padding:0.6rem 1rem;white-space:nowrap;" class="metric-val">{{ $entry->occurred_at->format('Y-m-d H:i:s') }}</td>
                        <td style="padding:0.6rem 1rem;">
                            @if($entry->automatic)
                                <span class="dot-badge" style="background:var(--btn-ghost-bg);color:var(--text-muted);">system</span>
                            @else
                                {{ $entry->user?->name ?? '—' }}
                                <div style="font-size:0.75rem;color:var(--text-muted);">{{ $entry->user_role ?? '' }}</div>
                            @endif
                        </td>
                        <td style="padding:0.6rem 1rem;">{{ $entry->controlRoom?->name ?? '—' }}</td>
                        <td style="padding:0.6rem 1rem;">{{ class_basename($entry->entity_type) }} @if($entry->entity_id)<span style="color:var(--text-muted);">#{{ $entry->entity_id }}</span>@endif</td>
                        <td style="padding:0.6rem 1rem;"><span class="dot-badge dot-badge-accent">{{ $entry->action }}</span></td>
                        <td style="padding:0.6rem 1rem;max-width:420px;">
                            @if($entry->changes)
                                @foreach($entry->changes as $field => $change)
                                    <div style="font-family:var(--font-mono);font-size:0.75rem;color:var(--text-secondary);">
                                        <span style="color:var(--text-muted);">{{ $field }}</span>:
                                        @if(is_array($change) && array_key_exists('old', $change))
                                            @if($change['old'] !== null)<s style="color:var(--text-faint);">{{ is_scalar($change['old']) ? $change['old'] : json_encode($change['old']) }}</s> → @endif{{ is_scalar($change['new']) || $change['new'] === null ? ($change['new'] ?? '—') : json_encode($change['new']) }}
                                        @else
                                            {{ json_encode($change) }}
                                        @endif
                                    </div>
                                @endforeach
                            @else
                                —
                            @endif
                        </td>
                        <td style="padding:0.6rem 1rem;color:var(--text-secondary);">{{ $entry->reason ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" style="padding:1.5rem 1rem;color:var(--text-muted);">No audit entries match this filter.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div style="margin-top:1rem;">{{ $entries->links() }}</div>
    </div>
</x-app-layout>
