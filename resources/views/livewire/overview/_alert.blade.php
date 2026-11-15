@php use App\Support\Format; @endphp
{{-- The selected alert: facts, then acknowledge / assign / escalate / resolve as the person's role allows. --}}
<section class="ov-detail" wire:key="ov-detail-{{ $rowId }}" data-ov-detail="{{ $rowId }}" aria-label="Selected alert: {{ $a->title }}" @unless($open) hidden @endunless>
    <a href="#ov-queue-title" class="ov-back" data-ov-back>Back to the list</a>
    <h3 class="ov-detail__title">{{ $a->title }}</h3>
    <dl class="ov-kv">
        <dt>Source</dt><dd class="ov-mono">{{ $a->source }}@if($a->priority) · {{ $a->priority }}@endif</dd>
        <dt>Severity</dt><dd>{{ ucfirst($a->severity) }}</dd>
        <dt>State</dt><dd>{{ ucfirst($a->workflowState()) }}@if($a->escalation_level) · level {{ $a->escalation_level }}@endif</dd>
        <dt>Unit</dt><dd>@if($a->equipment)<a class="ov-link" href="{{ route('control-rooms.equipment.show', [$a->controlRoom, $a->equipment]) }}">{{ $a->equipment->name }}</a>@else<span class="ov-dash">None</span>@endif</dd>
        <dt>Raised</dt><dd>{{ Format::age($a->source_triggered_at ?? $a->triggered_at) }}</dd>
        <dt>Assigned to</dt><dd>{{ $a->assignee?->name ?? 'Nobody' }}</dd>
    </dl>
    @if($a->description)<p class="ov-say">{{ $a->description }}</p>@endif
    @if(\App\Services\Memory\MemoryAvailability::enabled())
        @php($memorySubject = \App\Services\Memory\LoopSubject::for($a))
        <div class="ov-say"><livewire:memory-context :subject-type="$memorySubject['type']" :subject-id="$memorySubject['id']" :signature="'dot-central:alert:'.strtolower($a->severity)" :key="'memory-alert-'.$a->id" /></div>
    @endif
    @auth
    <div class="ov-acts" data-ov-acts>
        @can('acknowledge', $a)@if($a->acknowledged_at === null)<form method="POST" action="{{ route('alerts.acknowledge', $a) }}" class="ov-act">@csrf<span class="ov-act__label">Acknowledge</span><div class="ov-field"><button class="ov-btn ov-btn--sm">Acknowledge</button></div></form>@endif@endcan
        @can('assign', $a)<form method="POST" action="{{ route('alerts.assign', $a) }}" class="ov-act">@csrf<label for="{{ $rowId }}-assign">Assign</label><div class="ov-field"><select id="{{ $rowId }}-assign" name="assigned_to" class="ov-input ov-input--select">@foreach($assignees as $u)<option value="{{ $u->id }}" @selected($a->assigned_to === $u->id)>{{ $u->name }}</option>@endforeach</select><button class="ov-btn ov-btn--sm">Assign</button></div></form>@endcan
        @can('escalate', $a)<form method="POST" action="{{ route('alerts.escalate', $a) }}" class="ov-act">@csrf<label for="{{ $rowId }}-escalate">Escalate</label><div class="ov-field"><input id="{{ $rowId }}-escalate" name="note" class="ov-input" placeholder="Why escalate" required autocomplete="off" /><button class="ov-btn ov-btn--sm">Escalate</button></div></form>@endcan
        @can('resolve', $a)<form method="POST" action="{{ route('alerts.resolve', $a) }}" class="ov-act">@csrf<label for="{{ $rowId }}-resolve">Resolve</label><div class="ov-field"><input id="{{ $rowId }}-resolve" name="note" class="ov-input" placeholder="Resolution note" autocomplete="off" />@if($a->source === 'dot_mines' && auth()->user()->can('escalate', $a))<label class="ov-check"><input type="checkbox" name="push" value="1" /> also resolve in Dot.Mines</label>@endif<button class="ov-btn ov-btn--sm">Resolve</button></div></form>@endcan
    </div>
    @cannot('acknowledge', $a)@cannot('assign', $a)@cannot('escalate', $a)@cannot('resolve', $a)
    <p class="ov-readonly">Your role can read this. A supervisor decides on exceptions and alerts.</p>
    @endcannot @endcannot @endcannot @endcannot
    @endauth
</section>
