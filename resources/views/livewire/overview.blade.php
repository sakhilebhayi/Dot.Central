@php use App\Support\Format; use App\Support\EquipmentState; @endphp
<div class="dot-page" wire:poll.30s>
    @if($room === null)
        <div class="dot-page-head"><div><h1 class="dot-page-head__title">Operational Overview</h1><p class="dot-page-head__lede">You are not assigned to a control room yet.</p></div></div>
    @else
    <div class="dot-page-head">
        <div>
            <h1 class="dot-page-head__title">Operational Overview</h1>
            <p class="dot-page-head__lede">{{ $room->name }} · {{ $shift ? $shift->label().' · '.$shift->status.($isCurrent ? '' : ' (last completed shift)') : 'no shift yet' }} · Dot.Mines {{ $connection ? 'synced '.Format::age($connection->last_success_at) : 'not connected' }}</p>
        </div>
        <div style="display:flex;gap:0.5rem;align-items:center;">
            @if($rooms->count() > 1)
            <select wire:model.live="roomId" class="dot-input" style="width:auto;" aria-label="Control room">@foreach($rooms as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select>
            @endif
            @if($shift && $isCurrent)
                <a href="{{ route('shifts.show', $shift) }}" class="dot-btn dot-btn-ghost">Shift operations</a>
            @else
                @can('open', [\App\Models\Shift::class, $room])<a href="{{ route('control-rooms.shifts.start', $room) }}" class="dot-btn dot-btn-primary">Start shift</a>@endcan
            @endif
            <a href="{{ route('exceptions.index', ['room' => $room->id]) }}" class="dot-btn dot-btn-primary">Exceptions @if($exceptions->count())<span class="nav-count nav-count--bad" style="margin-left:0.4rem;">{{ $exceptions->count() }}</span>@endif</a>
        </div>
    </div>

    @if($shift)
        @php($controlRoom = $room)
        @include('shifts._strip')
    @endif

    <div class="dot-panels dot-panels--6" style="margin-bottom:1.25rem;">
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Operating</span><span class="dot-readout__value dot-readout__value--ok">{{ Format::integer($byState['operating'] ?? 0) }}<span class="dot-readout__unit">/ {{ $units->count() }}</span></span><span class="dot-readout__context">{{ $byState['idle'] ?? 0 }} idle · {{ $byState['offline'] ?? 0 }} offline · {{ $byState['unavailable'] ?? 0 }} unavailable</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Without telemetry</span><span class="dot-readout__value {{ (($byState['unknown'] ?? 0) + ($byState['stale'] ?? 0)) ? 'dot-readout__value--warn' : '' }}">{{ Format::integer(($byState['unknown'] ?? 0) + ($byState['stale'] ?? 0)) }}</span><span class="dot-readout__context">{{ $byState['stale'] ?? 0 }} stale · {{ $byState['unknown'] ?? 0 }} unknown</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Run hours (shift)</span><span class="dot-readout__value">{{ Format::number($totals['run_hours'] ?? null) }}</span><span class="dot-readout__context">{{ $totals['with_reading'] ?? 0 }} of {{ $totals['units'] ?? 0 }} with readings</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Loads (shift)</span><span class="dot-readout__value">{{ Format::integer($totals['loads'] ?? null) }}</span><span class="dot-readout__context">cumulative load counters</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Production (shift)</span><span class="dot-readout__value">{{ Format::number($totals['production_quantity'] ?? null, 0) }}<span class="dot-readout__unit">{{ $totals['production_unit'] ?? '' }}</span></span><span class="dot-readout__context">Dot.Mines records</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Stoppage (shift)</span><span class="dot-readout__value {{ ($totals['stoppage_minutes'] ?? 0) ? 'dot-readout__value--warn' : '' }}">{{ Format::number(($totals['stoppage_minutes'] ?? 0) / 60, 1) }}<span class="dot-readout__unit">h</span></span><span class="dot-readout__context">observed offline / maintenance</span></div>
    </div>

    <div class="dot-panels dot-panels--3" style="margin-bottom:1.25rem;">
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Critical</span><span class="dot-readout__value {{ ($exceptionCounts['CRITICAL'] ?? 0) ? 'dot-readout__value--bad' : 'dot-readout__value--ok' }}">{{ Format::integer($exceptionCounts['CRITICAL'] ?? 0) }}</span><span class="dot-readout__context">missing telemetry / conflicts — block the shift close</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Warning</span><span class="dot-readout__value {{ ($exceptionCounts['WARNING'] ?? 0) ? 'dot-readout__value--warn' : '' }}">{{ Format::integer($exceptionCounts['WARNING'] ?? 0) }}</span><span class="dot-readout__context">suspicious values</span></div>
        <div class="dot-panel dot-readout"><span class="dot-readout__label">Review · Info</span><span class="dot-readout__value">{{ Format::integer($exceptionCounts['REVIEW'] ?? 0) }}<span class="dot-readout__unit">· {{ $exceptionCounts['INFO'] ?? 0 }}</span></span><span class="dot-readout__context">{{ $alerts->count() }} open alerts</span></div>
    </div>

    <div class="dot-split">
        <div class="dot-stack">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Exceptions needing attention</h2><a href="{{ route('exceptions.index', ['room' => $room->id]) }}" class="dot-panel__action">All</a></div>
                <div class="dot-panel__body">
                    @forelse($exceptions->take(8) as $e)
                        @include('exceptions._card', ['e' => $e])
                    @empty
                        <p class="dot-page-head__lede">Nothing needs attention on this shift.</p>
                    @endforelse
                    @if($exceptions->count() > 8)<p class="dot-page-head__lede">+ {{ $exceptions->count() - 8 }} more in <a href="{{ route('exceptions.index', ['room' => $room->id]) }}" style="color:var(--signal);">Exceptions</a></p>@endif
                </div>
            </div>
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Open alerts</h2><a href="{{ route('alerts.index', ['room' => $room->id]) }}" class="dot-panel__action">All</a></div>
                <div class="dot-panel__body">
                    @forelse($alerts->take(6) as $a)
                        @include('alerts._card', ['a' => $a])
                    @empty
                        <p class="dot-page-head__lede">No open alerts.</p>
                    @endforelse
                </div>
            </div>
        </div>
        <div class="dot-stack">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Equipment by type</h2><a href="{{ route('control-rooms.fleet', $room) }}" class="dot-panel__action">Fleet board</a></div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Type</th><th class="num">Units</th><th class="num">Operating</th><th class="num">Idle</th><th class="num">No data</th><th class="num">Run h</th><th class="num">Loads</th></tr></thead><tbody>
                    @forelse($byType as $t)<tr><td>{{ $t['type'] }}</td><td class="num">{{ $t['units'] }}</td><td class="num">{{ $t['operating'] }}</td><td class="num">{{ $t['idle'] }}</td><td class="num">{{ $t['unknown'] }}</td><td class="num">{{ Format::number($t['run_hours']) }}</td><td class="num">{{ Format::integer($t['loads']) }}</td></tr>@empty<tr><td colspan="7" class="dot-ledger__empty">No machines mirrored.</td></tr>@endforelse
                </tbody></table></div>
            </div>
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Stopped now</h2>@if($shift)<a href="{{ route('shifts.downtime', $shift) }}" class="dot-panel__action">Downtime</a>@endif</div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Unit</th><th>State</th><th>Last heard</th></tr></thead><tbody>
                    @forelse($units->filter(fn($u) => in_array($u['state'], [EquipmentState::Offline, EquipmentState::Unavailable], true)) as $u)<tr><td class="dot-ledger__key">{{ $u['equipment']->name }}</td><td><span class="dot-badge dot-badge--{{ $u['state']->tone() }}">{{ $u['state']->label() }}</span></td><td class="dot-ledger__key">{{ Format::age($u['equipment']->last_heard_at) }}</td></tr>@empty<tr><td colspan="3" class="dot-ledger__empty">No machine is reported stopped.</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </div>
    </div>
    @endif
</div>
