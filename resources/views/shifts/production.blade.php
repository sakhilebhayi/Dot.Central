@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · {{ $shift->label() }} · Production</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Production</h1>
                <p class="dot-page-head__lede">{{ $shift->label() }} · tonnes are Dot.Mines production records for this date and shift; loads are differences of each machine's cumulative load counter inside the window. No factors are typed.</p>
            </div>
            <a href="{{ route('shifts.show', $shift) }}" class="dot-btn dot-btn-ghost">Shift operations</a>
        </div>
        @include('shifts._strip')
        @include('shifts._tiles')
        <div class="dot-panel dot-panel--flush">
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>Unit</th><th>Type</th><th class="num">Loads</th><th class="num">Production</th><th>Unit</th><th class="num">Run h</th><th class="num">Loads / run h</th></tr></thead>
                    <tbody>
                    @forelse($units->filter(fn($u) => $u['loads'] !== null || $u['production_quantity'] !== null) as $u)
                        <tr>
                            <td class="dot-ledger__key">{{ $u['equipment']->name }}</td>
                            <td>{{ $u['equipment']->machine_type ? str_replace('_', ' ', $u['equipment']->machine_type) : '—' }}</td>
                            <td class="num">{{ Format::integer($u['loads']) }}</td>
                            <td class="num">{{ Format::number($u['production_quantity'], 0) }}</td>
                            <td>{{ $u['production_unit'] ?? '—' }}</td>
                            <td class="num">{{ Format::number($u['run_hours']) }}</td>
                            <td class="num">{{ $u['loads'] !== null && $u['run_hours'] ? Format::number($u['loads'] / $u['run_hours']) : '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="dot-ledger__empty">No production data inside this window yet.</td></tr>
                    @endforelse
                    </tbody>
                    <tfoot><tr><td colspan="2" style="font-weight:600;">Total</td><td class="num" style="font-weight:600;">{{ Format::integer($totals['loads'] ?? null) }}</td><td class="num" style="font-weight:600;">{{ Format::number($totals['production_quantity'] ?? null, 0) }}</td><td>{{ $totals['production_unit'] ?? '' }}</td><td class="num">{{ Format::number($totals['run_hours'] ?? null) }}</td><td></td></tr></tfoot>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
