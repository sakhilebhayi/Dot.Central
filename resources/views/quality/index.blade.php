@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">Data quality</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Data Quality</h1>
                <p class="dot-page-head__lede">How trustworthy is the operational picture? Open items between {{ $from->toDateString() }} and {{ $to->toDateString() }}, by outcome, rule, unit and shift.</p>
            </div>
            <form method="GET" style="display:flex;gap:0.5rem;align-items:center;"><input type="date" name="from" value="{{ $from->toDateString() }}" class="dot-input" style="width:auto;" /><input type="date" name="to" value="{{ $to->toDateString() }}" class="dot-input" style="width:auto;" /><button class="dot-btn dot-btn-primary dot-btn-sm">Apply</button></form>
        </div>

        <div class="dot-panels dot-panels--4" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Missing readings</span><span class="dot-readout__value {{ $summary['missing'] ? 'dot-readout__value--bad' : 'dot-readout__value--ok' }}">{{ Format::integer($summary['missing']) }}</span><span class="dot-readout__context">machines without usable telemetry</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Suspicious values</span><span class="dot-readout__value {{ $summary['suspicious'] ? 'dot-readout__value--warn' : '' }}">{{ Format::integer($summary['suspicious']) }}</span><span class="dot-readout__context">flagged for review</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Requires explanation</span><span class="dot-readout__value">{{ Format::integer($summary['requires_explanation']) }}</span><span class="dot-readout__context">offline / no loads / idle / unclassified</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Conflicting readings</span><span class="dot-readout__value {{ $summary['conflicts'] ? 'dot-readout__value--warn' : '' }}">{{ Format::integer($summary['conflicts']) }}</span><span class="dot-readout__context">hour meter decreased</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Awaiting approval</span><span class="dot-readout__value">{{ Format::integer($summary['awaiting']) }}</span><span class="dot-readout__context">explained, supervisor to decide</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Shifts with open critical</span><span class="dot-readout__value {{ $summary['shifts_with_critical'] ? 'dot-readout__value--bad' : 'dot-readout__value--ok' }}">{{ Format::integer($summary['shifts_with_critical']) }}</span><span class="dot-readout__context">cannot close until explained</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Resolved / approved / dismissed</span><span class="dot-readout__value">{{ Format::integer($summary['closed']) }}</span><span class="dot-readout__context">closed with a trace</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Reopened</span><span class="dot-readout__value">{{ Format::integer($summary['reopened']) }}</span><span class="dot-readout__context">conditions that came back</span></div>
        </div>

        <div class="dot-panels dot-panels--2" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Open items by rule</h2></div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Sev</th><th>Rule</th><th>Group</th><th class="num">Open</th></tr></thead><tbody>
                    @forelse($byRule as $r)<tr><td><span class="dot-badge dot-badge--{{ match($r['severity']) { 'CRITICAL' => 'bad', 'WARNING' => 'warn', 'REVIEW' => 'review', default => 'info' } }}">{{ $r['severity'] }}</span></td><td class="dot-ledger__key">{{ $r['code'] }}</td><td>{{ $r['title'] }}</td><td class="num">{{ $r['n'] }}</td></tr>@empty<tr><td colspan="4" class="dot-ledger__empty">Nothing open.</td></tr>@endforelse
                </tbody></table></div>
            </div>
            <div class="dot-panel dot-panel--flush">
                <div class="dot-panel__head"><h2 class="dot-panel__title">Units with most open items</h2></div>
                <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Unit</th><th class="num">Open items</th></tr></thead><tbody>
                    @forelse($worstUnits as $u)<tr><td class="dot-ledger__key">{{ $u['name'] }}</td><td class="num">{{ $u['n'] }}</td></tr>@empty<tr><td colspan="2" class="dot-ledger__empty">None.</td></tr>@endforelse
                </tbody></table></div>
            </div>
        </div>

        <div class="dot-panel dot-panel--flush">
            <div class="dot-panel__head"><h2 class="dot-panel__title">Shifts (newest first)</h2></div>
            <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Shift</th><th>Status</th><th class="num">Without telemetry at end</th><th class="num">Critical open</th><th class="num">Open items</th></tr></thead><tbody>
                @forelse($shiftRows as $row)<tr><td class="dot-ledger__key"><a href="{{ route('exceptions.index', ['shift' => $row['shift']->id]) }}" style="color:inherit;">{{ $row['shift']->label() }}</a></td><td><span class="dot-badge">{{ $row['shift']->status }}</span></td><td class="num">{{ $row['unknown'] }}</td><td class="num" style="{{ $row['critical'] ? 'color:var(--bad);' : '' }}">{{ $row['critical'] }}</td><td class="num">{{ $row['open'] }}</td></tr>@empty<tr><td colspan="5" class="dot-ledger__empty">No shifts in this range.</td></tr>@endforelse
            </tbody></table></div>
        </div>
    </div>
</x-app-layout>
