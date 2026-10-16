@php use App\Support\Format; @endphp
{{-- The shared exception card: code · title · outcome · status, message, fix, explanation, resolution, role-aware actions. --}}
<article class="dot-msg dot-msg--{{ match($e->severity) { 'CRITICAL' => 'critical', 'WARNING' => 'warning', 'REVIEW' => 'review', default => 'info' } }}" aria-label="{{ $e->rule_code }} {{ $e->title }}">
    <div class="dot-msg__title">
        <span class="dot-msg__code">{{ $e->rule_code }}</span>
        <span>{{ $e->title }}</span>
        <span class="dot-badge dot-badge--{{ $e->tone() === 'review' ? 'review' : $e->tone() }}">{{ str_replace('_', ' ', $e->outcome) }}</span>
        <span class="dot-badge dot-badge--{{ match($e->status) { 'OPEN' => 'info', 'EXPLAINED' => 'review', 'APPROVED','RESOLVED' => 'ok', 'REJECTED','DISMISSED' => 'info', default => 'info' } }}">{{ $e->status }}</span>
    </div>
    <div style="font-size:var(--t-sm);margin-top:0.3rem;">{{ $e->message }}</div>
    @if($e->fix)<div class="dot-msg__fix">→ {{ $e->fix }}</div>@endif
    @if($e->explanation)<div class="dot-msg__fix"><strong>Explanation:</strong> {{ $e->explanation }}</div>@endif
    @if($e->resolution)<div class="dot-msg__fix"><strong>Resolution:</strong> {{ $e->resolution }}</div>@endif
    <div style="font-size:var(--t-xs);color:var(--text-faint);margin-top:0.4rem;display:flex;gap:0.6rem;flex-wrap:wrap;">
        <span>{{ $e->controlRoom?->name }}</span>
        @if($e->shift)<span>· {{ $e->shift->label() }}</span>@endif
        @if($e->equipment)<span>· {{ $e->equipment->name }}</span>@endif
        <span>· raised {{ Format::stamp($e->raised_at) }} by {{ $e->raised_by }}</span>
    </div>
    @auth
    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.6rem;">
        @can('explain', $e)
        <details><summary class="dot-btn dot-btn-ghost dot-btn-sm" style="list-style:none;cursor:pointer;">Explain</summary>
            <form method="POST" action="{{ route('exceptions.explain', $e) }}" style="display:flex;gap:0.4rem;margin-top:0.4rem;">@csrf<input name="explanation" class="dot-input" placeholder="Explanation (required)" required style="min-width:18rem;" /><button class="dot-btn dot-btn-primary dot-btn-sm">Save</button></form></details>
        @endcan
        @can('decide', $e)
        <form method="POST" action="{{ route('exceptions.approve', $e) }}" style="display:flex;gap:0.4rem;">@csrf<input name="comment" class="dot-input" placeholder="Approval comment (optional)" style="width:14rem;" /><button class="dot-btn dot-btn-ghost dot-btn-sm" style="color:var(--ok);">Approve</button></form>
        <form method="POST" action="{{ route('exceptions.reject', $e) }}" style="display:flex;gap:0.4rem;">@csrf<input name="comment" class="dot-input" placeholder="Reason for rejection" required style="width:14rem;" /><button class="dot-btn dot-btn-ghost dot-btn-sm" style="color:var(--bad);">Reject</button></form>
        @endcan
        @can('resolveConflict', $e)
        <form method="POST" action="{{ route('exceptions.resolve', $e) }}" style="display:flex;gap:0.4rem;flex-wrap:wrap;">@csrf
            <select name="choice" class="dot-input" style="width:auto;">
                <option value="A">{{ $e->detail['source_a'] ?? 'Source A' }} = {{ $e->detail['value_a'] ?? '—' }}</option>
                <option value="B">{{ $e->detail['source_b'] ?? 'Source B' }} = {{ $e->detail['value_b'] ?? '—' }}</option>
                <option value="NEITHER">Neither — corrected at source</option>
            </select>
            <input name="note" class="dot-input" placeholder="How was it verified?" style="width:14rem;" />
            <button class="dot-btn dot-btn-primary dot-btn-sm">Resolve conflict</button></form>
        @endcan
        @can('dismiss', $e)
        <form method="POST" action="{{ route('exceptions.dismiss', $e) }}" style="display:flex;gap:0.4rem;">@csrf<input name="comment" class="dot-input" placeholder="Why is this not an issue?" required style="width:14rem;" /><button class="dot-btn dot-btn-ghost dot-btn-sm">Dismiss</button></form>
        @endcan
    </div>
    @endauth
</article>
