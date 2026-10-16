@php use App\Support\Format; use App\Support\EquipmentState; use App\Support\RoleClass; @endphp
<div class="dot-page" wire:poll.15s>
    <div class="dot-page-head">
        <div>
            <h1 class="dot-page-head__title">Fleet board</h1>
            <p class="dot-page-head__lede">{{ $controlRoom->name }} · which machines are available for operating and what issues each one carries, from Dot.Mines (last sync {{ Format::age($lastSync) }}){{ $shift ? ' and the line-up of '.$shift->label() : ' — no open shift, so no line-up' }}. Stale or missing telemetry is shown as such; nothing here is typed.</p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;">
            <div class="dot-segmented" role="group" aria-label="Filter">
                @foreach(['all' => 'All ('.$total.')', 'available' => 'Available', 'free' => 'Free', 'attention' => 'Needs attention'] as $key => $label)
                    <button type="button" class="dot-btn dot-btn-sm {{ $filter === $key ? 'dot-btn-primary' : 'dot-btn-ghost' }}" wire:click="$set('filter', '{{ $key }}')">{{ $label }}</button>
                @endforeach
            </div>
            @if($shift)<a href="{{ route('shifts.lineup', $shift) }}" class="dot-btn dot-btn-ghost dot-btn-sm">Line-up</a>@endif
            <a href="{{ route('control-rooms.equipment.index', $controlRoom) }}" class="dot-btn dot-btn-ghost dot-btn-sm">Equipment ledger</a>
        </div>
    </div>

    @if($shift)
    <div class="dot-panels dot-panels--5" style="margin-bottom:1rem;">
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Available for operating</span><span class="dot-readout__value dot-readout__value--ok">{{ Format::integer($availability['available']) }}<span class="dot-readout__unit">/ {{ $total }}</span></span><span class="dot-readout__context">not reported stopped by Dot.Mines</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Placed</span><span class="dot-readout__value">{{ $availability['placed'] }}</span><span class="dot-readout__context">on a face or work area</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Free</span><span class="dot-readout__value {{ $availability['free'] ? 'dot-readout__value--warn' : '' }}">{{ $availability['free'] }}</span><span class="dot-readout__context">available, no place, no reason</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Standby</span><span class="dot-readout__value">{{ $availability['standby'] }}</span><span class="dot-readout__context">standby · no operator · off site</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Down</span><span class="dot-readout__value {{ $availability['down'] ? 'dot-readout__value--bad' : '' }}">{{ $availability['down'] }}</span><span class="dot-readout__context">offline / maintenance / classified breakdown</span></div>
    </div>
    @endif

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
            <div class="dot-panel__body" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:1px;background:var(--rule);padding:0;">
                @foreach($rows as $row)
                    @php($e = $row['equipment'])
                    @php($tone = $row['state']->tone())
                    <a href="{{ route('control-rooms.equipment.show', [$controlRoom, $e]) }}" style="display:block;background:var(--panel);padding:0.8rem 0.9rem;text-decoration:none;color:inherit;{{ $row['freshness'] !== 'fresh' ? 'opacity:.78;' : '' }}{{ ! $row['available'] ? 'box-shadow:inset 3px 0 0 var(--bad);' : '' }}">
                        <div style="display:flex;justify-content:space-between;align-items:baseline;gap:0.5rem;">
                            <span class="dot-ledger__key" style="font-weight:600;">{{ $e->name }}</span>
                            <span class="dot-badge dot-badge--{{ $tone === 'neutral' ? 'info' : $tone }}">{{ $row['state']->label() }}</span>
                        </div>
                        <div style="font-size:var(--t-xs);color:var(--text-faint);margin-top:0.3rem;">{{ RoleClass::label($row['role_class']) }} · heard {{ Format::age($e->last_heard_at) }}</div>
                        @if($row['lineup'])
                            <div style="font-size:var(--t-xs);color:var(--text-quiet);margin-top:0.2rem;">
                                {{ $row['available'] ? 'Available' : 'Not available' }} · {{ match($row['lineup']) { 'PLACED' => $row['face'], 'STANDBY' => 'standby: '.$row['standby'], 'DOWN' => 'down', default => 'free — not on the line-up' } }}
                                @if($row['operator']) · {{ $row['operator']->name() }}@endif
                            </div>
                        @endif
                        @if($row['geofence'])
                            <div style="font-size:var(--t-xs);color:var(--text-quiet);margin-top:0.2rem;">{{ $row['geofence']['direction'] ?? '' }} {{ $row['geofence']['geofence']['name'] ?? '' }}</div>
                        @endif
                        @if($row['issues'] !== [])
                            <ul style="margin:0.4rem 0 0;padding-left:1rem;font-size:var(--t-xs);color:var(--warn);line-height:1.5;">
                                @foreach(array_slice($row['issues'], 0, 4) as $issue)<li>{{ $issue }}</li>@endforeach
                                @if(count($row['issues']) > 4)<li>+ {{ count($row['issues']) - 4 }} more</li>@endif
                            </ul>
                        @else
                            <div style="font-size:var(--t-xs);color:var(--ok);margin-top:0.4rem;">no open issues</div>
                        @endif
                    </a>
                @endforeach
            </div>
        </section>
    @empty
        <div class="dot-panel"><p class="dot-page-head__lede">{{ $filter === 'attention' ? 'Every machine is reporting, available and without open issues.' : ($filter === 'free' ? 'Every machine has a place or a reason.' : ($filter === 'available' ? 'No machine is available: Dot.Mines reports every machine stopped.' : 'Nothing mirrored yet. Connect the room to Dot.Mines and run a sync.')) }}</p></div>
    @endforelse
    </div>
</div>
