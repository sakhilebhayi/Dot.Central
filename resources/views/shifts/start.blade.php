@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · Start shift</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Start Shift</h1>
                <p class="dot-page-head__lede">The next shift is proposed from the room's shift pattern. The fleet, its readings and open items come from Dot.Mines; you only confirm.</p>
            </div>
        </div>
        <x-validation-errors style="margin-bottom:1rem;" />
        @if($current)
            <div class="dot-msg dot-msg--warning"><div class="dot-msg__title">A shift is still open</div><div>{{ $current->label() }} is {{ $current->status }}. <a href="{{ route('shifts.review', $current) }}" style="color:var(--signal);">Close it under End Shift</a> before starting the next one.</div></div>
        @endif

        <div class="dot-panels dot-panels--2">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Shift to open</h2></div>
                <form method="POST" action="{{ route('control-rooms.shifts.store', $controlRoom) }}" class="dot-panel__body" style="display:flex;flex-direction:column;gap:1rem;">
                    @csrf
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;">
                        <div>
                            <x-label for="shift_date" value="Date" />
                            <x-input id="shift_date" name="shift_date" type="date" value="{{ old('shift_date', $proposal['shift_date']) }}" required />
                        </div>
                        <div>
                            <x-label for="shift_type_id" value="Shift" />
                            <select id="shift_type_id" name="shift_type_id" class="dot-input" required>
                                @foreach($shiftTypes as $type)
                                    <option value="{{ $type->id }}" @selected((int) old('shift_type_id', $proposal['shift_type']?->id) === $type->id)>{{ $type->name }} ({{ $type->starts_at }}–{{ $type->ends_at }})</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if($proposal['starts_at'])
                        <p class="dot-readout__context">Proposed window {{ Format::stamp($proposal['starts_at'], $timezone) }} – {{ Format::stamp($proposal['ends_at'], $timezone) }} ({{ $timezone }})</p>
                    @endif
                    <div>
                        <x-button :disabled="$current !== null">Start shift</x-button>
                    </div>
                    <div>
                        <h3 class="dot-readout__label" style="margin-bottom:0.4rem;">Loaded automatically</h3>
                        <ul style="margin:0;padding-left:1.1rem;font-size:var(--t-sm);color:var(--text-quiet);line-height:1.7;">
                            <li>{{ $expectedUnits }} machines mirrored from Dot.Mines for this room</li>
                            <li>Opening engine hours = the last Dot.Mines reading before the shift starts (no typing)</li>
                            <li>Loads, production, stoppages and states are derived from telemetry as the shift runs</li>
                            <li>The previous handover is acknowledged when you start</li>
                            <li>Next step, Shift Line-up: faces copied from the previous shift, trucks with their loading units, operators from the previous shift; machines Dot.Mines reports stopped stay down</li>
                        </ul>
                    </div>
                </form>
            </div>

            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Handover from {{ $previous ? $previous->label() : '—' }}</h2></div>
                <div class="dot-panel__body">
                    @if($previous && $previous->handover)
                        @php($snap = $previous->handover->snapshot)
                        <div class="dot-panels dot-panels--3" style="margin-bottom:1rem;">
                            <div class="dot-panel dot-readout"><span class="dot-readout__label">Run hours</span><span class="dot-readout__value">{{ Format::number($snap['fleet']['run_hours'] ?? null) }}</span></div>
                            <div class="dot-panel dot-readout"><span class="dot-readout__label">Loads</span><span class="dot-readout__value">{{ Format::integer($snap['fleet']['loads'] ?? null) }}</span></div>
                            <div class="dot-panel dot-readout"><span class="dot-readout__label">Production</span><span class="dot-readout__value">{{ Format::number($snap['fleet']['production_quantity'] ?? null, 0) }}</span></div>
                        </div>
                        @if($previous->handover->notes)<p style="font-size:var(--t-body);"><strong>Notes:</strong> {{ $previous->handover->notes }}</p>@endif
                        <h3 class="dot-readout__label">Stopped at shift end ({{ count($snap['fleet']['stopped_now'] ?? []) }})</h3>
                        <p style="font-size:var(--t-sm);color:var(--text-quiet);">{{ collect($snap['fleet']['stopped_now'] ?? [])->map(fn($s) => $s['name'].' ('.$s['state'].')')->implode(', ') ?: 'none' }}</p>
                        <h3 class="dot-readout__label">Without telemetry at shift end ({{ count($snap['fleet']['unknown_now'] ?? []) }})</h3>
                        <p style="font-size:var(--t-sm);color:var(--text-quiet);">{{ implode(', ', $snap['fleet']['unknown_now'] ?? []) ?: 'none' }}</p>
                        <p class="dot-readout__context">Issued {{ Format::stamp($previous->handover->issued_at, $timezone) }}{{ $previous->handover->acknowledged_at ? ' · acknowledged '.Format::stamp($previous->handover->acknowledged_at, $timezone) : ' · will be acknowledged when you start' }}</p>
                    @else
                        <p class="dot-page-head__lede">No previous handover.</p>
                    @endif

                    <h3 class="dot-readout__label" style="margin-top:1rem;">Still down at the previous end ({{ count($outstanding['down']) }})</h3>
                    <p style="font-size:var(--t-sm);color:var(--text-quiet);">{{ collect($outstanding['down'])->map(fn($d) => $d['name'].' ('.$d['cause'].($d['since'] ? ' since '.Format::stamp($d['since'], $timezone) : '').')')->implode(', ') ?: 'none — Dot.Mines reports every machine running or idle' }}</p>
                    @if($outstanding['standby'] !== [])
                        <h3 class="dot-readout__label">On standby at the previous end ({{ count($outstanding['standby']) }})</h3>
                        <p style="font-size:var(--t-sm);color:var(--text-quiet);">{{ collect($outstanding['standby'])->map(fn($s) => $s['name'].' ('.$s['reason'].')')->implode(', ') }}</p>
                    @endif
                    <h3 class="dot-readout__label">Open exceptions carried ({{ $outstanding['exceptions']->count() }})</h3>
                    @if($outstanding['exceptions']->isEmpty())
                        <p style="font-size:var(--t-sm);color:var(--text-quiet);">none</p>
                    @else
                        <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Sev</th><th>Rule</th><th>Unit</th><th>Detail</th></tr></thead><tbody>
                            @foreach($outstanding['exceptions']->take(12) as $e)<tr><td><span class="dot-badge dot-badge--{{ $e->tone() }}">{{ $e->severity }}</span></td><td class="dot-ledger__key">{{ $e->rule_code }}</td><td class="dot-ledger__key">{{ $e->equipment?->name ?? '—' }}</td><td>{{ $e->title }}</td></tr>@endforeach
                        </tbody></table></div>
                        @if($outstanding['exceptions']->count() > 12)<p class="dot-readout__context">+ {{ $outstanding['exceptions']->count() - 12 }} more under <a href="{{ route('exceptions.index', ['room' => $controlRoom->id]) }}" style="color:var(--signal);">Exceptions</a></p>@endif
                    @endif
                    @if($outstanding['faces'] !== [])
                        <h3 class="dot-readout__label">Previous faces ({{ count($outstanding['faces']) }}) — copied on the Line-up</h3>
                        <p style="font-size:var(--t-sm);color:var(--text-quiet);">{{ collect($outstanding['faces'])->map(fn($f) => $f['label'].' ('.(implode(' + ', $f['loaders']) ?: 'no loader').', '.count($f['trucks']).' trucks)')->implode(' · ') }}</p>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
