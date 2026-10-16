<x-app-layout>
    <x-slot name="header">Exceptions</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Exceptions</h1>
                <p class="dot-page-head__lede">Only what needs attention. Rules run over Dot.Mines data after every sync; normal data flows through silently. Explain what you know; supervisors decide.</p>
            </div>
            @php($openShift = $shifts->first(fn($s) => $s->isCurrent()))
            @if($openShift)
                @can('close', $openShift)
                <form method="POST" action="{{ route('shifts.rerun', $openShift) }}">@csrf<button type="submit" class="dot-btn dot-btn-ghost">Re-run shift validation</button></form>
                @endcan
            @endif
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <x-validation-errors style="margin-bottom:1rem;" />

        <form method="GET" class="dot-panel" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;padding:0.75rem 1rem;margin-bottom:1.25rem;">
            <select name="room" class="dot-input" style="width:auto;"><option value="">All control rooms</option>@foreach($rooms as $r)<option value="{{ $r->id }}" @selected(($filters['room'] ?? null) == $r->id)>{{ $r->name }}</option>@endforeach</select>
            <select name="shift" class="dot-input" style="width:auto;"><option value="ALL">All shifts</option>@foreach($shifts as $s)<option value="{{ $s->id }}" @selected(($filters['shift'] ?? null) == $s->id)>{{ $s->label() }} · {{ $s->status }}</option>@endforeach</select>
            <select name="sev" class="dot-input" style="width:auto;"><option value="ALL">All severities</option>@foreach(['CRITICAL','WARNING','REVIEW','INFO'] as $sev)<option value="{{ $sev }}" @selected(($filters['sev'] ?? null) === $sev)>{{ $sev }}</option>@endforeach</select>
            <select name="status" class="dot-input" style="width:auto;">@foreach([['OPEN','Open & explained'],['EXPLAINED','Awaiting approval'],['APPROVED','Approved'],['RESOLVED','Resolved'],['REJECTED','Rejected'],['DISMISSED','Dismissed'],['ALL','Everything']] as [$v,$l])<option value="{{ $v }}" @selected($filters['status'] === $v)>{{ $l }}</option>@endforeach</select>
            <select name="group" class="dot-input" style="width:auto;"><option value="ALL">All groups</option>@foreach($groups as $g)<option value="{{ $g }}" @selected(($filters['group'] ?? null) === $g)>{{ $g }}</option>@endforeach</select>
            <button type="submit" class="dot-btn dot-btn-primary dot-btn-sm">Filter</button>
            <span style="margin-left:auto;font-family:var(--font-mono);font-size:var(--t-xs);display:flex;gap:0.8rem;">
                <span style="color:var(--bad);">{{ $counts['CRITICAL'] ?? 0 }} critical</span><span style="color:var(--warn);">{{ $counts['WARNING'] ?? 0 }} warning</span><span style="color:var(--review);">{{ $counts['REVIEW'] ?? 0 }} review</span><span style="color:var(--info);">{{ $counts['INFO'] ?? 0 }} info</span>
            </span>
        </form>

        @forelse($exceptions as $e)
            @include('exceptions._card', ['e' => $e])
        @empty
            <div class="dot-msg dot-msg--ok"><div class="dot-msg__title">Nothing to show</div><div>No exceptions match this filter.</div></div>
        @endforelse
        @if($total > $exceptions->count())<p class="dot-page-head__lede">… {{ $total - $exceptions->count() }} more; narrow the filter.</p>@endif
    </div>
</x-app-layout>
