{{-- Workflow strip: the shift's path, with derived completion. --}}
@php($isClosed = $shift->isClosed())
<nav class="dot-panels dot-panels--6" aria-label="Shift workflow" style="margin-bottom:1.25rem;">
    @foreach([
        ['Start shift', route('control-rooms.shifts.index', $controlRoom), true],
        ['Fleet board', route('control-rooms.fleet', $controlRoom), ($totals['by_state']['unknown'] ?? 0) === 0],
        ['Engine hours', route('shifts.hours', $shift), ($totals['with_reading'] ?? 0) === ($totals['units'] ?? -1)],
        ['Production', route('shifts.production', $shift), ($totals['loads'] ?? 0) > 0 || ($totals['production_quantity'] ?? 0) > 0],
        ['Downtime', route('shifts.downtime', $shift), true],
        [$isClosed ? 'Handover' : 'End shift', route('shifts.review', $shift), $isClosed],
    ] as [$label, $href, $done])
        <a href="{{ $href }}" class="dot-panel" style="padding:0.6rem 0.9rem;text-decoration:none;font-family:var(--font-display);font-size:var(--t-sm);font-weight:600;color:{{ $done ? 'var(--ok)' : 'var(--text-quiet)' }};">{{ $done ? '✓ ' : '' }}{{ $label }}</a>
    @endforeach
</nav>
