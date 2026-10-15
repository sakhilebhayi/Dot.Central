@php use App\Support\Format; @endphp
<article class="dot-msg dot-msg--{{ match($a->severity) { 'critical' => 'critical', 'warning' => 'warning', default => 'info' } }}" aria-label="{{ $a->title }}">
    <div class="dot-msg__title">
        <span class="dot-badge dot-badge--{{ match($a->severity) { 'critical' => 'bad', 'warning' => 'warn', default => 'info' } }}">{{ $a->severity }}</span>
        <span>{{ $a->title }}</span>
        <span class="dot-badge">{{ $a->workflowState() }}@if($a->escalation_level) L{{ $a->escalation_level }}@endif</span>
        <span class="dot-msg__code">{{ $a->source }}@if($a->priority) · {{ $a->priority }}@endif</span>
    </div>
    @if($a->description)<div style="font-size:var(--t-sm);margin-top:0.3rem;">{{ $a->description }}</div>@endif
    <div style="font-size:var(--t-xs);color:var(--text-faint);margin-top:0.4rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
        <span>{{ $a->controlRoom?->name }}</span>
        @if($a->equipment)<span>· {{ $a->equipment->name }}</span>@endif
        <span>· raised {{ Format::age($a->source_triggered_at ?? $a->triggered_at) }}</span>
        @if($a->assignee)<span>· assigned to {{ $a->assignee->name }}</span>@endif
        @if($a->cleared_at)<span>· resolved {{ Format::stamp($a->cleared_at) }}{{ $a->resolution_note ? ': '.$a->resolution_note : '' }}</span>@endif
    </div>
    @if($a->cleared_at === null)
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.6rem;">
        @can('acknowledge', $a)@if($a->acknowledged_at === null)<form method="POST" action="{{ route('alerts.acknowledge', $a) }}">@csrf<button class="dot-btn dot-btn-primary dot-btn-sm">Acknowledge</button></form>@endif@endcan
        @can('assign', $a)<form method="POST" action="{{ route('alerts.assign', $a) }}" style="display:flex;gap:0.4rem;">@csrf<select name="assigned_to" class="dot-input" style="width:auto;">@foreach($assignees as $u)<option value="{{ $u->id }}" @selected($a->assigned_to === $u->id)>{{ $u->name }}</option>@endforeach</select><button class="dot-btn dot-btn-ghost dot-btn-sm">Assign</button></form>@endcan
        @can('escalate', $a)<form method="POST" action="{{ route('alerts.escalate', $a) }}" style="display:flex;gap:0.4rem;">@csrf<input name="note" class="dot-input" placeholder="Why escalate" required style="width:12rem;" /><button class="dot-btn dot-btn-ghost dot-btn-sm" style="color:var(--warn);">Escalate</button></form>@endcan
        @can('resolve', $a)<form method="POST" action="{{ route('alerts.resolve', $a) }}" style="display:flex;gap:0.4rem;align-items:center;">@csrf<input name="note" class="dot-input" placeholder="Resolution note" style="width:12rem;" />@if($a->source === 'dot_mines' && auth()->user()->can('escalate', $a))<label style="font-size:var(--t-xs);color:var(--text-quiet);display:flex;gap:0.3rem;align-items:center;"><input type="checkbox" name="push" value="1" /> also resolve in Dot.Mines</label>@endif<button class="dot-btn dot-btn-ghost dot-btn-sm" style="color:var(--ok);">Resolve</button></form>@endcan
    </div>
    @endif
</article>
