@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $shift->label() }}</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">{{ $shift->isClosed() ? 'Shift Handover' : 'End Shift Review' }}</h1>
                <p class="dot-page-head__lede">{{ $shift->label() }} · <span class="dot-badge dot-badge--{{ $shift->isClosed() ? 'ok' : 'info' }}">{{ $shift->status }}</span> · {{ Format::stamp($shift->starts_at, $timezone) }} – {{ Format::stamp($shift->ends_at, $timezone) }}</p>
            </div>
            <div style="display:flex;gap:0.5rem;">
                @if($shift->status === 'CLOSED')
                    @can('approve', $shift)
                    <form method="POST" action="{{ route('shifts.approve', $shift) }}">@csrf<button type="submit" class="dot-btn dot-btn-primary">Approve shift</button></form>
                    @endcan
                @endif
                @if($shift->isClosed())
                    @can('reopen', $shift)
                    <form method="POST" action="{{ route('shifts.reopen', $shift) }}" style="display:flex;gap:0.4rem;">
                        @csrf
                        <input name="reason" class="dot-input" placeholder="Reason to reopen (audited)" required style="width:16rem;" />
                        <button type="submit" class="dot-btn dot-btn-danger">Reopen</button>
                    </form>
                    @endcan
                @else
                    @can('close', $shift)<form method="POST" action="{{ route('shifts.rerun', $shift) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Re-run validation</button></form>@endcan
                    <a href="{{ route('shifts.show', $shift) }}" class="dot-btn dot-btn-ghost">Shift operations</a>
                @endif
            </div>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <x-validation-errors style="margin-bottom:1rem;" />

        @unless($shift->isClosed())
        <div class="dot-panels dot-panels--4" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Blocking (critical, unexplained)</span><span class="dot-readout__value {{ $blocking->isNotEmpty() ? 'dot-readout__value--bad' : 'dot-readout__value--ok' }}">{{ Format::integer($blocking->count()) }}</span><span class="dot-readout__context">{{ $blocking->isNotEmpty() ? 'must be cleared to close' : 'ready to close' }}</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Without telemetry</span><span class="dot-readout__value {{ count($totals['unknown_now'] ?? []) ? 'dot-readout__value--warn' : '' }}">{{ Format::integer(count($totals['unknown_now'] ?? [])) }}</span><span class="dot-readout__context">machines with no usable reading — recorded on the handover</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Readings</span><span class="dot-readout__value">{{ $totals['with_reading'] ?? 0 }}<span class="dot-readout__unit">/ {{ $totals['units'] ?? 0 }}</span></span><span class="dot-readout__context">machines with opening and closing hours</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Stopped now</span><span class="dot-readout__value">{{ Format::integer(count($totals['stopped_now'] ?? [])) }}</span><span class="dot-readout__context">carried into the handover</span></div>
        </div>
        @endunless

        <div class="dot-panels dot-panels--split">
            <div class="dot-stack">
                <div class="dot-panel dot-panel--flush">
                    <div class="dot-panel__head"><h2 class="dot-panel__title">Shift result (derived)</h2></div>
                    <div class="dot-panel__body">@include('shifts._tiles')</div>
                    <div class="dot-table-wrap">
                        <table class="dot-ledger">
                            <thead><tr><th>Unit</th><th>State at end</th><th class="num">Opening h</th><th class="num">Closing h</th><th class="num">Run h</th><th class="num">Loads</th><th class="num">Production</th><th class="num">Stoppage</th></tr></thead>
                            <tbody>
                            @foreach($units as $u)
                                <tr>
                                    <td class="dot-ledger__key">{{ $u['equipment']->name }}</td>
                                    <td>{{ \App\Support\EquipmentState::from($u['state'])->label() }}</td>
                                    <td class="num">{{ Format::number($u['opening_hours']) }}</td>
                                    <td class="num">{{ Format::number($u['closing_hours']) }}</td>
                                    <td class="num">{{ Format::number($u['run_hours']) }}</td>
                                    <td class="num">{{ Format::integer($u['loads']) }}</td>
                                    <td class="num">{{ Format::number($u['production_quantity'], 0) }}</td>
                                    <td class="num">{{ Format::integer($u['stoppage_minutes']) }} min</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="dot-stack">
                @if($shift->isClosed())
                    <div class="dot-panel dot-panel--flush">
                        <div class="dot-panel__head"><h2 class="dot-panel__title">Handover issued</h2></div>
                        <div class="dot-panel__body" style="font-size:var(--t-sm);">
                            <p>Issued {{ Format::stamp($shift->handover?->issued_at, $timezone) }} by {{ $shift->handover?->issuer?->name ?? '—' }}</p>
                            <p>Acknowledged: {{ $shift->handover?->acknowledged_at ? Format::stamp($shift->handover->acknowledged_at, $timezone).' by '.($shift->handover->acknowledger?->name ?? '—') : 'not yet' }}</p>
                            <p><strong>Notes:</strong> {{ $shift->handover?->notes ?? '—' }}</p>
                            @if($shift->handover && $shift->handover->acknowledged_at === null)
                                @can('acknowledge', $shift)
                                <form method="POST" action="{{ route('shifts.acknowledge', $shift) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Acknowledge handover</button></form>
                                @endcan
                            @endif
                        </div>
                    </div>
                @else
                    @can('close', $shift)
                    <div class="dot-panel dot-panel--flush">
                        <div class="dot-panel__head"><h2 class="dot-panel__title">Close shift</h2></div>
                        <form method="POST" action="{{ route('shifts.close', $shift) }}" class="dot-panel__body" style="display:flex;flex-direction:column;gap:0.75rem;">
                            @csrf
                            <x-label for="notes" value="Handover notes" />
                            <textarea id="notes" name="notes" rows="4" class="dot-input" placeholder="What is down, what is pending, what the next shift must know">{{ old('notes', $shift->notes) }}</textarea>
                            <div><x-button>Close shift and issue handover</x-button></div>
                            <p class="dot-readout__context">Closing freezes the derived result; later changes need a supervisor to reopen the shift with a reason and are fully audited.</p>
                        </form>
                    </div>
                    @else
                    <div class="dot-panel"><p class="dot-page-head__lede">The shift operator or supervisor closes the shift. This view is read-only.</p></div>
                    @endcan
                @endif
                @if($blocking->isNotEmpty())
                <div class="dot-panel dot-panel--flush">
                    <div class="dot-panel__head"><h2 class="dot-panel__title">Blocking items</h2></div>
                    <div class="dot-panel__body">
                        @foreach($blocking as $item)
                            <div class="dot-msg dot-msg--critical"><div class="dot-msg__title"><span class="dot-msg__code">{{ $item['code'] }}</span> {{ $item['title'] }}</div><div class="dot-msg__fix">→ <a href="{{ route('exceptions.index', ['shift' => $shift->id, 'sev' => 'CRITICAL']) }}" style="color:var(--signal);">Explain it on Exceptions</a></div></div>
                        @endforeach
                    </div>
                </div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
