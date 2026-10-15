@php use App\Support\Format; @endphp
<x-app-layout>
    <x-slot name="header">Approvals</x-slot>
    <div class="dot-page">
        <div class="dot-page-head">
            <div>
                <h1 class="dot-page-head__title">Approvals</h1>
                <p class="dot-page-head__lede">Explained critical items, conflicts and closed shifts wait here for a supervisor. Reports show pending values as unapproved until decided.</p>
            </div>
        </div>
        @if (session('status'))<div class="dot-msg dot-msg--ok" role="status"><div class="dot-msg__title">{{ session('status') }}</div></div>@endif

        <div class="dot-panels dot-panels--3" style="margin-bottom:1.25rem;">
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Awaiting decision</span><span class="dot-readout__value {{ $pending->count() ? 'dot-readout__value--warn' : 'dot-readout__value--ok' }}">{{ Format::integer($pending->count()) }}</span><span class="dot-readout__context">{{ $explained }} explained by operators</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Conflicts</span><span class="dot-readout__value">{{ Format::integer($conflicts) }}</span><span class="dot-readout__context">two readings disagree</span></div>
            <div class="dot-panel dot-readout"><span class="dot-readout__label">Shifts to approve</span><span class="dot-readout__value">{{ Format::integer($shiftsToApprove->count()) }}</span><span class="dot-readout__context">closed, not yet signed off</span></div>
        </div>

        @if($shiftsToApprove->isNotEmpty())
        <div class="dot-panel dot-panel--flush" style="margin-bottom:1.25rem;">
            <div class="dot-panel__head"><h2 class="dot-panel__title">Closed shifts awaiting approval</h2></div>
            <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Shift</th><th>Room</th><th>Closed</th><th></th></tr></thead><tbody>
                @foreach($shiftsToApprove as $s)
                <tr><td class="dot-ledger__key">{{ $s->label() }}</td><td>{{ $s->controlRoom?->name }}</td><td class="dot-ledger__key">{{ Format::stamp($s->closed_at) }}</td>
                    <td style="display:flex;gap:0.5rem;"><a href="{{ route('shifts.review', $s) }}" class="dot-btn dot-btn-ghost dot-btn-sm">Review</a>@can('approve', $s)<form method="POST" action="{{ route('shifts.approve', $s) }}">@csrf<button class="dot-btn dot-btn-primary dot-btn-sm">Approve shift</button></form>@endcan</td></tr>
                @endforeach
            </tbody></table></div>
        </div>
        @endif

        @forelse($pending as $e)
            @include('exceptions._card', ['e' => $e])
        @empty
            <div class="dot-msg dot-msg--ok"><div class="dot-msg__title">Nothing awaiting approval</div></div>
        @endforelse

        <div class="dot-panel dot-panel--flush" style="margin-top:1.25rem;">
            <div class="dot-panel__head"><h2 class="dot-panel__title">Recent decisions</h2></div>
            <div class="dot-table-wrap"><table class="dot-ledger"><thead><tr><th>Decided</th><th>Decision</th><th>Exception</th><th>Comment</th></tr></thead><tbody>
                @forelse($decisions as $d)
                <tr><td class="dot-ledger__key">{{ Format::stamp($d->decided_at) }}</td><td><span class="dot-badge dot-badge--{{ $d->status === 'APPROVED' ? 'ok' : 'bad' }}">{{ $d->status }}</span></td><td>{{ $d->rule_code }} {{ $d->title }}</td><td>{{ $d->decision_comment ?? '—' }}</td></tr>
                @empty
                <tr><td colspan="4" class="dot-ledger__empty">No decisions yet.</td></tr>
                @endforelse
            </tbody></table></div>
        </div>
    </div>
</x-app-layout>
