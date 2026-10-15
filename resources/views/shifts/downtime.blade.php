@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $shift->label() }} · Downtime</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Downtime</h1>
                <p class="dot-page-head__lede">{{ $shift->label() }} · stoppages are observed from Dot.Mines status changes (offline, maintenance). The control room's only input is the reason.</p>
            </div>
            <a href="{{ route('shifts.show', $shift) }}" class="dot-btn dot-btn-ghost">Shift operations</a>
        </div>
        @include('shifts._strip')
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <x-validation-errors style="margin-bottom:1rem;" />

        <div class="dot-panels dot-panels--3" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Stoppage this shift</span><span class="dot-readout__value">{{ Format::number($stoppages->sum('minutes') / 60, 1) }}<span class="dot-readout__unit">h</span></span><span class="dot-readout__context">{{ $stoppages->count() }} intervals observed</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Unclassified</span><span class="dot-readout__value {{ $stoppages->whereNull('classification')->count() ? 'dot-readout__value--warn' : '' }}">{{ Format::integer($stoppages->whereNull('classification')->count()) }}</span><span class="dot-readout__context">need a reason</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Still stopped</span><span class="dot-readout__value {{ $stoppages->where('open', true)->count() ? 'dot-readout__value--bad' : '' }}">{{ Format::integer($stoppages->where('open', true)->count()) }}</span><span class="dot-readout__context">at this moment</span></div>
        </div>

        <div class="dot-panel dot-panel--flush">
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>Unit</th><th>Observed</th><th>Stopped</th><th>Resumed</th><th class="num">Minutes</th><th>Reason</th><th>Note</th>@if($canClassify)<th></th>@endif</tr></thead>
                    <tbody>
                    @forelse($stoppages as $i => $s)
                        <tr>
                            <td class="dot-ledger__key">{{ $s['equipment']->name }}</td>
                            <td><span class="dot-badge dot-badge--{{ $s['cause'] === 'offline' ? 'bad' : 'warn' }}">{{ $s['cause'] }}</span></td>
                            <td class="dot-ledger__key">{{ Format::stamp($s['started_at'], $timezone) }}</td>
                            <td class="dot-ledger__key">{{ $s['open'] ? 'still stopped' : Format::stamp($s['ended_at'], $timezone) }}</td>
                            <td class="num">{{ Format::integer($s['minutes']) }}</td>
                            <td>{{ $s['classification']?->reason?->name ?? '—' }}</td>
                            <td style="max-width:22rem;">{{ $s['classification']?->note ?? '—' }}</td>
                            @if($canClassify)
                            <td>
                                @if($s['classification'] === null)
                                <details>
                                    <summary class="dot-btn dot-btn-ghost dot-btn-sm" style="list-style:none;cursor:pointer;">Classify</summary>
                                    <form method="POST" action="{{ route('shifts.downtime.classify', $shift) }}" style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.5rem;min-width:16rem;">
                                        @csrf
                                        <input type="hidden" name="equipment_id" value="{{ $s['equipment']->id }}" />
                                        <input type="hidden" name="started_at" value="{{ $s['started_at']->copy()->setTimezone($timezone)->format('Y-m-d\TH:i') }}" />
                                        @unless($s['open'])<input type="hidden" name="ended_at" value="{{ $s['ended_at']->copy()->setTimezone($timezone)->format('Y-m-d\TH:i') }}" />@endunless
                                        <select name="downtime_reason_id" class="dot-input" required>
                                            @foreach($reasons as $reason)<option value="{{ $reason->id }}">{{ $reason->name }}</option>@endforeach
                                        </select>
                                        <input name="note" class="dot-input" placeholder="What happened (required for breakdowns)" />
                                        <button type="submit" class="dot-btn dot-btn-primary dot-btn-sm">Save reason</button>
                                    </form>
                                </details>
                                @endif
                            </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="8" class="dot-ledger__empty">No stoppage observed in this window.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
