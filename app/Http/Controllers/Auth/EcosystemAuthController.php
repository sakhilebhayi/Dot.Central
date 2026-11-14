<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Ecosystem\ProvisionUserFromInfoDot;
use App\Actions\Ecosystem\SyncTeamsFromInfoDot;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\User;
use App\Services\InfoDot\IdentityClient;
use App\Services\InfoDot\InfoDotUnavailable;
use App\Support\EntitlementRefusal;
use App\Support\SsoFailure;
use App\Support\SsoReceiverStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Ecosystem SSO handoff: InfoDot (the identity hub) sends the browser here with a
 * short-lived handoff token. This app has its own database, so the token is sent to
 * InfoDot's redeem endpoint, which answers with the person's identity and companies
 * (see App\Services\InfoDot\IdentityClient). Only when that client is not configured
 * does the old shared-database lookup (a Sanctum token with the `ecosystem:read`
 * ability) apply. Either way the token is consumed exactly once, and the response is
 * marked no-store with the session regenerated.
 */
class EcosystemAuthController extends Controller
{
    /** A GET (an old link with the token in the URL) is not honoured: the token is never read. */
    public function legacy(): RedirectResponse
    {
        return redirect()->to('/login');
    }

    /**
     * Sign-in from InfoDot. The token arrives in the POST body of a form InfoDot's launch page posts here, so it
     * is never in a URL (address bar, history, access logs, Referer). A refused sign-in lands on the plain login
     * page: the credential is not shown, echoed, flashed or logged. A GET with ?token= is no longer honoured
     * (the route sends it to the login page), and a token in the query string of a POST is ignored.
     */
    public function handle(Request $request): Response
    {
        try {
            if ($request->isMethod('post')) {
                $this->assertPostedFromInfoDot($request);
            }

            return app()->call([$this, 'redeem'], ['request' => $request]);
        } catch (SsoFailure $failure) {
            return $this->fail($request, $failure);
        } catch (HttpExceptionInterface $e) {
            return $this->fail($request, new SsoFailure(
                $e->getStatusCode() === 503 ? SsoFailure::INFODOT_UNAVAILABLE : SsoFailure::TOKEN_REJECTED,
                ['http_status' => $e->getStatusCode()],
            ));
        }
    }

