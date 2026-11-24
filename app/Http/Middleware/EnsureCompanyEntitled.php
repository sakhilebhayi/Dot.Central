<?php

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use App\Support\EntitlementRefusal;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The signed-in area honours the company's entitlement (R-50).
 *
 * InfoDot decides whether a company holds Dot.Central. A person who is already signed in must stop working when that
 * ends, so each request in the signed-in area asks (through a one-minute cache) whether the company whose control room is
 * being worked in still holds the platform. A clear "no" answers with the plain refusal page and HTTP 403: nothing is
 * read from or written to the control room. Anything else, including every kind of failure, lets the request through
 * exactly as before.
 *
 * R-61: when InfoDot has switched Dot.Central off (maintenance, unavailable) signed-in requests get a 503 with InfoDot's
 * message, ahead of the company check and whatever the company holds. Nothing is read, changed or deleted and nobody
 * is signed out. A Livewire update gets a real JSON 503 (Livewire discards a non-redirect response returned by a
 * persistent middleware, so it is thrown as a response exception).
 *
 * It sits on the signed-in route group (routes/web.php) and on Jetstream's own pages (config/jetstream.php), and is
 * a Livewire persistent middleware, so component updates from those pages are checked too. It is not on sign-in,
 * sign-out, the handoff receiver, health checks, the Mines webhook, the Guardian report, the scheduler probe, the
 * token API, the scheduled jobs, or the public pages: none of them is a signed-in person's page or action.
 */
class EnsureCompanyEntitled
{
    /** Routes inside the checked groups that must keep working for a person whose company is refused. */
    private const EXEMPT = [
        'current-team.update', // switching to another company
        'profile.show',        // the person's own account
        'terms.show', 'policy.show', 'team-invitations.accept',
    ];

    /** Session key: redeem's `entitled` for the person's personal workspace, remembered at sign-in (absent: not set). */
    public const PERSONAL = 'infodot.personal_entitled';

    /** The reason in the JSON body when InfoDot has switched Dot.Central off (R-61). */
    public const UNAVAILABLE = 'platform_unavailable';

    public function __construct(private readonly EntitlementClient $entitlements) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->entitlements->enabled() || $request->routeIs(...self::EXEMPT)) {
            return $next($request);
        }

        $user = $request->user() ?? Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        $team = $this->company($request, $user);

        // A personal workspace, or a team InfoDot does not know: the read endpoint is never asked. What InfoDot said
        // about the person's personal workspace at sign-in decides: exactly false is a refusal (individuals hold
        // the free core only); true, or nothing remembered, carries on.
        if (! $team) {
            return $next($request);
        }

        if ($team->personal_team || blank($team->infodot_team_uuid)) {
            if (! $request->hasSession() || $request->session()->get(self::PERSONAL) !== false) {
                return $next($request);
            }

            return $this->refuse($request, fn () => EntitlementRefusal::response(
                'company_required',
                null,
                $user->allTeams()->reject(fn ($other) => $other->id === $team->id || $other->personal_team || blank($other->infodot_team_uuid)),
            ));
        }

        $answer = $this->entitlements->forCompany((string) $team->infodot_team_uuid);

        if ($answer !== null && $answer['available'] === false) {
            return $this->unavailable($request, $answer['message']);
        }

        if ($answer === null || $answer['entitled'] === true) {
            return $next($request);
        }

        return $this->refuse($request, fn () => EntitlementRefusal::response(
            $answer['status'],
            $team->name,
            $user->allTeams()->reject(fn ($other) => $other->id === $team->id),
        ));
    }

    /** 503 with the message and nothing else: no record is read, changed or deleted, and the person is not signed out. */
    private function unavailable(Request $request, ?string $message): Response
    {
        $message ??= 'Dot.Central is temporarily unavailable.';

        if ($request->expectsJson() || $this->isLivewire($request)) {
            $json = response()->json(['message' => $message, 'reason' => self::UNAVAILABLE], 503)
                ->header('Retry-After', '60')->header('Cache-Control', 'no-store, private');

            // Thrown, not returned: Livewire drops a persistent middleware's non-redirect response and runs the update.
            if ($this->isLivewire($request)) {
                throw new HttpResponseException($json);
            }

            return $json;
        }

        return response()->view('errors.platform-unavailable', ['message' => $message, 'signedIn' => true], 503)
            ->header('Cache-Control', 'no-store, private')->header('Retry-After', '60');
    }

    private function isLivewire(Request $request): bool
    {
        return $request->headers->has('X-Livewire') || $request->is('livewire/*', 'livewire-*/*');
    }

    /**
     * Livewire throws away any non-redirect response a persistent middleware returns, so a refusal page would let a
     * component update run. On a Livewire update the refusal must abort: nothing runs and no component comes back.
     */
    private function refuse(Request $request, Closure $page): Response
    {
        if ($this->isLivewire($request) || request()->headers->has('X-Livewire')) {
            abort(403);
        }

        return $page();
    }

    /** The company whose control room this request works in: the one in the address when the person belongs to it, else their current one. */
    private function company(Request $request, User $user): ?Team
    {
        $named = $request->route('team');

        if (is_scalar($named)) {
            $named = $user->allTeams()->firstWhere('id', (int) $named);
        }

        if ($named instanceof Team && $user->belongsToTeam($named)) {
            return $named;
        }

        $current = $user->currentTeam;

        return $current instanceof Team ? $current : null;
    }
}
