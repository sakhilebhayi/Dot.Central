{{-- The shift path: seven real steps, done ones ticked, the next one marked. Same completion rules as the strip on
     the shift pages (shifts/_strip), drawn as one ruled row instead of seven panels. --}}
@php
    $isClosed = $shift->isClosed();
    $lineupConfirmed = app(\App\Services\Lineup\LineupService::class)->isConfirmed($shift);
    $steps = [
        ['Start shift', route('control-rooms.shifts.index', $controlRoom), true],
        ['Line-up', route('shifts.lineup', $shift), $lineupConfirmed],
        ['Fleet board', route('control-rooms.fleet', $controlRoom), ($totals['by_state']['unknown'] ?? 0) === 0],
        ['Engine hours', route('shifts.hours', $shift), ($totals['with_reading'] ?? 0) === ($totals['units'] ?? -1)],
        ['Production', route('shifts.production', $shift), ($totals['loads'] ?? 0) > 0 || ($totals['production_quantity'] ?? 0) > 0],
        ['Downtime', route('shifts.downtime', $shift), true],
        [$isClosed ? 'Handover' : 'End shift', route('shifts.review', $shift), $isClosed],
    ];
    $nextIndex = collect($steps)->search(fn ($s) => ! $s[2]);
    $doneCount = collect($steps)->filter(fn ($s) => $s[2])->count();
@endphp
<div class="ov-row ov-pathrow ov-a2">
    <ol class="ov-path" aria-label="Shift path">
        @foreach($steps as $i => [$label, $href, $isDone])
        <li><a href="{{ $href }}" class="{{ $isDone ? 'is-done' : '' }} {{ $nextIndex === $i ? 'is-next' : '' }}" @if($nextIndex === $i) aria-label="Next step: {{ $label }}" @endif>@if($isDone)<span class="ov-n" aria-hidden="true">&#10003;</span><span class="sr-only">Done: </span>@else<span class="ov-n" aria-hidden="true">{{ str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>@endif{{ $label }}</a></li>
        @endforeach
    </ol>
    <details class="ov-path-fold">
        <summary>Shift path <span class="ov-mono">{{ str_pad((string) $doneCount, 2, '0', STR_PAD_LEFT) }} of 07 done</span></summary>
        <ol>
            @foreach($steps as $i => [$label, $href, $isDone])
            <li><a href="{{ $href }}" class="{{ $isDone ? 'is-done' : '' }} {{ $nextIndex === $i ? 'is-next' : '' }}"><span class="ov-n" aria-hidden="true">{{ $isDone ? '✓' : str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT) }}</span>{{ $label }}</a></li>
            @endforeach
        </ol>
    </details>
</div>
