<?php

namespace Tests\Feature\Sso;

use App\Mail\SsoClaimCodeMail;
use App\Models\Team;
use App\Models\User;
use App\Sso\CentralProvisioner;
use Dot\SsoClient\Tests\Support\FakeIdp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;
use Laravel\Jetstream\Mail\TeamInvitation;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** R-54 rollout I: Dot.Central behind DOTSSO_ENABLED. Off, nothing changes; on, every entry goes to InfoDot. */
class CentralSignInCentralTest extends TestCase
{
    use RefreshDatabase;

    private const UUID = '5a5a5a5a-5a5a-4a5a-8a5a-5a5a5a5a5a5a';

    private FakeIdp $idp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->idp = (new FakeIdp)->install();
        config(['dotsso.provisioner' => CentralProvisioner::class, 'dotsso.enabled' => false]);
        Mail::fake();
    }

    private function closeRegistration(): void
    {
        config(['fortify.features' => array_values(array_diff(config('fortify.features'), [Features::registration()]))]);
    }

    private function on(): void
    {
        config(['dotsso.enabled' => true]);
    }

    private function signInVia(array $claims = [], array $session = [])
    {
        $response = $this->withSession($session)->get('/sso/login')->assertRedirect();
        $authorize = FakeIdp::authorizeParams($response->headers->get('Location'));
        $code = $this->idp->approve($authorize, $claims);

        return $this->get('/sso/callback?'.http_build_query(['code' => $code, 'state' => $authorize['state']]));
    }

    public function test_with_the_flag_off_nothing_changes(): void
    {
        $this->get('/login')->assertOk()->assertSee('Remember me');
        $this->get('/sso/login')->assertNotFound();
        $this->get('/')->assertOk()->assertSee(route('login'), false)->assertDontSee('/sso/login', false);
    }

    public function test_create_an_account_never_loops_back_to_the_login_page(): void
    {
        // Public sign-up is closed in production; the old stub sent /register to /login, a dead end.
        $this->closeRegistration();
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.config('ecosystem.signup_url').'"', $html);
        $this->assertStringNotContainsString('href="'.route('register').'"', $html);

        $this->on();
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('href="'.route('dotsso.login', ['screen' => 'register']).'"', $html);
        $this->assertStringNotContainsString('href="'.config('ecosystem.signup_url').'"', $html);
    }

    public function test_the_closed_registration_stub_points_at_infodot_not_at_login(): void
    {
        // The test run has registration on, so read the route file's rule rather than the live router.
        $source = file_get_contents(base_path('routes/web.php'));

        $this->assertStringContainsString("Route::redirect('/register', (string) config('ecosystem.signup_url'", $source);
        $this->assertStringNotContainsString("Route::redirect('/register', '/login')", $source);
    }

    public function test_the_team_invitation_email_offers_the_central_create_account_link_only_when_on(): void
    {
        $this->closeRegistration();
        $team = Team::factory()->create();
        $inv = $team->teamInvitations()->create(['email' => 'new@example.test', 'role' => 'editor']);
        $mail = new TeamInvitation($inv);

        $this->assertStringNotContainsString('Create Account', $mail->render());
        $this->on();
        $this->assertStringContainsString(e(route('dotsso.login', ['screen' => 'register'])), $mail->render());
    }

    public function test_with_the_flag_on_the_local_pages_go_to_infodot_sign_in(): void
    {
        $this->on();

        $this->get('/login')->assertRedirect(route('dotsso.login'));
        $this->get('/register')->assertRedirect(route('dotsso.login', ['screen' => 'register']));
        $this->get('/forgot-password')->assertRedirect(route('dotsso.login'));
        $this->post('/login', ['email' => 'a@b.test', 'password' => 'x'])->assertRedirect(route('dotsso.login'));
    }

    public function test_the_break_glass_switch_brings_the_local_login_back(): void
    {
        $this->on();
        config(['dotsso.allow_local_login' => true]);

        $this->get('/login')->assertOk()->assertSee('Remember me');
    }

    public function test_a_signed_out_deep_link_survives_the_round_trip(): void
    {
        $this->on();
        $this->get('/reports')->assertRedirect('/login');
        $this->get('/login')->assertRedirect(route('dotsso.login'));

        $this->signInVia(['sub' => self::UUID])->assertRedirect('/reports');
        $this->assertAuthenticated();
    }

    public function test_a_first_sign_in_creates_the_account_with_the_infodot_id_and_a_personal_team(): void
    {
        $this->on();

        $this->signInVia(['sub' => self::UUID, 'email' => 'new@example.test', 'name' => 'Nia New'])->assertRedirect('/dashboard');

        $user = User::where('email', 'new@example.test')->firstOrFail();
        $this->assertSame(self::UUID, $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Team::where('user_id', $user->id)->where('personal_team', true)->exists());
        $this->assertAuthenticatedAs($user);
    }

    public function test_no_account_is_created_when_provisioning_is_off(): void
    {
        $this->on();
        config(['dotsso.auto_provision' => false]);

        $this->signInVia(['sub' => self::UUID, 'email' => 'new@example.test'])->assertStatus(403);

        $this->assertGuest();
        $this->assertSame(0, User::where('email', 'new@example.test')->count());
    }

    public function test_the_stored_infodot_id_wins_over_a_changed_email(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'old@example.test', 'email_verified_at' => now(), 'infodot_user_uuid' => self::UUID]);

        $this->signInVia(['sub' => self::UUID, 'email' => 'new@example.test'])->assertRedirect('/dashboard');

        $this->assertAuthenticatedAs($user);
        $this->assertSame(1, User::count());
    }

    public function test_a_verified_local_account_is_linked_at_first_sign_in_and_one_linked_elsewhere_is_refused(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => now()]);

        $this->signInVia(['sub' => self::UUID, 'email' => 'ada@example.test'])->assertRedirect('/dashboard');
        $this->assertSame(self::UUID, $user->fresh()->infodot_user_uuid);

        auth()->logout();
        $other = '7b7b7b7b-7b7b-4b7b-8b7b-7b7b7b7b7b7b';
        $this->signInVia(['sub' => $other, 'email' => 'ada@example.test']);
        $this->assertGuest();
        $this->assertSame(self::UUID, $user->fresh()->infodot_user_uuid, 'never re-pointed');
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_local_user_claims_with_a_code_and_keeps_data_and_password(): void
    {
        $this->on();
        $user = User::factory()->withPersonalTeam()->create(['email' => 'ada@example.test', 'email_verified_at' => null, 'password' => Hash::make('old-password-1')]);
        $team = $user->ownedTeams()->first();
        $hash = $user->password;

        $this->signInVia(['sub' => self::UUID])->assertRedirect(route('dotsso.claim'));
        $this->assertGuest();

        $sent = null;
        Mail::assertSent(SsoClaimCodeMail::class, function ($m) use (&$sent) {
            $sent = $m->code;

            return $m->hasTo('ada@example.test');
        });

        $this->get('/sso/claim')->assertOk()->assertSee('Confirm this is your account')->assertDontSee($sent);
        $this->post('/sso/claim', ['code' => $sent])->assertRedirect('/dashboard');

        $user->refresh();
        $this->assertSame(self::UUID, $user->infodot_user_uuid);
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($team->fresh()->is($user->ownedTeams()->first()), 'team membership untouched');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_claim_code_does_not_sign_in_or_link(): void
    {
        $this->on();
        $user = User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);

        $this->signInVia(['sub' => self::UUID])->assertRedirect(route('dotsso.claim'));
        $this->post('/sso/claim', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull($user->fresh()->infodot_user_uuid);
    }

    public function test_the_claim_page_is_one_form_with_a_labelled_code_field_in_the_control_room_shell(): void
    {
        $this->on();
        User::factory()->create(['email' => 'ada@example.test', 'email_verified_at' => null]);
        $this->signInVia(['sub' => self::UUID])->assertRedirect(route('dotsso.claim'));

        $html = $this->get('/sso/claim')->assertOk()->getContent();

        $this->assertStringContainsString('autocomplete="one-time-code"', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertMatchesRegularExpression('/<label[^>]+for="code"/', $html);
        $this->assertStringContainsString('role="alert"', $html);
        $this->assertStringContainsString('--signal', $html);
        $this->assertSame(1, substr_count($html, '<h1'));
    }

    public function test_the_fallback_receiver_also_routes_an_unverified_account_to_the_claim_flow_while_the_flag_is_off(): void
    {
        // Covered with the receiver's own fixtures in InfoDotRedeemTest and SsoReceiverHardeningTest; here only the flag.
        $this->assertFalse(config('dotsso.enabled'));
        $this->assertTrue(Route::has('dotsso.claim'));
    }

    public function test_the_provisioner_never_touches_password_teams_or_two_factor(): void
    {
        $user = User::factory()->withPersonalTeam()->create(['email_verified_at' => null, 'two_factor_secret' => 'enc-secret', 'password' => Hash::make('pw-1234567')]);
        $before = $user->only(['password', 'two_factor_secret', 'current_team_id']);

        $p = new CentralProvisioner;
        $p->link($user, self::UUID);
        $p->confirmEmail($user);

        $user->refresh();
        $this->assertSame($before, $user->only(['password', 'two_factor_secret', 'current_team_id']));
        $this->assertSame(self::UUID, $p->uuidOf($user));
        $this->assertTrue($p->hasVerifiedEmail($user));
    }

    public function test_sanctum_api_access_is_untouched_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        Sanctum::actingAs($user, ['*']);

        $this->getJson('/api/user')->assertOk()->assertJsonPath('email', $user->email);
    }

    public function test_a_real_sanctum_bearer_token_works_on_the_auth_sanctum_route_while_sso_is_on(): void
    {
        $this->on();
        $user = User::factory()->create();
        $token = $user->createToken('t')->plainTextToken;

        $this->getJson('/api/user')->assertUnauthorized();
        $this->withHeader('Authorization', 'Bearer '.$token)->getJson('/api/user')->assertOk();
        // The package default guard is the session guard, never the sanctum one.
        $this->assertSame('web', config('dotsso.guard'));
    }

    public function test_health_receiver_mines_webhook_and_guardian_doors_are_not_intercepted_while_sso_is_on(): void
    {
        $this->on();

        $this->get('/up')->assertOk();
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        // Each door answers with its own reply, never a redirect to the sign-in (an unknown connection is the receiver's own 404).
        $this->postJson('/webhooks/mines/1', [])->assertStatus(404)->assertJsonPath('message', 'Unknown connection.');
        $this->post('/webhooks/mines/1', [])->assertStatus(404);
        $this->assertNotSame(303, $this->get('/guardian/health')->getStatusCode());
        $this->assertNotSame(302, $this->get('/guardian/health')->getStatusCode());
    }
}
