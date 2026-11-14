@php use App\Support\Format; use App\Support\EquipmentState; @endphp
<div class="dot-page ov" data-ov-root>
    {{-- The 30 s refresh. A sentinel, so it can be paused: Livewire skips a `.visible` poll whose element has no box, and
         the page script hides this one while a field in the detail pane has focus or text in it. --}}
    <span class="ov-poll" wire:poll.30s.visible data-ov-poll aria-hidden="true"></span>

    <h1 class="sr-only">Operational Overview</h1>

    {{-- Head: the state of the room in ONE sentence, the ONE next step, and who/where underneath. --}}
    <section class="ov-row ov-head" aria-labelledby="overview-title">
        <div class="ov-head__main ov-a1">
            @if($room === null)
            <img src="{{ asset('images/logo-light.png') }}" alt="Dot.Central" width="1181" height="886" class="ov-head__logo ov-head__logo--night" />
            <img src="{{ asset('images/logo.png') }}" alt="Dot.Central" width="1181" height="886" class="ov-head__logo ov-head__logo--day" />
            @endif
            <h2 class="ov-head__headline" id="overview-title" data-arrival-headline>{{ $arrival['headline'] }}</h2>
            <p class="ov-head__sub" data-arrival-welcome>{{ $arrival['welcome'] }} {{ $arrival['place'] }}</p>
            @if($room !== null)<p class="ov-head__sub ov-head__state ov-head__state--{{ $arrival['level'] }}" data-arrival-state>{{ $arrival['state'] }}</p>@endif
            @if($room !== null)
            <p class="ov-head__sub">Dot.Mines {{ $connection ? 'synced '.Format::age($connection->last_success_at) : 'not connected' }}@if($lineup) · line-up <span class="dot-badge dot-badge--{{ $lineup->isConfirmed() ? 'ok' : 'warn' }}">{{ $lineup->exists ? $lineup->status : 'NOT STARTED' }}</span> <span class="ov-mono">{{ str_pad((string) $lineupSummary['placed'], 2, '0', STR_PAD_LEFT) }}</span> placed · <span class="ov-mono">{{ str_pad((string) $lineupSummary['free'], 2, '0', STR_PAD_LEFT) }}</span> free · <span class="ov-mono">{{ str_pad((string) $lineupSummary['down'], 2, '0', STR_PAD_LEFT) }}</span> down @endif</p>
            @if($rooms->count() > 1)
            <div class="ov-head__room"><label for="ov-room">Control room</label>
                <select wire:model.live="roomId" id="ov-room" class="dot-input ov-select">@foreach($rooms as $r)<option value="{{ $r->id }}">{{ $r->name }}</option>@endforeach</select></div>
            @endif
            @else
            <p class="ov-head__sub">Dot.Central is the control room for mining shifts: which machines are working, what has stopped, and what needs a decision.</p>
            @endif
            @if($room !== null && $shift && $isCurrent)
            @php
                $tz = $timezone;
                $startL = $shift->starts_at->copy()->timezone($tz);
                $endL = $shift->ends_at->copy()->timezone($tz);
                $span = max(1, $shift->starts_at->diffInSeconds($shift->ends_at));
                $done = max(0, min(100, round($shift->starts_at->diffInSeconds(now(), false) / $span * 100, 1)));
                $hours = max(1, (int) round($span / 3600));
            @endphp
            {{-- The shift window drawn from the real start, end and now. Decoration that is also a reading: it
                 states the elapsed share and carries nothing the sentence above does not. --}}
            <svg class="ov-window ov-a2" viewBox="0 0 600 22" width="600" height="22" role="img" aria-label="Shift window {{ $startL->format('H:i') }} to {{ $endL->format('H:i') }}, {{ (int) $done }} per cent elapsed" preserveAspectRatio="none">
                <rect class="ov-window__base" x="0" y="10" width="600" height="2" />
                <rect class="ov-window__done" x="0" y="9" width="{{ $done * 6 }}" height="4" />
                @for($i = 0; $i <= $hours; $i++)<rect class="ov-window__tick" x="{{ min(599, round($i / $hours * 600)) }}" y="{{ $i % 3 === 0 ? 2 : 5 }}" width="1" height="{{ $i % 3 === 0 ? 18 : 12 }}" />@endfor
            </svg>
            @endif
        </div>
        <div class="ov-head__action ov-a2">
            <a href="{{ $arrival['action']['url'] }}" class="ov-btn ov-btn--primary" data-primary-action="{{ $arrival['action']['key'] }}">{{ $arrival['action']['label'] }}</a>
            <p class="ov-head__why">{{ $arrival['action']['why'] }}</p>
        </div>
    </section>

    @if($room !== null)
    @if($shift)
        @php($controlRoom = $room)
        @include('livewire.overview._path')
    @endif

    {{-- Needs you: ONE queue of exceptions and alerts (left), the selected one's facts and actions (right). --}}
    <div class="ov-split ov-a3 @if($queue->isEmpty()) ov-split--solo @endif" data-ov-needs>
        <section class="ov-queue" aria-labelledby="ov-queue-title">
            <div class="ov-pane-head">
                <h2 class="ov-pane-title" id="ov-queue-title">Needs you <span class="ov-mono">{{ str_pad((string) ($exceptions->count() + $alerts->count()), 2, '0', STR_PAD_LEFT) }}</span></h2>
                @if($queue->isNotEmpty())
                <div class="ov-seg" role="group" aria-label="Show">
                    <button type="button" aria-pressed="true" data-ov-filter="all">All<span class="ov-mono">{{ str_pad((string) $queue->count(), 2, '0', STR_PAD_LEFT) }}</span></button>
                    <button type="button" aria-pressed="false" data-ov-filter="exceptions">Exceptions<span class="ov-mono">{{ str_pad((string) $queue->where('kind', 'exceptions')->count(), 2, '0', STR_PAD_LEFT) }}</span></button>
                    <button type="button" aria-pressed="false" data-ov-filter="alerts">Alerts<span class="ov-mono">{{ str_pad((string) $queue->where('kind', 'alerts')->count(), 2, '0', STR_PAD_LEFT) }}</span></button>
                </div>
                @endif
            </div>
            @if($queue->isEmpty())
            <div class="ov-empty">
                <svg class="ov-rest" viewBox="0 0 160 36" width="160" height="36" aria-hidden="true" focusable="false"><rect class="ov-rest__rail" x="0" y="17" width="160" height="2"/>@for($i = 0; $i < 17; $i++)<rect class="ov-rest__tick" x="{{ $i * 10 - ($i === 16 ? 1 : 0) }}" y="{{ $i % 4 === 0 ? 6 : 11 }}" width="1" height="{{ $i % 4 === 0 ? 24 : 14 }}"/>@endfor<rect class="ov-rest__lamp" x="74" y="12" width="12" height="12" rx="6"/></svg>
                <p><strong>Nothing needs attention on this shift.</strong> No open exceptions and no open alerts in {{ $room->name }}. New ones appear here the moment they are raised.</p>
            </div>
            @else
            <div class="ov-table-wrap">
            <table class="ov-table" role="grid" aria-label="Exceptions and alerts needing attention, most severe first">
                <thead><tr><th scope="col">Level</th><th scope="col">Item</th><th scope="col" class="ov-hide-s">Unit</th><th scope="col" class="ov-hide-m">Raised</th><th scope="col" class="ov-hide-m">Outcome</th></tr></thead>
                <tbody data-ov-rows>
                @foreach($queue as $row)
                    @php($m = $row['model'])
                    @php($isE = $row['kind'] === 'exceptions')
                    @php($sev = $row['rank'] === 0 ? 'crit' : ($row['rank'] === 1 ? 'warn' : 'rev'))
                    <tr class="ov-qrow" wire:key="ov-row-{{ $row['id'] }}" role="row" tabindex="{{ $row['id'] === $selected ? 0 : -1 }}" aria-selected="{{ $row['id'] === $selected ? 'true' : 'false' }}" data-ov-row="{{ $row['id'] }}" data-kind="{{ $row['kind'] }}">
                        <td class="ov-lvl"><span class="ov-sev ov-sev--{{ $sev }}"><span class="ov-lamp" aria-hidden="true"></span>{{ strtoupper(['crit' => 'CRIT', 'warn' => 'WARN', 'rev' => $isE && $m->severity === 'REVIEW' ? 'REV' : 'INFO'][$sev]) }}</span></td>
                        <td><div class="ov-q-title">{{ $m->title }}</div><div class="ov-q-meta">{{ $isE ? 'EXC '.$m->rule_code : 'ALERT · '.$m->source }}</div></td>
                        <td class="ov-mono ov-hide-s">{!! $m->equipment ? e($m->equipment->name) : '<span class="ov-dash">—</span>' !!}</td>
                        <td class="ov-mono ov-hide-m ov-nowrap">{{ Format::age($isE ? $m->raised_at : ($m->source_triggered_at ?? $m->triggered_at)) }}</td>
                        <td class="ov-hide-m"><span class="ov-tag {{ $isE ? 'ov-tag--'.($m->tone() === 'bad' ? 'bad' : ($m->tone() === 'warn' || $m->tone() === 'review' ? 'warn' : '')) : '' }}">{{ $isE ? str_replace('_', ' ', $m->outcome) : strtoupper($m->workflowState()) }}</span></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
            @endif
            <div class="ov-links">
                <a class="ov-link" href="{{ route('exceptions.index', ['room' => $room->id]) }}">All exceptions @if($exceptions->count() > 8)<span class="ov-mono">+{{ $exceptions->count() - 8 }}</span>@endif</a>
                <a class="ov-link" href="{{ route('alerts.index', ['room' => $room->id]) }}">All alerts @if($alerts->count() > 6)<span class="ov-mono">+{{ $alerts->count() - 6 }}</span>@endif</a>
            </div>
        </section>
        @foreach($queue as $row)
            @if($row['kind'] === 'exceptions')
                @include('livewire.overview._exception', ['e' => $row['model'], 'rowId' => $row['id'], 'open' => $row['id'] === $selected])
            @else
                @include('livewire.overview._alert', ['a' => $row['model'], 'rowId' => $row['id'], 'open' => $row['id'] === $selected])
            @endif
        @endforeach
    </div>

    {{-- Fleet: one cell per machine, counts by type, what is stopped now. --}}
    <section class="ov-fleet ov-a4" aria-labelledby="ov-fleet-title">
        <div class="ov-pane-head ov-pane-head--top">
            <h2 class="ov-pane-title" id="ov-fleet-title">Fleet <span class="ov-mono">{{ str_pad((string) $units->count(), 2, '0', STR_PAD_LEFT) }}</span></h2>
            <div class="ov-figs">
                <span>Operating<b>{{ str_pad((string) ($byState['operating'] ?? 0), 2, '0', STR_PAD_LEFT) }}/{{ str_pad((string) $units->count(), 2, '0', STR_PAD_LEFT) }}</b></span>
                <span>Production<b>{{ $hasProduction ? Format::number($totals['production_quantity'] ?? null, 0).' '.($totals['production_unit'] ?? '') : '—' }}</b></span>
                <span>Stoppage<b>{{ $hasReadings ? Format::number(($totals['stoppage_minutes'] ?? 0) / 60, 1).' h' : '—' }}</b></span>
                <a class="ov-link" href="{{ route('control-rooms.fleet', $room) }}">Fleet board</a>
            </div>
        </div>
        <div class="ov-table-wrap">
        <table class="ov-table ov-fleet-table" aria-label="Equipment by type, with one cell per machine">
            <thead><tr><th scope="col">Type</th><th scope="col">Machines</th><th scope="col" class="ov-num ov-hide-p">Units</th><th scope="col" class="ov-num">Operating</th><th scope="col" class="ov-num">Idle</th><th scope="col" class="ov-num">No data</th><th scope="col" class="ov-num">Run h</th><th scope="col" class="ov-num">Loads</th></tr></thead>
            <tbody>
            @forelse($byType as $t)
                <tr wire:key="ov-type-{{ $t['type'] }}">
                    <td class="ov-type">{{ $t['type'] }}</td>
                    <td class="ov-units-cell"><div class="ov-units">
                        @foreach($units->filter(fn ($u) => str_replace('_', ' ', $u['equipment']->machine_type ?? 'unknown') === $t['type'])->sortBy(fn ($u) => $u['equipment']->name) as $u)
                            @php($shape = match ($u['state']) { EquipmentState::Operating => 'op', EquipmentState::Idle => 'idle', EquipmentState::Offline => 'off', EquipmentState::Unavailable => 'down', EquipmentState::Stale => 'stale', default => 'unk' })
                            <a href="{{ route('control-rooms.equipment.show', [$room, $u['equipment']]) }}" class="ov-u ov-u--{{ $shape }}" aria-label="{{ $u['equipment']->name }}, {{ $u['state']->label() }}, last heard {{ Format::age($u['equipment']->last_heard_at) }}" title="{{ $u['equipment']->name }} · {{ $u['state']->label() }} · {{ Format::age($u['equipment']->last_heard_at) }}"><i></i></a>
                        @endforeach
                    </div></td>
                    <td class="ov-num ov-hide-p" data-l="Units">{{ str_pad((string) $t['units'], 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="ov-num" data-l="Operating">{{ str_pad((string) $t['operating'], 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="ov-num" data-l="Idle">{{ str_pad((string) $t['idle'], 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="ov-num" data-l="No data">{{ str_pad((string) $t['unknown'], 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="ov-num" data-l="Run h">{{ $t['run_hours'] === null ? '—' : Format::number($t['run_hours']) }}</td>
                    <td class="ov-num" data-l="Loads">{{ $t['loads'] === null ? '—' : Format::integer($t['loads']) }}</td>
                </tr>
            @empty
                <tr><td colspan="8" class="ov-empty-cell">No machines mirrored. Connect Dot.Mines and every machine in this room appears here as one cell.</td></tr>
            @endforelse
            </tbody>
            @if($units->isNotEmpty())
            <tfoot><tr><td colspan="8"><span class="ov-mono">{{ str_pad((string) $units->count(), 2, '0', STR_PAD_LEFT) }}</span> units · <span class="ov-mono">{{ str_pad((string) ($byState['operating'] ?? 0), 2, '0', STR_PAD_LEFT) }}</span> operating · <span class="ov-mono">{{ str_pad((string) ($byState['idle'] ?? 0), 2, '0', STR_PAD_LEFT) }}</span> idle · <span class="ov-mono">{{ str_pad((string) (($byState['unknown'] ?? 0) + ($byState['stale'] ?? 0)), 2, '0', STR_PAD_LEFT) }}</span> without telemetry @unless($hasReadings)· run hours and loads show a dash until Dot.Mines sends readings for this shift @endunless</td></tr></tfoot>
            @endif
        </table>
        </div>
        @if($units->isNotEmpty())
        <div class="ov-key" aria-label="Key to machine cells">
            <span><span class="ov-u ov-u--op"><i></i></span>Operating</span><span><span class="ov-u ov-u--idle"><i></i></span>Idle</span><span><span class="ov-u ov-u--off"><i></i></span>Offline</span><span><span class="ov-u ov-u--down"><i></i></span>Unavailable</span><span><span class="ov-u ov-u--stale"><i></i></span>Stale</span><span><span class="ov-u ov-u--unk"><i></i></span>No data</span>
        </div>
        @endif
        <div class="ov-sub-head"><span>Stopped now <span class="ov-mono">{{ str_pad((string) $stoppedNow->count(), 2, '0', STR_PAD_LEFT) }}</span></span>@if($shift)<a class="ov-link" href="{{ route('shifts.downtime', $shift) }}">Downtime</a>@endif</div>
        <table class="ov-table ov-stopped" aria-label="Machines reported stopped"><thead class="sr-only"><tr><th>Unit</th><th>State</th><th>Last heard</th></tr></thead><tbody>
            @forelse($stoppedNow as $u)
            <tr wire:key="ov-stopped-{{ $u['equipment']->id }}"><td class="ov-mono"><a class="ov-link ov-link--plain" href="{{ route('control-rooms.equipment.show', [$room, $u['equipment']]) }}">{{ $u['equipment']->name }}</a></td><td><span class="ov-tag ov-tag--{{ $u['state']->tone() === 'bad' ? 'bad' : 'warn' }}">{{ $u['state']->label() }}</span></td><td class="ov-mono">{{ Format::age($u['equipment']->last_heard_at) }}</td></tr>
            @empty
            <tr><td colspan="3" class="ov-empty-cell">No machine is reported stopped.</td></tr>
            @endforelse
        </tbody></table>
    </section>
    @endif
</div>
