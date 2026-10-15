<x-app-layout>
    <x-slot name="header">Alerts</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Alerts</h1>
                <p class="dot-page-head__lede">Raised by Dot.Mines and by Dot.Central's own rules. Acknowledge, assign, escalate, resolve; every step is recorded. A manual alert is a supervisor action on the room page.</p>
            </div>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif
        <x-validation-errors style="margin-bottom:1rem;" />
        <form method="GET" class="dot-panel" style="display:flex;gap:0.6rem;flex-wrap:wrap;align-items:center;padding:0.75rem 1rem;margin-bottom:1.25rem;">
            <select name="room" class="dot-input" style="width:auto;"><option value="">All control rooms</option>@foreach($rooms as $r)<option value="{{ $r->id }}" @selected(($filters['room'] ?? null) == $r->id)>{{ $r->name }}</option>@endforeach</select>
            <select name="sev" class="dot-input" style="width:auto;"><option value="ALL">All severities</option>@foreach(['critical','warning','info'] as $sev)<option value="{{ $sev }}" @selected(($filters['sev'] ?? null) === $sev)>{{ $sev }}</option>@endforeach</select>
            <select name="state" class="dot-input" style="width:auto;"><option value="open" @selected($filters['state']==='open')>Open</option><option value="resolved" @selected($filters['state']==='resolved')>Resolved</option><option value="all" @selected($filters['state']==='all')>Everything</option></select>
            <button class="dot-btn dot-btn-primary dot-btn-sm">Filter</button>
        </form>
        @forelse($alerts as $a)
            @include('alerts._card', ['a' => $a])
        @empty
            <div class="dot-msg dot-msg--ok"><div class="dot-msg__title">No alerts match this filter.</div></div>
        @endforelse
        <div style="margin-top:1rem;">{{ $alerts->links() }}</div>
    </div>
</x-app-layout>
