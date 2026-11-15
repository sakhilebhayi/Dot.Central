@props(['bar'])
{{-- The state of the room, in words, on every page (docs/dot-central/11-operator-experience.md §2): lamp, state word and the counts.
     It lives in the shared header's start column (R-52); the shift, the Dot.Mines link and the clock are in the foot. The long
     word has a short form for the narrowest phones, so the word is never cut off. --}}
@php
    $short = ['NEEDS ATTENTION' => 'ATTENTION', 'NO CONTROL ROOM' => 'NO ROOM'][$bar->state] ?? $bar->state;
    $counts = $bar->rooms > 0 ? $bar->critical.' critical · '.$bar->open.' open' : null;
    $tag = ($bar->detailUrl && $bar->level !== 'ok') ? 'a' : 'span';
@endphp
<{{ $tag }} class="statebar statebar--{{ $bar->level }}" @if($tag === 'a') href="{{ $bar->detailUrl }}" @endif role="status" aria-label="{{ ucfirst(strtolower($bar->state)) }}. {{ $bar->detail }}" title="{{ $bar->detail }}">
    <span class="statebar__lamp" aria-hidden="true"></span>
    <span class="statebar__state"><span class="statebar__long">{{ $bar->state }}</span><span class="statebar__short" aria-hidden="true">{{ $short }}</span></span>
    @if($counts)<span class="statebar__counts" aria-hidden="true">{{ $counts }}</span>@endif
    <span class="statebar__detail" aria-hidden="true">{{ $bar->detail }}</span>
</{{ $tag }}>
