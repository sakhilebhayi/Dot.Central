@props(['bar'])
{{-- The foot: status in words, live values only. The shift, the Dot.Mines link and the clock moved here from the
     state bar (R-51), so the bar keeps the title, the state word, the counts and the tools. --}}
<footer class="shellfoot" aria-label="Status">
    @if($bar->currentShift)
    <a href="{{ route('shifts.show', $bar->currentShift) }}" class="shellfoot__item shellfoot__item--shift" title="Open shift">
        <span class="dot-status-lamp shellfoot__lamp shellfoot__lamp--signal" aria-hidden="true"></span>
        {{ $bar->currentShift->label() }} · {{ $bar->currentShift->status }}
    </a>
    @else
    <a href="{{ $bar->startUrl ?? route('control-rooms.index') }}" class="shellfoot__item shellfoot__item--shift" title="No shift is open">
        <span class="dot-status-lamp shellfoot__lamp shellfoot__lamp--idle" aria-hidden="true"></span>
        No open shift — start one
    </a>
    @endif
    <span class="shellfoot__item" title="Dot.Mines data link">
        <span class="dot-status-lamp shellfoot__lamp shellfoot__lamp--{{ $bar->dataLinkLevel }}" aria-hidden="true"></span>
        {{ $bar->dataLink }}
    </span>
    <span class="shellfoot__item shellfoot__item--grow shellfoot__clock" data-shell-clock title="Server time, UTC">{{ now('UTC')->format('Y-m-d H:i') }} UTC</span>
</footer>
