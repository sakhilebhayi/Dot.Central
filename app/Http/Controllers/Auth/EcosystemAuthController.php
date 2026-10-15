<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Ecosystem SSO handoff: the InfoDot hub issues a short-lived Sanctum
 * token with the `ecosystem:read` ability and sends the browser here.
 * The token is consumed exactly once and must not be expired. The hub
 * puts it in the query string, so the response is marked no-store and
 * the session is regenerated before the redirect strips it from the
 * address bar.
 */
class EcosystemAuthController extends Controller
{
    public function handle(Request $request): RedirectResponse
    {
        $plain = $request->query('token');
        $accessToken = is_string($plain) && $plain !== '' ? PersonalAccessToken::findToken($plain) : null;

        abort_if(
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
            || ! $accessToken->tokenable instanceof User,
            403
        );

        /** @var User $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard')
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
