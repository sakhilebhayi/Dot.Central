<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Team;
use App\Models\User;
use App\Services\Guardian\Checks\SsoReceiverCheck;
use App\Support\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * R-52: the sign-in receiver fails loudly and for a named reason (never silently into a login page), the host's
 * setup is checkable, people are matched by InfoDot's user uuid first, and a session that starts here lasts.
 */
class SsoReceiverHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private const ANN = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const BOB = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    private string $secret;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->secret = 'S3cr3t-'.Str::random(40);
        $this->logFile = sys_get_temp_dir().'/central-sso-'.Str::random(8).'.log';

        config([
            'logging.default' => 'sso_test',
            'logging.channels.sso_test' => ['driver' => 'single', 'path' => $this->logFile, 'level' => 'debug'],
            'ecosystem.infodot' => [
                'redeem_url' => self::REDEEM, 'platform' => 'central', 'secret' => $this->secret, 'timeout' => 2,
                'provision' => true, 'entitlement_check' => true,
            ],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function log(): string
    {
        return is_file($this->logFile) ? (string) file_get_contents($this->logFile) : '';
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function origin(string $host = 'infodot.test'): array
    {
        return ['Origin' => 'https://'.$host];
    }

    private function identity(array $user = [], array $teams = [], ?string $current = null): array
    {
        return [
            'platform' => 'central',
            'user' => array_merge(['id' => 7, 'uuid' => self::ANN, 'name' => 'Ann Dlamini', 'email' => 'ann@acme.test', 'email_verified' => true], $user),
            'teams' => $teams ?: [['id' => 1, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'owner', 'entitled' => true]],
            'current_team_id' => 1,
            'current_team_uuid' => $current ?? self::ACME,
        ];
    }

    private function signIn(array $identity, string $token = 'one-time-handoff-token')
    {
        $this->infodotSays([self::REDEEM => Http::response($identity)]);

        return $this->post(route('ecosystem.auth'), ['token' => $token], $this->origin());
    }

    // ------------------------------------------------------------------ distinct, logged, operator-facing failures

    public function test_a_blank_secret_shows_the_operator_page_and_logs_the_reason_without_calling_infodot(): void
    {
        config(['ecosystem.infodot.secret' => '']);
        $this->infodotSays([]);

        $response = $this->post(route('ecosystem.auth'), ['token' => 'tok-blank-secret-1'], $this->origin());

        $response->assertStatus(503)
            ->assertSee('SSO-NOT-CONFIGURED')
            ->assertSee('Dot.Central cannot sign you in through InfoDot right now')
            ->assertSee('Back to InfoDot')
            ->assertSee('INFODOT_SERVICE_SECRET')
            ->assertDontSee('tok-blank-secret-1');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        $this->assertGuest();
        Http::assertNothingSent();

        $this->assertStringContainsString('not_configured', $this->log());
        $this->assertStringContainsString('secret_missing', $this->log());
        $this->assertStringNotContainsString('tok-blank-secret-1', $this->log());
    }

    public function test_a_blank_redeem_url_is_the_same_operator_page(): void
    {
        config(['ecosystem.infodot.redeem_url' => '']);
        $this->infodotSays([]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->origin())->assertStatus(503)->assertSee('SSO-NOT-CONFIGURED')->assertSee('INFODOT_REDEEM_URL');
        $this->assertStringContainsString('redeem_url_missing', $this->log());
    }

    public function test_a_secret_infodot_rejects_shows_the_operator_page_and_never_logs_the_secret_or_token(): void
    {
        $this->infodotSays([self::REDEEM => Http::response(['error' => 'invalid_request'], 401)]);

        $response = $this->post(route('ecosystem.auth'), ['token' => 'tok-wrong-secret-2'], $this->origin());

        $response->assertStatus(503)->assertSee('SSO-SECRET-REJECTED')->assertSee('ECOSYSTEM_SECRET_CENTRAL')->assertDontSee($this->secret)->assertDontSee('tok-wrong-secret-2');
        $this->assertGuest();
        $this->assertStringContainsString('secret_rejected', $this->log());
        $this->assertStringNotContainsString($this->secret, $this->log());
        $this->assertStringNotContainsString('tok-wrong-secret-2', $this->log());
    }

    public function test_a_redeem_host_that_is_not_infodots_host_is_named_not_hidden_behind_the_login_page(): void
    {
        config(['ecosystem.infodot.redeem_url' => 'https://www.infodot.test/api/ecosystem/redeem']);
        $this->infodotSays([]);

        // InfoDot's real host posts; this host was told InfoDot lives at www.: the host's setting is wrong.
        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->origin('infodot.test'))
            ->assertStatus(503)->assertSee('SSO-HOST-MISMATCH')->assertSee('DOT_INFODOT_URL');

        $this->assertGuest();
        $this->assertStringContainsString('redeem_host_mismatch', $this->log());
        $this->assertStringContainsString('"origin_host":"infodot.test"', $this->log());
        Http::assertNothingSent();
    }

    public function test_a_stranger_posting_from_another_site_still_gets_the_quiet_login_page_not_the_operator_page(): void
    {
        $this->infodotSays([]);

        $this->post(route('ecosystem.auth'), ['token' => 'tok-evil'], $this->origin('evil.example'))
            ->assertStatus(303)->assertRedirect('/login')->assertSessionHas('status', fn ($m) => str_contains($m, 'could not sign you in'));

        $this->assertStringContainsString('origin_rejected', $this->log());
        $this->assertStringContainsString('evil.example', $this->log());
        $this->assertGuest();
        Http::assertNothingSent();
    }

    public function test_a_token_infodot_refuses_gives_a_specific_safe_message_on_the_login_page(): void
    {
        $this->infodotSays([self::REDEEM => Http::response(['error' => 'invalid_request'], 403)]);

        $this->post(route('ecosystem.auth'), ['token' => 'tok-used'], $this->origin())
            ->assertStatus(303)->assertRedirect('/login')
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'expired or was already used'));

        $this->assertStringContainsString('token_rejected', $this->log());
        $this->assertStringNotContainsString('tok-used', $this->log());
    }

    public function test_infodot_down_is_a_retry_message_with_its_own_reason(): void
    {
        $this->infodotSays([self::REDEEM => fn () => throw new ConnectionException('timeout')]);

        $this->post(route('ecosystem.auth'), ['token' => 't'], $this->origin())
            ->assertRedirect('/login')->assertSessionHas('status', fn ($m) => str_contains($m, 'unavailable'));

        $this->assertStringContainsString('infodot_unavailable', $this->log());
    }

    public function test_a_person_infodot_cannot_vouch_for_is_told_to_ask_an_administrator_and_nothing_is_created(): void
    {
        $this->signIn($this->identity(['email_verified' => false]))
            ->assertRedirect('/login')->assertSessionHas('status', fn ($m) => str_contains($m, 'ask your administrator'));

        $this->assertGuest();
        $this->assertSame(0, User::count());
        $this->assertStringContainsString('no_account', $this->log());
    }

    public function test_with_provisioning_off_an_unknown_person_creates_nothing(): void
    {
        config(['ecosystem.infodot.provision' => false]);

        $this->signIn($this->identity())->assertRedirect('/login');

        $this->assertSame(0, User::count());
        $this->assertGuest();
    }

    public function test_the_operator_page_shows_no_secret_no_url_and_no_person_detail(): void
    {
        config(['ecosystem.infodot.secret' => '']);

        $body = $this->post(route('ecosystem.auth'), ['token' => 't'], $this->origin())->getContent();

        $this->assertStringNotContainsString(self::REDEEM, $body);
        $this->assertStringNotContainsString('ann@acme.test', $body);
        $this->assertSame(1, substr_count($body, 'data-primary-action'));
    }

    public function test_the_operator_reason_is_logged_once_a_minute_not_once_per_visitor(): void
    {
        config(['ecosystem.infodot.secret' => '']);
        $this->infodotSays([]);

        foreach (range(1, 4) as $i) {
            $this->post(route('ecosystem.auth'), ['token' => 't'.$i], $this->origin())->assertStatus(503);
        }

        $this->assertSame(1, substr_count($this->log(), 'SSO receiver cannot sign anyone in'));
    }

    public function test_no_token_ever_reaches_a_url_or_the_log_on_the_happy_path(): void
    {
        $token = 'tok-happy-'.Str::random(24);

        $response = $this->signIn($this->identity(), $token);

        $response->assertRedirect(route('dashboard'));
        $this->assertStringNotContainsString($token, (string) $response->headers->get('Location'));
        $this->assertStringNotContainsString($token, $this->log());
        $this->assertStringNotContainsString($this->secret, $this->log());
    }

    public function test_a_token_in_a_get_url_is_still_never_read(): void
    {
        $this->infodotSays([]);

        $this->get('/auth/ecosystem?token=abc')->assertRedirect('/login');
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ uuid-first matching

    public function test_the_migration_adds_a_nullable_unique_uuid_and_reverses(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'infodot_user_uuid'));

        User::factory()->create(['email' => 'a@x.test']);
        User::factory()->create(['email' => 'b@x.test']); // two nulls are allowed
        $this->assertSame(2, User::whereNull('infodot_user_uuid')->count());

        $migration = require base_path('database/migrations/2026_11_15_000025_add_infodot_user_uuid_to_users_table.php');
        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'infodot_user_uuid'));
        $migration->down(); // safe twice
        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'infodot_user_uuid'));
        $migration->up(); // safe twice
        $this->assertSame(2, User::count(), 'no row is lost');
    }

    public function test_a_known_uuid_wins_over_the_email_so_a_changed_email_still_signs_in_the_same_person(): void
    {
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@old.test']);
        $ann->forceFill(['infodot_user_uuid' => self::ANN])->save();

        $this->signIn($this->identity(['email' => 'ann@new.test']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($ann);
        $this->assertSame(1, User::count());
        $this->assertSame('ann@old.test', $ann->fresh()->email, 'sign-in never rewrites the email');
    }

    public function test_an_email_match_stamps_the_uuid_and_a_second_sign_in_uses_it(): void
    {
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);

        $this->signIn($this->identity(['email' => 'ANN@acme.test']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($ann);
        $this->assertSame(self::ANN, $ann->fresh()->infodot_user_uuid);
        $this->assertSame(1, User::count());
    }

    public function test_an_account_linked_to_a_different_person_is_never_taken_over_by_email(): void
    {
        $owner = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $owner->forceFill(['infodot_user_uuid' => self::BOB])->save();

        $this->signIn($this->identity())->assertRedirect('/login')
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'ask your administrator'));

        $this->assertGuest();
        $this->assertSame(self::BOB, $owner->fresh()->infodot_user_uuid, 'never re-pointed');
        $this->assertSame(1, User::count());
        $this->assertStringContainsString('identity_conflict', $this->log());
    }

    /** R-54: was "adopted with its password replaced and linked". Now the claim flow: nothing replaced, nothing linked until the code. */
    public function test_an_unverified_local_account_goes_to_the_claim_flow_and_keeps_its_password(): void
    {
        $squatter = User::factory()->withPersonalTeam()->unverified()->create(['email' => 'ann@acme.test', 'password' => bcrypt('attacker-knows-this')]);
        $hash = $squatter->password;

        $this->signIn($this->identity())->assertRedirect(route('dotsso.claim'));

        $squatter->refresh();
        $this->assertGuest();
        $this->assertSame($hash, $squatter->password);
        $this->assertNull($squatter->infodot_user_uuid);
        $this->assertNull($squatter->email_verified_at);
    }

    public function test_a_new_person_is_created_exactly_as_before_with_the_uuid_and_nothing_else_new(): void
    {
        $this->signIn($this->identity())->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertSame(self::ANN, $user->infodot_user_uuid);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame(1, User::count());
        $this->assertSame(1, Team::where('personal_team', true)->count());
    }

    public function test_a_missing_or_malformed_uuid_falls_back_to_the_verified_email(): void
    {
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);

        $this->signIn($this->identity(['uuid' => 'not-a-uuid']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($ann);
        $this->assertNull($ann->fresh()->infodot_user_uuid);
    }

    // ------------------------------------------------------------------ business context, permissions, tenant isolation

    public function test_the_person_lands_in_the_company_infodot_named_with_the_role_it_gave_and_sees_only_its_rooms(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $globex = Team::forceCreate(['user_id' => $other->id, 'name' => 'Globex', 'personal_team' => false, 'infodot_team_uuid' => self::GLOBEX]);
        ControlRoom::factory()->create(['team_id' => $globex->id, 'name' => 'Globex Secret Room']);

        $response = $this->signIn($this->identity(teams: [
            ['id' => 1, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'owner', 'entitled' => true],
        ]));
        $response->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();
        ControlRoom::factory()->create(['team_id' => $acme->id, 'name' => 'Acme North Room']);

        $this->assertSame($acme->id, $ann->current_team_id, 'business context follows InfoDot');
        $this->assertSame(Role::Administrator, $ann->roleOn($acme));
        $this->assertFalse($ann->belongsToTeam($globex), 'a company InfoDot did not list for the person is never attached');
        $this->assertNull($ann->roleOn($globex));

        $this->actingAs($ann->fresh())->get(route('control-rooms.index'))->assertOk()->assertSee('Acme North Room')->assertDontSee('Globex Secret Room');
    }

    public function test_a_member_does_not_become_an_owner_and_gets_the_role_infodot_gave_not_more(): void
    {
        $boss = User::factory()->withPersonalTeam()->create();
        $acme = Team::forceCreate(['user_id' => $boss->id, 'name' => 'Acme Ltd', 'personal_team' => false, 'infodot_team_uuid' => self::ACME]);

        $this->signIn($this->identity(teams: [
            ['id' => 1, 'uuid' => self::ACME, 'name' => 'Acme Ltd', 'personal' => false, 'role' => 'viewer', 'entitled' => true],
        ]))->assertRedirect(route('dashboard'));

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $this->assertTrue($ann->belongsToTeam($acme));
        $this->assertNotSame(Role::Administrator, $ann->roleOn($acme));
        $this->assertSame($boss->id, $acme->fresh()->user_id);
    }

    // ------------------------------------------------------------------ the session lasts

    /** Carry the session cookie the way a browser does, on a real (file) session store. */
    private function browserCall(string $method, string $uri, array $cookies, array $server = []): TestResponse
    {
        $this->app['auth']->forgetGuards();
        $this->app['session']->forgetDrivers();

        return $this->call($method, $uri, [], $cookies, [], $server);
    }

    public function test_a_session_started_by_sso_survives_many_requests_livewire_calls_and_the_ten_minute_entitlement_recheck(): void
    {
        $dir = sys_get_temp_dir().'/central-sessions-'.Str::random(6);
        mkdir($dir);
        config(['session.driver' => 'file', 'session.files' => $dir, 'session.lifetime' => 120]);
        $this->app['session']->forgetDrivers();

        $uuid = self::ACME;
        $recheck = fn (bool $entitled) => Http::response([
            'platform' => 'central', 'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [['key' => 'central', 'name' => 'Dot.Central', 'status' => $entitled ? 'active' : 'expired', 'entitled' => $entitled]],
        ]);

        $this->infodotSays([self::REDEEM => Http::response($this->identity()), 'https://infodot.test/api/ecosystem/companies/*' => $recheck(true)]);

        $response = $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->origin());
        $response->assertRedirect(route('dashboard'));

        $name = config('session.cookie');
        $cookie = $response->getCookie($name, false);
        $this->assertNotNull($cookie, 'the sign-in sets the session cookie');
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $cookies = [$name => $cookie->getValue()];

        // Page loads, a Livewire-style XHR, and a JSON call.
        $this->browserCall('GET', '/dashboard', $cookies)->assertOk();
        $this->browserCall('GET', '/dashboard', $cookies, ['HTTP_X_LIVEWIRE' => 'true'])->assertOk();
        $this->browserCall('GET', route('control-rooms.index'), $cookies, ['HTTP_ACCEPT' => 'application/json'])->assertOk();
        $this->assertAuthenticated();

        // Eleven minutes later the cached answer has expired: InfoDot is asked again, and the person carries on.
        $this->travel(11)->minutes();
        $this->infodotSays(['https://infodot.test/api/ecosystem/companies/*' => $recheck(true)]);
        $this->browserCall('GET', '/dashboard', $cookies)->assertOk();
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/companies/'.self::ACME.'/platforms'));
        $this->browserCall('GET', '/dashboard', $cookies, ['HTTP_X_LIVEWIRE' => 'true'])->assertOk();

        // If InfoDot now says the company no longer holds Central, the Livewire call is refused, not silently run.
        $this->travel(11)->minutes();
        $this->infodotSays(['https://infodot.test/api/ecosystem/companies/*' => $recheck(false)]);
        $this->browserCall('GET', '/dashboard', $cookies, ['HTTP_X_LIVEWIRE' => 'true'])->assertForbidden();

        // And a wrong secret at re-check time (an operator problem) never signs anyone out.
        $this->travel(11)->minutes();
        $this->infodotSays(['https://infodot.test/api/ecosystem/companies/*' => Http::response(['error' => 'x'], 401)]);
        $this->browserCall('GET', '/dashboard', $cookies)->assertOk();

        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    // ------------------------------------------------------------------ the host check

    public function test_the_guardian_report_names_a_missing_secret_and_a_wrong_redeem_host_and_never_prints_the_secret(): void
    {
        config(['guardian.token' => 'guardian-token']);
        $get = fn () => $this->getJson('/guardian/health', ['Authorization' => 'Bearer guardian-token']);

        $ok = $get()->assertOk()->json('checks.sso_receiver_configured');
        $this->assertSame('healthy', $ok['status']);
        $this->assertTrue($ok['metrics']['secret_present']);

        config(['ecosystem.infodot.secret' => '']);
        $bad = $get()->json('checks.sso_receiver_configured');
        $this->assertSame('critical', $bad['status']);
        $this->assertContains('secret_missing', $bad['metrics']['problems']);
        $this->assertFalse($bad['metrics']['secret_present']);

        config(['ecosystem.infodot.secret' => $this->secret, 'ecosystem.infodot.redeem_url' => 'https://203.0.113.9/api/ecosystem/redeem']);
        $mismatch = $get();
        $this->assertContains('redeem_host_mismatch', $mismatch->json('checks.sso_receiver_configured.metrics.problems'));
        $this->assertSame('critical', $mismatch->json('status'));
        $this->assertStringNotContainsString($this->secret, $mismatch->getContent());
    }

    public function test_a_plain_http_redeem_url_in_production_is_a_warning_not_an_outage(): void
    {
        $this->app['env'] = 'production';
        config(['ecosystem.infodot.redeem_url' => 'http://infodot.test/api/ecosystem/redeem']);

        $result = (new SsoReceiverCheck)->run()->toArray();

        $this->assertSame('warning', $result['status']);
        $this->assertContains('redeem_url_not_https', $result['metrics']['problems']);
    }

    public function test_the_public_up_probe_is_unchanged_and_shows_nothing_about_sso(): void
    {
        config(['ecosystem.infodot.secret' => '']);

        $this->get('/up')->assertOk()->assertDontSee('secret', false)->assertDontSee('sso', false);
    }

    public function test_sso_check_fails_on_a_blank_secret_and_prints_no_secret_value(): void
    {
        config(['ecosystem.infodot.secret' => '']);
        $this->assertSame(1, Artisan::call('sso:check'));
        $this->assertStringContainsString('EMPTY', Artisan::output());

        config(['ecosystem.infodot.secret' => $this->secret]);
        $this->assertSame(0, Artisan::call('sso:check'));
        $out = Artisan::output();
        $this->assertStringNotContainsString($this->secret, $out);
        $this->assertStringContainsString(substr(hash('sha256', $this->secret), 0, 8), $out);

        config(['ecosystem.infodot.redeem_url' => 'https://www.infodot.test/api/ecosystem/redeem']);
        $this->assertSame(1, Artisan::call('sso:check'));
        $this->assertStringContainsString('DOT_INFODOT_URL', Artisan::output());
    }

    public function test_the_env_example_documents_the_host_rules(): void
    {
        $example = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString('php artisan sso:check', $example);
        $this->assertStringContainsString('sso_receiver_configured', $example);
        $this->assertStringContainsString('HOST must equal InfoDot', $example);
    }
}