    /**
     * R-52: every refusal has a named reason. The reason is logged (never the token, the secret or anything about
     * the person). A host-setup problem gets the operator recovery page; anything else goes back to the login page
     * with a safe, specific sentence.
     */
    private function fail(Request $request, SsoFailure $failure): Response
    {
        $context = array_merge(['reason' => $failure->reason], $failure->context);

        if ($failure->needsOperator()) {
            // One line a minute per reason: an operator problem repeats for every visitor.
            $once = true;

            try {
                $once = Cache::add('sso:receiver:logged:'.$failure->reason, 1, 60);
            } catch (Throwable) {
                // No cache: log every time rather than not at all.
            }

            if ($once) {
                Log::error('SSO receiver cannot sign anyone in: '.$failure->reason.'. Run php artisan sso:check on this host.', $context + ['setup_problems' => SsoReceiverStatus::problems()]);
            }

            return response()
                ->view('auth.sso-problem', ['code' => $failure->code(), 'reason' => $failure->reason, 'advice' => $this->advice($failure), 'infodotUrl' => rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/')], 503)
                ->header('Cache-Control', 'no-store, private')
                ->header('Referrer-Policy', 'no-referrer');
        }

        Log::warning('SSO sign-in refused: '.$failure->reason.'.', $context);

        return redirect('/login', 303)->with('status', $failure->userMessage());
    }

    /**
     * Setting names only, never values, for the person who runs this host.
     *
     * @return list<string>
     */
    private function advice(SsoFailure $failure): array
    {
        if ($failure->reason === SsoFailure::SECRET_REJECTED) {
            return ["InfoDot refused this platform's secret. INFODOT_SERVICE_SECRET here must equal ECOSYSTEM_SECRET_CENTRAL in InfoDot's .env, and INFODOT_PLATFORM_KEY must be central. Compare their hashes, never print them. Then run php artisan config:cache."];
        }

        $problems = SsoReceiverStatus::blocking();

        if ($failure->reason === SsoFailure::REDEEM_HOST_MISMATCH && ! in_array(SsoReceiverStatus::REDEEM_HOST_MISMATCH, $problems, true)) {
            $problems[] = SsoReceiverStatus::REDEEM_HOST_MISMATCH;
        }

        return array_map(fn (string $p) => SsoReceiverStatus::ADVICE[$p], $problems) ?: [
            'Run php artisan sso:check on this host: it names the setting that is wrong.',
        ];
    }

    /**
     * Login CSRF: a form on another site could sign a visitor in as someone else (the attacker's own valid
     * token). A browser names the page that posted a cross-site form in Origin, so only InfoDot's launch page,
     * on this app's parent domain (tasks.infodot.co.za -> infodot.co.za), is accepted.
     */
    private function assertPostedFromInfoDot(Request $request): void
    {
        $origin = (string) $request->headers->get('Origin', '');
        $host = $origin !== '' && $origin !== 'null'
            ? parse_url($origin, PHP_URL_HOST)
            : parse_url((string) $request->headers->get('Referer', ''), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            if (in_array($request->headers->get('Sec-Fetch-Site'), ['same-origin', 'same-site'], true)) {
                return;
            }

            throw new SsoFailure(SsoFailure::ORIGIN_REJECTED, ['origin_host' => null]);
        }

        $host = strtolower($host);

        // When this app knows InfoDot's address, exactly that origin is accepted, not a sibling subdomain.
        $infodot = SsoReceiverStatus::redeemHost();

        if ($infodot !== null) {
            if ($host === $infodot) {
                return;
            }

            // InfoDot's own public host posted, but this host was told InfoDot lives somewhere else: that is this
            // host's setting being wrong, not a stranger's form.
            if ($host === SsoReceiverStatus::infodotHost()) {
                throw new SsoFailure(SsoFailure::REDEEM_HOST_MISMATCH, ['origin_host' => $host, 'redeem_host' => $infodot]);
            }

            throw new SsoFailure(SsoFailure::ORIGIN_REJECTED, ['origin_host' => $host, 'redeem_host' => $infodot]);
        }

        // An app without that setting falls back to its own parent domain.
        $self = strtolower($request->getHost());
        $parent = str_contains($self, '.') ? substr($self, strpos($self, '.') + 1) : $self;

        if ($host === $self || $host === $parent || str_ends_with($host, '.'.$parent)) {
            return;
        }

        // No address for InfoDot is configured at all: the host's setting, not a stranger's form.
        if (in_array(SsoReceiverStatus::REDEEM_URL_MISSING, SsoReceiverStatus::problems(), true)) {
            throw new SsoFailure(SsoFailure::NOT_CONFIGURED, ['origin_host' => $host, 'setup_problems' => implode(',', SsoReceiverStatus::problems())]);
        }

        throw new SsoFailure(SsoFailure::ORIGIN_REJECTED, ['origin_host' => $host]);
    }

    public function redeem(Request $request, IdentityClient $infoDot, ProvisionUserFromInfoDot $provision, SyncTeamsFromInfoDot $syncTeams): Response
    {
        $token = $request->post('token');

        if (! is_string($token) || $token === '') {
            throw new SsoFailure(SsoFailure::TOKEN_MISSING);
        }

        $user = $this->userFromLocalToken($token);
        $identity = null;

        // Production gives each Dot app its own database, so InfoDot's token is
        // not in ours: ask InfoDot to prove it.
        if (! $user) {
            if (! $infoDot->enabled()) {
                // No secret (or no redeem address) and the token is not in a database we share: nobody can ever sign
                // in here. That is the host's setting, not the visitor's doing.
                throw new SsoFailure(SsoFailure::NOT_CONFIGURED, ['setup_problems' => implode(',', SsoReceiverStatus::problems())]);
            }

            try {
                $identity = $infoDot->redeem($token);
            } catch (InfoDotUnavailable) {
                throw new SsoFailure(SsoFailure::INFODOT_UNAVAILABLE, ['infodot_status' => $infoDot->lastStatus]);
            }

            if ($identity === null) {
                throw $infoDot->lastStatus === 401
                    ? new SsoFailure(SsoFailure::SECRET_REJECTED, ['infodot_status' => 401])
                    : new SsoFailure($infoDot->lastStatus !== null && $infoDot->lastStatus < 300 ? SsoFailure::BAD_ANSWER : SsoFailure::TOKEN_REJECTED, ['infodot_status' => $infoDot->lastStatus]);
            }

            // R-50: InfoDot marks each company with whether it holds this platform. When it says none does, nobody
            // is signed in and nothing is created: the person gets the plain page that says so.
            if (($refusal = $this->nothingEntitled($identity)) !== null) {
                return EntitlementRefusal::response($refusal['reason'], $refusal['company']);
            }

            $user = $provision($identity);

            if (! $user) {
                throw new SsoFailure($provision->failure ?? SsoFailure::NO_ACCOUNT);
            }

            $syncTeams($user, $identity);
        }

        // Auth::login() also regenerates the session id (no fixation).
        Auth::login($user);
        $request->session()->regenerate();

        // R-50: what InfoDot said about the person's personal workspace for this platform, kept with the sign-in so
        // the local personal team is not a way round the company's entitlement. No key: nothing is remembered.
        $personal = collect($identity['teams'] ?? [])->first(fn ($t) => ($t['personal'] ?? false) === true);

        if ($personal !== null && array_key_exists('entitled', $personal)) {
            $request->session()->put(EnsureCompanyEntitled::PERSONAL, $personal['entitled'] === true);
        } else {
            $request->session()->forget(EnsureCompanyEntitled::PERSONAL);
        }

        // The sign-in response is never cached and never leaks the address as a Referer.
        return ($this->safeRedirect($request->post('redirect')) ?? redirect()->route('dashboard'))
            ->header('Cache-Control', 'no-store, private')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /**
     * The reason and company to name when InfoDot answered and no company in the answer holds this platform. Null
     * when at least one does, or when any company comes without the `entitled` key (an InfoDot from before R-50,
     * where absence is not a refusal).
     *
     * @param  array<string, mixed>  $identity
     * @return array{reason: string, company: ?string}|null
     */
    private function nothingEntitled(array $identity): ?array
    {
        $teams = collect($identity['teams'] ?? [])->filter(fn ($t) => is_array($t))->values();

        if ($teams->isEmpty() || $teams->contains(fn ($t) => ! array_key_exists('entitled', $t) || $t['entitled'] === true)) {
            return null;
        }

        // Speak about the company the person was working in at InfoDot, else their first real company.
        $active = $identity['current_team_uuid'] ?? null;
        $about = $teams->first(fn ($t) => $active !== null && ($t['uuid'] ?? null) === $active && ! ($t['personal'] ?? false))
            ?? $teams->first(fn ($t) => ! ($t['personal'] ?? false))
            ?? $teams->first();

        return [
            'reason' => is_string($about['access'] ?? null) ? $about['access'] : '',
            'company' => ($about['personal'] ?? false) || ! is_string($about['name'] ?? null) ? null : $about['name'],
        ];
    }

    /**
     * A deep link may name an in-app path to open. Only a plain absolute path on this
     * site is honoured, so this can never be turned into an open redirect: it must start
     * with a single "/", and may not contain "//", a backslash, "..", a control character
     * or a scheme, either as written or once percent-decoded.
     */
    private function safeRedirect(mixed $redirect): ?RedirectResponse
    {
        if (! is_string($redirect) || $redirect === '' || strlen($redirect) > 2048) {
            return null;
        }

        foreach ([$redirect, rawurldecode($redirect)] as $candidate) {
            if (
                ! str_starts_with($candidate, '/')
                || str_starts_with($candidate, '//')
                || str_contains($candidate, '\\')
                || str_contains($candidate, '..')
                || str_contains($candidate, '//')
                || preg_match('/[\x00-\x20\x7f]/', $candidate)
            ) {
                return null;
            }
        }

        return redirect($redirect);
    }

    /** A token minted in a database we share (the original design): found, checked, spent. */
    private function userFromLocalToken(string $token): ?User
    {
        $accessToken = PersonalAccessToken::findToken($token);

        if (
            ! $accessToken
            || ! $accessToken->can('ecosystem:read')
            || ($accessToken->expires_at && $accessToken->expires_at->isPast())
            || ! $accessToken->tokenable instanceof User
        ) {
            return null;
        }

        /** @var User $user */
        $user = $accessToken->tokenable;
        $accessToken->delete();

        return $user;
    }
}
