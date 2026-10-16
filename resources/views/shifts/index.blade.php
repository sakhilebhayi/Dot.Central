@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">{{ $controlRoom->name }} · Shifts</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Shifts</h1>
                <p class="dot-page-head__lede">{{ $controlRoom->name }} · {{ $current ? 'Current: '.$current->label().' ('.$current->status.')' : 'No shift is open.' }}</p>
            </div>
            <div style="display:flex;gap:0.5rem;">
                @if($current)
                    <a href="{{ route('shifts.show', $current) }}" class="dot-btn dot-btn-primary">Shift operations</a>
                @else
                    @can('open', [\App\Models\Shift::class, $controlRoom])
                    <a href="{{ route('control-rooms.shifts.start', $controlRoom) }}" class="dot-btn dot-btn-primary">Start shift</a>
                    @endcan
                @endif
                <a href="{{ route('control-rooms.show', $controlRoom) }}" class="dot-btn dot-btn-ghost">Room</a>
            </div>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <div class="dot-panel dot-panel--flush">
            <div class="dot-table-wrap">
                <table class="dot-ledger">
                    <thead><tr><th>Shift</th><th>Window</th><th>Status</th><th class="num">Run h</th><th class="num">Loads</th><th class="num">Production</th><th>Handover</th><th></th></tr></thead>
                    <tbody>
                    @forelse($shifts as $s)
                        <tr>
                            <td class="dot-ledger__key">{{ $s->label() }}</td>
                            <td class="dot-ledger__key">{{ Format::stamp($s->starts_at, $timezone) }} – {{ Format::stamp($s->ends_at, $timezone) }}</td>
                            <td><span class="dot-badge dot-badge--{{ match($s->status) { 'APPROVED','CLOSED' => 'ok', 'OPEN','IN_REVIEW','REOPENED' => 'info', default => 'info' } }}">{{ $s->status }}</span></td>
                            <td class="num">{{ Format::number($s->summary['run_hours'] ?? null) }}</td>
                            <td class="num">{{ Format::integer($s->summary['loads'] ?? null) }}</td>
                            <td class="num">{{ Format::number($s->summary['production_quantity'] ?? null, 0) }}</td>
                            <td>{{ $s->handover ? ($s->handover->acknowledged_at ? 'acknowledged' : 'issued') : '—' }}</td>
                            <td><a href="{{ $s->isClosed() ? route('shifts.review', $s) : route('shifts.show', $s) }}" class="dot-panel__action">Open →</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="dot-ledger__empty">No shifts yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        <div style="margin-top:1rem;">{{ $shifts->links() }}</div>
    </div>
</x-app-layout>
