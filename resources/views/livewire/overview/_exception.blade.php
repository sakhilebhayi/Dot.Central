@php use App\Support\Format; @endphp
{{-- The selected exception: facts, then the actions the person's role allows (same routes and policies as the cards
     on the Exceptions page). Rendered hidden for every row; the page script shows the selected one. --}}
<section class="ov-detail" wire:key="ov-detail-{{ $rowId }}" data-ov-detail="{{ $rowId }}" aria-label="Selected exception: {{ $e->title }}" @unless($open) hidden @endunless>
    <a href="#ov-queue-title" class="ov-back" data-ov-back>Back to the list</a>
    <h3 class="ov-detail__title">{{ $e->title }}</h3>
    <dl class="ov-kv">
        <dt>Rule</dt><dd class="ov-mono">{{ $e->rule_code }}</dd>
        <dt>Outcome</dt><dd>{{ ucfirst(strtolower(str_replace('_', ' ', $e->outcome))) }}</dd>
        <dt>Status</dt><dd>{{ ucfirst(strtolower($e->status)) }}</dd>
        <dt>Unit</dt><dd>@if($e->equipment)<a class="ov-link" href="{{ route('control-rooms.equipment.show', [$e->controlRoom, $e->equipment]) }}">{{ $e->equipment->name }}</a>@else<span class="ov-dash">None</span>@endif</dd>
        @if($e->shift)<dt>Shift</dt><dd>{{ $e->shift->label() }}</dd>@endif
        <dt>Raised</dt><dd>{{ Format::stamp($e->raised_at) }} by {{ $e->raised_by }}</dd>
    </dl>
    <p class="ov-say">{{ $e->message }}</p>
    @if($e->fix)<p class="ov-say ov-say--fix">{{ $e->fix }}</p>@endif
    @if($e->explanation)<p class="ov-say"><strong>Explanation:</strong> {{ $e->explanation }}</p>@endif
    @if($e->resolution)<p class="ov-say"><strong>Resolution:</strong> {{ $e->resolution }}</p>@endif
    @if(\App\Services\Memory\MemoryAvailability::enabled())
        @php($memorySubject = \App\Services\Memory\LoopSubject::for($e))
        <div class="ov-say"><livewire:memory-context :subject-type="$memorySubject['type']" :subject-id="$memorySubject['id']" :signature="'dot-central:exception:'.$e->rule_code" :key="'memory-exception-'.$e->id" /></div>
    @endif
    @auth
    <div class="ov-acts" data-ov-acts>
        @can('explain', $e)
        <form method="POST" action="{{ route('exceptions.explain', $e) }}" class="ov-act">@csrf<label for="{{ $rowId }}-explain">Explain</label><div class="ov-field"><input id="{{ $rowId }}-explain" name="explanation" class="ov-input" placeholder="Explanation (required)" required autocomplete="off" /><button class="ov-btn ov-btn--sm">Save</button></div></form>
        @endcan
        @can('decide', $e)
        <form method="POST" action="{{ route('exceptions.approve', $e) }}" class="ov-act">@csrf<label for="{{ $rowId }}-approve">Approve</label><div class="ov-field"><input id="{{ $rowId }}-approve" name="comment" class="ov-input" placeholder="Comment (optional)" autocomplete="off" /><button class="ov-btn ov-btn--sm">Approve</button></div></form>
        <form method="POST" action="{{ route('exceptions.reject', $e) }}" class="ov-act">@csrf<label for="{{ $rowId }}-reject">Reject</label><div class="ov-field"><input id="{{ $rowId }}-reject" name="comment" class="ov-input" placeholder="Reason for rejection" required autocomplete="off" /><button class="ov-btn ov-btn--sm">Reject</button></div></form>
        @endcan
        @can('resolveConflict', $e)
        <form method="POST" action="{{ route('exceptions.resolve', $e) }}" class="ov-act">@csrf<label for="{{ $rowId }}-choice">Resolve conflict</label><div class="ov-field">
            <select id="{{ $rowId }}-choice" name="choice" class="ov-input ov-input--select">
                <option value="A">{{ $e->detail['source_a'] ?? 'Source A' }} = {{ $e->detail['value_a'] ?? '—' }}</option>
                <option value="B">{{ $e->detail['source_b'] ?? 'Source B' }} = {{ $e->detail['value_b'] ?? '—' }}</option>
                <option value="NEITHER">Neither — corrected at source</option>
            </select>
            <input name="note" class="ov-input" placeholder="How was it verified?" aria-label="How was it verified?" autocomplete="off" />
            <button class="ov-btn ov-btn--sm">Resolve conflict</button></div></form>
        @endcan
        @can('dismiss', $e)
        <form method="POST" action="{{ route('exceptions.dismiss', $e) }}" class="ov-act">@csrf<label for="{{ $rowId }}-dismiss">Dismiss</label><div class="ov-field"><input id="{{ $rowId }}-dismiss" name="comment" class="ov-input" placeholder="Why is this not an issue?" required autocomplete="off" /><button class="ov-btn ov-btn--sm">Dismiss</button></div></form>
        @endcan
    </div>
    @cannot('explain', $e)@cannot('decide', $e)@cannot('resolveConflict', $e)@cannot('dismiss', $e)
    <p class="ov-readonly">Your role can read this. A supervisor decides on exceptions and alerts.</p>
    @endcannot @endcannot @endcannot @endcannot
    @endauth
</section>
