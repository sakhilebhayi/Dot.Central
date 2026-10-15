@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">Reports</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Reports</h1>
                <p class="dot-page-head__lede">Every report is generated from the same persisted records and derivations; nothing is retyped. Generation is logged in the audit trail. Generated {{ Format::stamp($generatedAt, $timezone) }} ({{ $timezone }}).</p>
            </div>
            <div style="display:flex;gap:0.5rem;">
                @can('export-reports')@if($rows !== [])<a href="{{ route('reports.index', ['r' => $report, 'shift' => $shift?->id, 'room' => $room?->id, 'export' => 'csv']) }}" class="dot-btn dot-btn-ghost">Export CSV</a>@endif@endcan
                <button type="button" class="dot-btn dot-btn-ghost" onclick="window.print()">Print</button>
            </div>
        </div>
        <form method="GET" class="dot-panel" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;padding:0.75rem 1rem;margin-bottom:1.25rem;">
            <select name="r" class="dot-input" style="width:auto;">@foreach($reports as $key => $name)<option value="{{ $key }}" @selected($report === $key)>{{ $name }}</option>@endforeach</select>
            <select name="shift" class="dot-input" style="width:auto;">@foreach($shifts as $s)<option value="{{ $s->id }}" @selected($shift?->id === $s->id)>{{ $s->controlRoom?->name }} · {{ $s->label() }} · {{ $s->status }}</option>@endforeach</select>
            <button class="dot-btn dot-btn-primary dot-btn-sm">Generate</button>
        </form>
        <div class="dot-panel dot-panel--flush">
            <div class="dot-panel__head"><h2 class="dot-panel__title">{{ $title }}</h2><span class="dot-panel__action" style="color:var(--text-faint);">{{ count($rows) }} rows</span></div>
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr>@foreach($columns as $key => $label)<th class="{{ is_numeric($rows[0][$key] ?? null) ? 'num' : '' }}">{{ $label }}</th>@endforeach</tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        <tr>@foreach($columns as $key => $label)<td class="{{ is_numeric($row[$key] ?? null) ? 'num' : '' }}">{{ is_float($row[$key] ?? null) ? Format::number($row[$key]) : ($row[$key] ?? '—') }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ max(1, count($columns)) }}" class="dot-ledger__empty">Nothing to report for this selection.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
