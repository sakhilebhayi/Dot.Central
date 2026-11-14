{{-- R-52: sign-in from InfoDot cannot work because of how this host is set up. The visitor is told plainly that it
     is a setup problem and not their account, and gets two ways forward. The reference code and the setting names
     under "For the person who runs Dot.Central" name what to fix; no secret, URL or person detail is shown. --}}
@extends('errors._ecosystem-layout', ['code' => 503])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('code_label', $code)
@section('title', 'Dot.Central cannot sign you in through InfoDot right now')
@section('message', 'This is a setup problem on our side, not a problem with your account, and nothing has been lost. It has been recorded for the people who run Dot.Central. You can go back to InfoDot, or sign in here with a Dot.Central password if you have one.')

@section('actions')
    <a href="{{ $infodotUrl }}" class="press" data-primary-action style="padding: 12px 24px; background: var(--signal); border: 1px solid var(--signal); color: var(--on-signal); font-size: 15px;">Back to InfoDot</a>
    <a href="{{ url('/login') }}" class="press" style="padding: 12px 24px; border: 1px solid var(--rule-strong); font-size: 15px;">Sign in with a password</a>
@endsection

@section('after')
    <details class="operator" style="margin-top: 24px; text-align: left; max-width: 34rem;">
        <summary style="cursor: pointer; color: var(--text-muted); font-size: 14px; min-height: 44px; display: flex; align-items: center;">For the person who runs Dot.Central</summary>
        <p class="font-mono" style="font-size: 13px; color: var(--text-quiet); margin: 8px 0;">Reference {{ $code }}</p>
        <ul style="margin: 0; padding-left: 1.1rem; color: var(--text-muted); font-size: 14px; line-height: 1.55;">
            @foreach ($advice as $line)
                <li>{{ $line }}</li>
            @endforeach
            <li>Run <span class="font-mono">php artisan sso:check</span> on this host for the full list. The same check is in the Guardian report as <span class="font-mono">sso_receiver_configured</span>.</li>
        </ul>
    </details>
@endsection
