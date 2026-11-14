{{-- R-50: the company does not hold Dot.Central. The plain control-room error card with the reason as its tag and ONE
     action (data-primary-action). Rendered by App\Support\EntitlementRefusal with HTTP 403. It shows nothing from the
     control room: no rooms, shifts, machines or counts. --}}
@extends('errors._ecosystem-layout', ['code' => 403])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="5" y="11" width="14" height="9" rx="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M8 11V7a4 4 0 118 0v4" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('code_label', $stamp)
@section('title', $title)
@section('message', $body)

@section('header_action')
    @auth
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="signout">Sign out</button>
        </form>
    @endauth
@endsection

@section('actions')
    <a href="{{ $platformsUrl }}" class="press" data-primary-action style="padding: 12px 24px; background: var(--signal); border: 1px solid var(--signal); color: var(--on-signal); font-size: 15px;">Open Platforms in InfoDot</a>
@endsection

@section('after')
    @auth
        @if ($otherCompanies->isNotEmpty())
            <section class="others" aria-labelledby="others-title">
                <h2 id="others-title">Your other {{ $otherCompanies->count() === 1 ? 'company' : 'companies' }} here</h2>
                <ul>
                    @foreach ($otherCompanies as $other)
                        <li>
                            <span>{{ $other->name }}</span>
                            <form method="POST" action="{{ route('current-team.update') }}">
                                @csrf
                                @method('PUT')
                                <input type="hidden" name="team_id" value="{{ $other->id }}">
                                <button type="submit" aria-label="Switch to {{ $other->name }}">Switch</button>
                            </form>
                        </li>
                    @endforeach
                </ul>
            </section>
        @endif
    @endauth
@endsection
