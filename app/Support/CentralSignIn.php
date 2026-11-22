<?php

namespace App\Support;

use Laravel\Fortify\Features;

/**
 * Where the "Sign in" and "Create account" entry points of the signed-out pages go. With DOTSSO_ENABLED they go straight
 * to the central sign-in (no hop through the local pages); otherwise they behave exactly as before, except that
 * "Create account" no longer lands on /login (it was a dead end while public sign-up here is closed): it opens InfoDot's
 * register page. The interception in the shared package would catch /login and /register anyway.
 */
class CentralSignIn
{
    public static function active(): bool
    {
        return (bool) config('dotsso.enabled') && ! (bool) config('dotsso.allow_local_login');
    }

    public static function login(): string
    {
        return self::active() ? route('dotsso.login') : route('login');
    }

    /** @param  string  $fallback  where "Create account" goes when the central sign-in is off */
    public static function register(string $fallback): string
    {
        return self::active() ? route('dotsso.login', ['screen' => 'register']) : $fallback;
    }

    /** Is public sign-up open in this app (Fortify's registration feature)? Not Route::has: the closed stub is a named route. */
    public static function registrationOpen(): bool
    {
        return Features::enabled(Features::registration());
    }

    /** "Create account" target for pages that have no other idea of it. */
    public static function join(): string
    {
        return self::register(self::registrationOpen() ? route('register') : (string) config('ecosystem.signup_url'));
    }
}
