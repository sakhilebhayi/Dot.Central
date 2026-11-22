{{-- R-54: a sign-in through InfoDot could not finish. Plain words, one way forward, the reference for support. --}}
@extends('errors._ecosystem-layout', ['code' => $code])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><path d="M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z" stroke-linecap="round" stroke-linejoin="round"/><path d="M12 9v4M12 17h.01" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('code_label', 'Reference '.$code)
@section('title', $headline)
@section('message', $body)

@section('header_action')
    <span></span>
@endsection

@section('actions')
    @if ($retry)
        <a href="{{ $retryUrl }}" class="press" data-primary-action style="min-height: 44px; display: inline-flex; align-items: center; padding: 0 24px; background: var(--signal); border: 1px solid var(--signal); color: var(--on-signal); font-size: 15px;">Start again</a>
    @endif
    <a href="{{ $homeUrl }}" class="press" style="min-height: 44px; display: inline-flex; align-items: center; padding: 0 24px; border: 1px solid var(--rule-strong); font-size: 15px;">Back to the start page</a>
@endsection
