@php use App\Support\Format; use App\Support\EquipmentState; @endphp
<div class="dot-page" wire:poll.15s>
    <div class="dot-page-head">
        <div>
            <h1 class="dot-page-head__title">Fleet board</h1>
            <p class="dot-page-head__lede">{{ $controlRoom->name }} · where every machine is and what it is doing, from Dot.Mines (last sync {{ Format::age($lastSync) }}). Nothing here is placed by hand; stale or missing telemetry is shown as such.</p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            <div class="dot-segmented" role="group" aria-label="Filter">
                <button type="button" class="dot-btn dot-btn-sm {{ $filter === 'all' ? 'dot-btn-primary' : 'dot-btn-ghost' }}" wire:click="$set('filter', 'all')">All ({{ $total }})</button>
                <button type="button" class="dot-btn dot-btn-sm {{ $filter === 'attention' ? 'dot-btn-primary' : 'dot-btn-ghost' }}" wire:click="$set('filter', 'attention')">Needs attention</button>
            </div>
            <a href="{{ route('control-rooms.equipment.index', $controlRoom) }}" class="dot-btn dot-btn-ghost dot-btn-sm">Equipment ledger</a>
        </div>
    </div>

    <div class="dot-panels dot-panels--6" style="margin-bottom:1.25rem;">
        @foreach([EquipmentState::Operating, EquipmentState::Idle, EquipmentState::Offline, EquipmentState::Unavailable, EquipmentState::Stale, EquipmentState::Unknown] as $state)
            <div class="dot-panel dot-readout">
                <span class="dot-readout__label">{{ $state->label() }}</span>
                <span class="dot-readout__value {{ ($counts[$state->value] ?? 0) > 0 && in_array($state->tone(), ['ok','warn','bad']) ? 'dot-readout__value--'.$state->tone() : '' }}">{{ Format::integer($counts[$state->value] ?? 0) }}</span>
            </div>
        @endforeach
    </div>

    <div class="dot-stack">
    @forelse($groups as $area => $rows)
        <section class="dot-panel dot-panel--flush" aria-label="{{ $area }}">
            <div class="dot-panel__head"><h2 class="dot-panel__title">{{ $area }}</h2><span class="dot-panel__action" style="color:var(--text-faint);">{{ $rows->count() }} machine{{ $rows->count() === 1 ? '' : 's' }}</span></div>
            <div class="dot-panel__body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1px;background:var(--rule);padding:0;">
                @foreach($rows as $row)
                    @php($e = $row['equipment'])
                    @php($tone = $row['state']->tone())
                    <a href="{{ route('control-rooms.equipment.show', [$controlRoom, $e]) }}" style="display:block;background:var(--panel);padding:0.8rem 0.9rem;text-decoration:none;color:inherit;{{ $row['freshness'] !== 'fresh' ? 'opacity:.72;' : '' }}">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:0.5rem;">
                            <span class="dot-ledger__key" style="font-weight:600;">{{ $e->name }}</span>
                            <span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}">{{ $row['state']->label() }}</span>
                        </div>
                        <div style="font-size:var(--t-xs);color:var(--text-faint);margin-top:0.3rem;">{{ $e->machine_type ? str_replace('_', ' ', $e->machine_type) : '—' }} · heard {{ Format::age($e->last_heard_at) }}</div>
                        @if($row['geofence'])
                            <div style="font-size:var(--t-xs);color:var(--text-quiet);margin-top:0.2rem;">{{ $row['geofence']['direction'] ?? '' }} {{ $row['geofence']['geofence']['name'] ?? '' }}</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <div class="dot-panel"><p class="dot-page-head__lede">{{ $filter === 'attention' ? 'Every machine is reporting and operating or idle.' : 'Nothing mirrored yet. Connect the room to Dot.Mines and run a sync.' }}</p></div>
    @endforelse
    </div>
</div>
