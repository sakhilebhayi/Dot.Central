@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $shift->label() }}</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Shift Operations</h1>
                <p class="dot-page-head__lede">{{ $shift->label() }} · <span class="dot-badge dot-badge--info">{{ $shift->status }}</span> · {{ Format::stamp($shift->starts_at, $timezone) }} – {{ Format::stamp($shift->ends_at, $timezone) }} · {{ Format::number(max(0, $shift->starts_at->diffInMinutes($shift->effectiveEnd(), true)) / 60, 1) }} h elapsed</p>
            </div>
            <a href="{{ route('shifts.review', $shift) }}" class="dot-btn dot-btn-primary">End shift →</a>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif

        @include('shifts._strip')
        @include('shifts._tiles')

        <div class="dot-panel dot-panel--flush" style="margin-bottom:1.25rem;">
            <div class="dot-panel__head"><h2 class="dot-panel__title">Faces (line-up) · <span class="dot-badge dot-badge--{{ $lineupStatus->isConfirmed() ? 'ok' : 'warn' }}">{{ $lineupStatus->exists ? $lineupStatus->status : 'NOT STARTED' }}</span></h2><a href="{{ route('shifts.lineup', $shift) }}" class="dot-panel__action">Open line-up</a></div>
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>#</th><th>Material / work</th><th>Loading units</th><th>Trucks</th><th>Support</th><th>Cut / blocks</th></tr></thead>
                    <tbody>
                    @forelse($lineupFaces as $row)
                        <tr><td class="num">{{ $row['face']->seq }}</td><td>{{ $row['face']->what() }}</td><td>{{ implode(' + ', $row['loaders']) ?: '—' }}</td><td>{{ count($row['trucks']) }}{{ $row['trucks'] ? ': '.implode(', ', $row['trucks']) : '' }}</td><td>{{ implode(', ', $row['support']) ?: '—' }}</td><td class="dot-ledger__key">{{ trim(($row['face']->cut ?? '').' '.implode(' ', $row['face']->blocks ?? [])) ?: '—' }}</td></tr>
                    @empty
                        <tr><td colspan="6" class="dot-ledger__empty">No faces yet — build them on the Shift Line-up.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        <div class="dot-panels dot-panels--split">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Machines needing attention</h2><a href="{{ route('control-rooms.fleet', $controlRoom) }}" class="dot-panel__action">Fleet board</a></div>
                <div class="dot-table-wrap">
                    <table class="dot-ledger">
                        <thead><tr><th>Unit</th><th>State</th><th>Last heard</th><th class="num">Run h</th><th class="num">Stoppage</th></tr></thead>
                        <tbody>
                        @forelse($units->filter(fn($u) => in_array($u['state'], ['offline','unavailable','stale','unknown'], true) || ($u['stoppage_minutes'] ?? 0) > 0)->take(12) as $u)
                            @php($tone = \App\Support\EquipmentState::from($u['state'])->tone())
                            <tr>
                                <td class="dot-ledger__key"><a href="{{ route('control-rooms.equipment.show', [$controlRoom, $u['equipment']]) }}" style="color:inherit;text-decoration:none;">{{ $u['equipment']->name }}</a></td>
                                <td><span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}">{{ \App\Support\EquipmentState::from($u['state'])->label() }}</span></td>
                                <td class="dot-ledger__key">{{ Format::age($u['equipment']->last_heard_at) }}</td>
                                <td class="num">{{ Format::number($u['run_hours']) }}</td>
                                <td class="num">{{ Format::integer($u['stoppage_minutes']) }} min</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="dot-ledger__empty">Every machine is reporting and operating or idle.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="dot-stack">
                <div class="dot-panel dot-panel--flush">
                    <div class="dot-panel__head"><h2 class="dot-panel__title">Open alerts</h2><a href="{{ route('control-rooms.show', $controlRoom) }}" class="dot-panel__action">All</a></div>
                    <div class="dot-panel__body">
                        @forelse($alerts as $alert)
                            <div style="display:flex;gap:0.6rem;align-items:baseline;padding:0.4rem 0;border-top:1px solid var(--rule);font-size:var(--t-sm);">
                                <span class="dot-badge dot-badge--{{ match($alert->severity) { 'critical' => 'bad', 'warning' => 'warn', default => 'info' } }}">{{ $alert->severity }}</span>
                                <span style="flex:1;">{{ $alert->title }} <span style="color:var(--text-faint);">· {{ $alert->source }}</span></span>
                                <span class="dot-ledger__key" style="color:var(--text-faint);">{{ Format::age($alert->source_triggered_at ?? $alert->triggered_at) }}</span>
                            </div>
                        @empty
                            <p class="dot-page-head__lede">None open.</p>
                        @endforelse
                    </div>
                </div>
                <div class="dot-panel dot-panel--flush">
                    <div class="dot-panel__head"><h2 class="dot-panel__title">Latest stoppages observed</h2><a href="{{ route('shifts.downtime', $shift) }}" class="dot-panel__action">Downtime</a></div>
                    <div class="dot-panel__body">
                        @forelse($recentStoppages as $event)
                            <div style="display:flex;gap:0.6rem;padding:0.4rem 0;border-top:1px solid var(--rule);font-size:var(--t-sm);">
                                <span class="dot-ledger__key">{{ $event->equipment?->name }}</span>
                                <span style="flex:1;color:var(--text-quiet);">{{ $event->from_value ?? '—' }} → {{ $event->to_value }}</span>
                                <span class="dot-ledger__key" style="color:var(--text-faint);">{{ Format::stamp($event->observed_at, $timezone) }}</span>
                            </div>
                        @empty
                            <p class="dot-page-head__lede">No stoppages observed this shift.</p>
                        @endforelse
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
