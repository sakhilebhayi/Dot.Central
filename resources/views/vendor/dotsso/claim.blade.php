{{-- R-54: confirm an existing Dot.Central account before InfoDot is linked to it. Rendered in the control-room shell
     (errors._ecosystem-layout: panel card, signal accent, Roboto, day and night). 44px targets, one labelled code field. --}}
@extends('errors._ecosystem-layout', ['code' => 'Confirm'])

@section('icon')
    <svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"><rect x="3" y="5" width="18" height="14" rx="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M3 7l9 6 9-6" stroke-linecap="round" stroke-linejoin="round"/></svg>
@endsection

@section('code_label', 'Confirm it is you')
@section('title', 'Confirm this is your account')
@section('message', 'A Dot.Central account already uses this email address. We sent a '.$codeLength.'-digit code to '.$maskedEmail.'. It works for '.$minutes.' minutes and once. Nothing in your account changes until you enter it.')

@section('header_action')
    <span></span>
@endsection

@section('actions')
    <form method="post" action="{{ $verifyUrl }}" class="claim" style="width: 100%;">
        @csrf
        @if (session('dotsso_status'))<p class="claim__ok" role="status">{{ session('dotsso_status') }}</p>@endif
        <label for="code" class="claim__label">Confirmation code</label>
        <input id="code" name="code" class="claim__input font-mono" inputmode="numeric" autocomplete="one-time-code" maxlength="{{ $codeLength }}" required autofocus aria-describedby="code-error" @if ($errors->has('code')) aria-invalid="true" @endif>
        <p id="code-error" class="claim__err" role="alert">{{ $errors->first('code') }}</p>
        <button type="submit" class="claim__btn claim__btn--primary" data-primary-action>Confirm and sign in</button>
    </form>
@endsection

@section('after')
    <style>
        .claim__label { display: block; font-weight: 600; font-size: 14px; color: var(--text); margin: 0 0 6px; }
        .claim__input { display: block; width: 100%; min-height: 48px; padding: 0 14px; font-size: 22px; letter-spacing: .3em; background: var(--ground); color: var(--text); border: 1px solid var(--rule-strong); border-radius: 2px; }
        .claim__input:focus-visible { outline: 2px solid var(--signal); outline-offset: 2px; border-color: var(--signal); }
        .claim__input[aria-invalid="true"] { border-color: #e2574c; }
        .claim__err { margin: 8px 0 0 !important; min-height: 1.4em; color: #ef7a70 !important; font-size: 14px !important; }
        :root[data-theme='light'] .claim__err { color: #b3261e !important; }
        .claim__ok { margin: 0 0 14px !important; color: var(--text) !important; font-size: 14px !important; }
        .claim__btn { font: inherit; font-weight: 600; font-size: 15px; min-height: 44px; padding: 0 20px; border: 1px solid var(--rule-strong); background: none; color: var(--text); cursor: pointer; border-radius: 2px; }
        .claim__btn:hover { border-color: var(--text-muted); }
        .claim__btn--primary { background: var(--signal); border-color: var(--signal); color: var(--on-signal); margin-top: 6px; }
        .claim__btn:focus-visible { outline: 2px solid var(--signal); outline-offset: 2px; }
        .claim__row { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 18px; }
        .claim__row form { margin: 0; }
        @media (max-width: 480px) { .claim__btn--primary { width: 100%; } .claim__row form, .claim__row .claim__btn { width: 100%; } }
    </style>
    <div class="claim__row">
        <form method="post" action="{{ $resendUrl }}">@csrf<button class="claim__btn" type="submit">Send a new code</button></form>
        <form method="post" action="{{ $cancelUrl }}">@csrf<button class="claim__btn" type="submit">Cancel</button></form>
    </div>
@endsection
