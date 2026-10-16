@php use App\Services\Memory\MemoryContextService; @endphp
<div class="memory-strip" aria-live="polite">
    @switch($context['state'])
        @case('known')
            Memory: seen before {{ $context['seen'] }} {{ $context['seen'] === 1 ? 'time' : 'times' }}@if($context['first_seen']) since {{ MemoryContextService::day($context['first_seen']) }}@endif
            @if($context['tried'] !== []) · tried {{ collect($context['tried'])->map(fn ($t) => MemoryContextService::phrase($t['kind']).($t['verdict'] ? ' ('.$t['verdict'].')' : ''))->implode(', ') }}@endif
            @if($context['worked']) · worked best: {{ MemoryContextService::phrase($context['worked']) }}@endif
            @break
        @case('none')
            Memory: no history for this {{ $subjectType === 'equipment' ? 'machine' : str_replace('_', ' ', $subjectType) }}
            @break
        @case('unreachable')
            Memory: Dot.Memory unreachable{{ $context['fetched_at'] ? ', last fetched '.\App\Support\Format::age(\Illuminate\Support\Carbon::parse($context['fetched_at'])) : '' }}
            @break
        @default
    @endswitch
</div>
