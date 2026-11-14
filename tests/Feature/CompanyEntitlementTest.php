<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureCompanyEntitled;
use App\Models\ControlRoom;
use App\Models\Team;
use App\Models\User;
use App\Services\InfoDot\EntitlementClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Livewire\Mechanisms\PersistentMiddleware\PersistentMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * R-50: Dot.Central honours the company's entitlement. At sign-in only companies InfoDot marks entitled are admitted;
 * during a session the signed-in area asks InfoDot whether the current company still holds the platform. A clear
 * "no" is the plain 403 page; every failure lets the person carry on. Response bodies follow InfoDot's own tests
 * (CompanyPlatformsEndpointTest, the redeem controller).
 */
class CompanyEntitlementTest extends TestCase
{
    use RefreshDatabase;

    private const REDEEM = 'https://infodot.test/api/ecosystem/redeem';

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    private string $credential;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credential = Str::random(40);

        config(['ecosystem.infodot' => [
            'redeem_url' => self::REDEEM, 'platform' => 'central', 'secret' => $this->credential, 'timeout' => 2, 'provision' => true,
            'entitlement_check' => true,
        ]]);
        config(['ecosystem.platforms.infodot.url' => 'https://infodot.test']);
    }

    // ------------------------------------------------------------------ helpers

    private static function readUrl(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    /** The read endpoint's body, as InfoDot's CompanyPlatformsEndpointTest asserts it. */
    private static function platforms(string $uuid, bool $entitled, string $status): array
    {
        return [
            'platform' => 'central',
            'company' => ['uuid' => $uuid, 'personal' => false],
            'platforms' => [
                ['key' => 'finance', 'name' => 'Dot.Finance', 'status' => 'included', 'entitled' => true, 'is_core' => true],
                ['key' => 'central', 'name' => 'Dot.Central', 'status' => $status, 'entitled' => $entitled, 'is_core' => false, 'access_mode' => 'everyone'],
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'free_while_pricing_finalised', 'entitled' => false, 'is_core' => false],
                ['key' => 'infodot', 'name' => 'InfoDot', 'status' => 'included', 'entitled' => true, 'is_core' => true],
            ],
        ];
    }

    private function infodotSays(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    /** @return array{0: User, 1: Team, 2: ControlRoom} an owner, their InfoDot company, and a control room in it */
    private function company(string $uuid = self::ACME, string $name = 'Acme Ltd', ?User $owner = null): array
    {
        $owner ??= User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $owner->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $room = ControlRoom::factory()->create(['team_id' => $team->id, 'name' => 'North Pit Control']);

        return [$owner->fresh(), $team, $room];
    }

    private function fromInfodot(): array
    {
        return ['Origin' => 'https://infodot.test'];
    }

    private function redeemTeam(string $uuid, string $name, string $role, ?bool $entitled = null, ?string $access = null, bool $personal = false): array
    {
        $team = ['id' => random_int(1, 999), 'uuid' => $uuid, 'name' => $name, 'personal' => $personal, 'role' => $role];

        return $entitled === null ? $team : $team + ['entitled' => $entitled, 'access' => $access];
    }

    private function signIn(string $email, array $teams, ?string $current = null)
    {
        $this->infodotSays([self::REDEEM => Http::response([
            'platform' => 'central',
            'user' => ['id' => 1, 'uuid' => (string) Str::uuid(), 'name' => ucfirst(strstr($email, '@', true)), 'email' => $email, 'email_verified' => true],
            'teams' => $teams,
            'current_team_id' => 1,
            'current_team_uuid' => $current,
        ])]);

        auth()->logout();

        return $this->post(route('ecosystem.auth'), ['token' => 'one-time'], $this->fromInfodot());
    }

    // ------------------------------------------------------------------ sign-in (redeem)

    public function test_a_company_marked_entitled_is_admitted_at_sign_in(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true)], self::ACME)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();
        $team = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame($team->id, $user->current_team_id);
    }

    public function test_a_company_marked_not_entitled_is_not_created_updated_or_made_current(): void
    {
        // Globex exists here already under an older name, with Ann as a viewer working in it.
        [$globexOwner, $globex] = $this->company(self::GLOBEX, 'Globex (old name)');
        $ann = User::factory()->withPersonalTeam()->create(['email' => 'ann@acme.test']);
        $globex->users()->attach($ann->id, ['role' => 'viewer']);
        $ann->forceFill(['current_team_id' => $globex->id])->save();

        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', true),
            $this->redeemTeam(self::GLOBEX, 'Globex Corporation', 'admin', false, 'suspended'),
            $this->redeemTeam('33333333-3333-4333-8333-333333333333', 'Initech', 'owner', false, 'not_activated'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $ann->refresh();
        $acme = Team::where('infodot_team_uuid', self::ACME)->firstOrFail();

        $this->assertNull(Team::where('infodot_team_uuid', '33333333-3333-4333-8333-333333333333')->first(), 'a company that does not hold the platform is not created');
        $this->assertSame('Globex (old name)', $globex->fresh()->name, 'nor renamed');
        $this->assertSame('viewer', $globex->users()->where('users.id', $ann->id)->first()->membership->role, 'nor is the role changed');
        $this->assertTrue($ann->belongsToTeam($globex), 'membership is left as it was');
        $this->assertSame($acme->id, $ann->current_team_id, 'and it cannot be the current company');
    }

    public function test_without_the_key_sign_in_behaves_as_before(): void
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner'),
            $this->redeemTeam(self::GLOBEX, 'Globex', 'admin'),
        ], self::GLOBEX)->assertRedirect(route('dashboard'));

        $user = User::where('email', 'ann@acme.test')->firstOrFail();

        $this->assertNotNull(Team::where('infodot_team_uuid', self::ACME)->first());
        $this->assertSame(Team::where('infodot_team_uuid', self::GLOBEX)->firstOrFail()->id, $user->current_team_id);
    }

    public function test_when_no_company_is_entitled_the_person_gets_the_plain_page_and_is_not_signed_in(): void
    {
        $response = $this->signIn('ann@acme.test', [
            $this->redeemTeam('44444444-4444-4444-8444-444444444444', "Ann's Team", 'owner', false, 'no_personal_access', personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', false, 'payment_required'),
        ], self::ACME);

        $response->assertStatus(403)
            ->assertSee('Dot.Central needs a payment for Acme Ltd')
            ->assertSee('href="https://infodot.test/platforms/central"', false)
            ->assertSee('Open Platforms in InfoDot')
            ->assertDontSee('Whoops')
            ->assertDontSee('Stack trace');

        $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'));
        $this->assertGuest();
        $this->assertNull(User::where('email', 'ann@acme.test')->first(), 'no account is created for nothing');
        $this->assertSame(0, Team::whereNotNull('infodot_team_uuid')->count());
    }

    public function test_the_page_escapes_the_company_name(): void
    {
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::ACME, '<script>alert(1)</script>', 'owner', false, 'not_activated')], self::ACME)
            ->assertStatus(403)
            ->assertDontSee('<script>alert(1)</script>', false);
    }

    // ------------------------------------------------------------------ during a session

    public function test_an_entitled_company_carries_on(): void
    {
        [$owner, $team, $room] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk()->assertSee('North Pit Control');

        Http::assertSent(fn (Request $request) => $request->method() === 'GET'
            && str_starts_with($request->url(), 'https://infodot.test/api/ecosystem/companies/'.self::ACME.'/platforms')
            && $request['platform'] === 'central'
            && $request->hasHeader('X-Dot-Platform', 'central')
            && $request->hasHeader('Authorization', 'Bearer '.$this->credential));
    }

    public static function refusals(): array
    {
        return [
            'not switched on' => ['free_while_pricing_finalised', 'Dot.Central is not switched on for Acme Ltd'],
            'suspended' => ['suspended', 'Dot.Central is suspended for Acme Ltd'],
            'payment needed' => ['payment_required', 'Dot.Central needs a payment for Acme Ltd'],
            'ended' => ['subscription_expired', 'Dot.Central has ended for Acme Ltd'],
        ];
    }

    #[DataProvider('refusals')]
    public function test_a_company_that_no_longer_holds_the_platform_gets_403_and_no_data(string $status, string $title): void
    {
        [$owner, $team, $room] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, $status))]);

        foreach ([route('dashboard'), route('control-rooms.index'), route('control-rooms.show', $room), route('alerts.index'), route('teams.show', $team)] as $url) {
            $response = $this->actingAs($owner)->get($url)->assertStatus(403)
                ->assertSee($title)
                ->assertSee('href="https://infodot.test/platforms/central"', false)
                ->assertDontSee('North Pit Control')
                ->assertDontSee($room->slug);

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        // Nothing is changed either: no room is added, none is removed or renamed.
        $this->actingAs($owner)->post(route('control-rooms.store'), ['name' => 'Another Room'])->assertStatus(403);
        $this->actingAs($owner)->delete(route('control-rooms.destroy', $room))->assertStatus(403);
        $this->assertSame(1, ControlRoom::withoutGlobalScopes()->count());
        $this->assertSame('North Pit Control', $room->fresh()->name, 'the data is untouched');
    }

    public function test_an_entitled_company_can_still_change_its_data(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->post(route('control-rooms.store'), ['name' => 'Second Room'])->assertRedirect();

        $this->assertSame(2, ControlRoom::withoutGlobalScopes()->where('team_id', $team->id)->count());
    }

    public static function failures(): array
    {
        return [
            'bad secret (401)' => [fn () => Http::response(['error' => 'invalid_request'], 401)],
            'platform switched off (403)' => [fn () => Http::response(['error' => 'invalid_request'], 403)],
            'unknown company (404)' => [fn () => Http::response(['error' => 'not_found'], 404)],
            'throttled (429)' => [fn () => Http::response('', 429)],
            'server error (500)' => [fn () => Http::response('boom', 500)],
            'not json' => [fn () => Http::response('<html>maintenance</html>', 200)],
            'no platforms list' => [fn () => Http::response(['platform' => 'central', 'company' => ['uuid' => self::ACME, 'personal' => false]])],
            'own row missing' => [fn () => Http::response(['platform' => 'central', 'company' => ['uuid' => self::ACME, 'personal' => false], 'platforms' => [
                ['key' => 'hr', 'name' => 'Dot.HR', 'status' => 'suspended', 'entitled' => false, 'is_core' => false],
            ]])],
            'entitled is not a boolean' => [fn () => Http::response(['platform' => 'central', 'platforms' => [['key' => 'central', 'status' => 'suspended', 'entitled' => 'false']]])],
            'answer for another platform' => [fn () => Http::response(['platform' => 'hr', 'platforms' => [['key' => 'central', 'status' => 'suspended', 'entitled' => false]]])],
            'connection error or timeout' => [fn () => fn () => throw new ConnectionException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function test_any_failure_lets_the_person_carry_on_and_is_logged_once(Closure $answer): void
    {
        [$owner, $team] = $this->company();
        $calls = 0;
        $reply = $answer();
        $this->infodotSays([self::readUrl(self::ACME) => function () use (&$calls, $reply) {
            $calls++;

            return $reply instanceof Closure ? $reply() : $reply;
        }]);
        Log::spy();

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk()->assertSee('North Pit Control');
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        $this->assertSame(1, $calls, 'one call for the cache window');
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'InfoDot entitlement check gave no answer'));
    }

    public function test_a_failure_is_remembered_for_a_minute_and_an_answer_for_ten(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push('boom', 500)
            ->push(self::platforms(self::ACME, false, 'suspended'))
            ->push(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk();

        $this->travel(EntitlementClient::FAILURE_TTL + 1)->seconds();
        $this->actingAs($owner)->get(route('control-rooms.index'))->assertStatus(403);

        $this->travel(EntitlementClient::ANSWER_TTL - 5)->seconds();
        $this->actingAs($owner)->get(route('control-rooms.index'))->assertStatus(403);
        Http::assertSentCount(2);

        $this->travel(10)->seconds();
        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk();
        Http::assertSentCount(3);
    }

    public function test_no_secret_configured_carries_on_without_calling(): void
    {
        [$owner, $team] = $this->company();
        config(['ecosystem.infodot.secret' => null]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_answer_is_cached_per_company(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertSentCount(1);
    }

    public function test_with_the_flag_off_no_call_is_made(): void
    {
        [$owner, $team] = $this->company();
        config(['ecosystem.infodot.entitlement_check' => false]);
        $this->infodotSays([]);

        $this->actingAs($owner)->get(route('control-rooms.index'))->assertOk();
        $this->actingAs($owner)->get(route('dashboard'))->assertOk();

        Http::assertNothingSent();
    }

    public function test_the_flag_is_on_by_default(): void
    {
        $this->assertTrue((require config_path('ecosystem.php'))['infodot']['entitlement_check']);
        $this->assertStringContainsString('CENTRAL_ENTITLEMENT_CHECK=true', file_get_contents(base_path('.env.example')));
    }

    public function test_a_personal_workspace_or_a_company_infodot_does_not_know_is_not_asked_about(): void
    {
        $this->infodotSays([]);

        $solo = User::factory()->withPersonalTeam()->create();
        $this->actingAs($solo)->get(route('dashboard'))->assertOk();

        $local = Team::forceCreate(['user_id' => $solo->id, 'name' => 'Local Co', 'personal_team' => false]);
        $solo->forceFill(['current_team_id' => $local->id])->save();
        $this->actingAs($solo->fresh())->get(route('control-rooms.index'))->assertOk();

        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ personal workspaces (individuals hold the free core only)

    private const PERSONAL = '44444444-4444-4444-8444-444444444444';

    /** Ann signs in with one entitled company and a personal workspace marked as given, then works in her local personal team. */
    private function signInThenWorkPersonally(?bool $personalEntitled): array
    {
        $this->signIn('ann@acme.test', [
            $this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', $personalEntitled, $personalEntitled === false ? 'no_personal_access' : null, personal: true),
            $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner', $personalEntitled === null ? null : true),
        ], self::ACME)->assertRedirect(route('dashboard'));

        // The test client does not carry the session between requests; hand over what sign-in remembered.
        $this->withSession(session()->has(EnsureCompanyEntitled::PERSONAL) ? [EnsureCompanyEntitled::PERSONAL => session(EnsureCompanyEntitled::PERSONAL)] : []);

        $ann = User::where('email', 'ann@acme.test')->firstOrFail();
        $personal = $ann->personalTeam();
        $room = ControlRoom::factory()->create(['team_id' => $personal->id, 'name' => 'Private Depot Room']);
        $ann->forceFill(['current_team_id' => $personal->id])->save();

        $this->actingAs($ann->fresh());

        return [$ann->fresh(), $personal, $room, Team::where('infodot_team_uuid', self::ACME)->firstOrFail()];
    }

    public function test_a_personal_workspace_infodot_marked_not_entitled_is_refused_without_asking_the_read_endpoint(): void
    {
        [$ann, $personal, $room, $acme] = $this->signInThenWorkPersonally(false);
        $this->assertFalse(session(EnsureCompanyEntitled::PERSONAL));

        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        foreach ([route('dashboard'), route('control-rooms.index'), route('control-rooms.show', $room)] as $url) {
            $response = $this->get($url)->assertStatus(403)
                ->assertSee('Dot.Central needs a company that has switched it on')
                ->assertSee('href="https://infodot.test/platforms/central"', false)
                ->assertSee('name="team_id" value="'.$acme->id.'"', false)
                ->assertDontSee('Private Depot Room');

            $this->assertSame(1, substr_count($response->getContent(), 'data-primary-action'), $url);
        }

        $this->post(route('control-rooms.store'), ['name' => 'Another Room'])->assertStatus(403);
        Http::assertNothingSent();

        // Nothing in the workspace is deleted or altered.
        $this->assertSame(1, ControlRoom::withoutGlobalScopes()->where('team_id', $personal->id)->count());
        $this->assertSame('Private Depot Room', $room->fresh()->name);
        $this->get(route('profile.show'))->assertOk();

        // The entitled company can still be switched to.
        $this->put(route('current-team.update'), ['team_id' => $acme->id])->assertRedirect();
        $this->get(route('dashboard'))->assertOk();
    }

    public function test_a_team_with_no_infodot_company_is_refused_the_same_way(): void
    {
        [$ann] = $this->signInThenWorkPersonally(false);
        $local = Team::forceCreate(['user_id' => $ann->id, 'name' => 'Local Co', 'personal_team' => false]);
        $ann->forceFill(['current_team_id' => $local->id])->save();
        $this->infodotSays([]);

        $this->get(route('control-rooms.index'))->assertStatus(403)->assertSee('Dot.Central needs a company that has switched it on');
        Http::assertNothingSent();
    }

    public function test_a_personal_workspace_infodot_marked_entitled_carries_on(): void
    {
        [$ann, $personal] = $this->signInThenWorkPersonally(true);
        $this->assertTrue(session(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('control-rooms.index'))->assertOk()->assertSee('Private Depot Room');
        Http::assertNothingSent();
    }

    public function test_with_no_key_at_sign_in_nothing_is_remembered_and_the_personal_workspace_carries_on(): void
    {
        [$ann, $personal] = $this->signInThenWorkPersonally(null);
        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
        $this->infodotSays([]);

        $this->get(route('dashboard'))->assertOk();
        $this->get(route('control-rooms.index'))->assertOk()->assertSee('Private Depot Room');
        Http::assertNothingSent();
    }

    public function test_a_later_sign_in_without_the_key_forgets_an_earlier_refusal(): void
    {
        $this->signInThenWorkPersonally(false);
        $this->signIn('ann@acme.test', [$this->redeemTeam(self::PERSONAL, "Ann's Team", 'owner', personal: true), $this->redeemTeam(self::ACME, 'Acme Ltd', 'owner')], self::ACME)->assertRedirect();

        $this->assertFalse(session()->has(EnsureCompanyEntitled::PERSONAL));
    }

    public function test_the_personal_refusal_is_off_with_the_flag(): void
    {
        [$ann, $personal] = $this->signInThenWorkPersonally(false);
        config(['ecosystem.infodot.entitlement_check' => false]);

        $this->get(route('control-rooms.index'))->assertOk();
    }

    /** A real Livewire update request (not the test harness, which skips persistent middleware) for the team-name form. */
    private function livewireSnapshot(Team $team): string
    {
        $page = $this->get(route('teams.show', $team))->assertOk()->getContent();
        preg_match_all('/wire:snapshot="([^"]+)"/', $page, $all);
        $snapshot = '';

        foreach ($all[1] as $encoded) {
            $decoded = html_entity_decode($encoded, ENT_QUOTES);

            if (str_contains($decoded, 'update-team-name-form')) {
                $snapshot = $decoded;
            }
        }

        $this->assertNotSame('', $snapshot, 'the team name form is on the page');

        return $snapshot;
    }

    private function livewireRename(string $snapshot, string $name)
    {
        return $this->postJson(Livewire::getUpdateUri(), ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['state.name' => $name],
            'calls' => [['path' => '', 'method' => 'updateTeamName', 'params' => []]],
        ]]], ['X-Livewire' => 'true']);
    }

    public function test_a_livewire_update_is_refused_once_the_company_loses_the_platform(): void
    {
        [$owner, $team] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::sequence()
            ->push(self::platforms(self::ACME, true, 'active'))
            ->push(self::platforms(self::ACME, false, 'suspended'))]);
        $this->actingAs($owner);
        $snapshot = $this->livewireSnapshot($team);

        // While the company holds the platform the action works.
        $worked = $this->livewireRename($snapshot, 'Acme Mining');
        $this->assertNotSame(403, $worked->getStatusCode());
        $this->assertSame('Acme Mining', $team->fresh()->name);

        // The cached answer expires, InfoDot now says no: the same action is refused and returns no component.
        $this->travel(EntitlementClient::ANSWER_TTL + 1)->seconds();
        $response = $this->livewireRename($snapshot, 'Hijacked Name');

        $this->assertSame(403, $response->getStatusCode());
        // Central answers an abort with its own error page (not JSON), so the body is checked as text.
        $this->assertStringNotContainsString('"components"', (string) $response->getContent());
        $this->assertStringNotContainsString('snapshot', (string) $response->getContent());
        $this->assertSame('Acme Mining', $team->fresh()->name, 'the data is unchanged');
    }

    public function test_the_overview_page_that_hosts_the_live_components_is_refused_not_rendered(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended'))]);

        $response = $this->actingAs($owner)->get(route('dashboard'))->assertStatus(403);

        $this->assertStringNotContainsString('wire:snapshot', $response->getContent());
    }

    public function test_exempt_routes_make_no_call(): void
    {
        [$owner] = $this->company();
        $this->infodotSays([]);

        // Signed out: the public pages, health, sign-in, the handoff receiver's legacy GET, and the machine routes
        // (Guardian report, scheduler probe, Mines webhook) all answer without asking InfoDot.
        $this->get('/')->assertOk();
        $this->get('/login')->assertOk();
        $this->get('/cookies')->assertOk();
        $this->get('/up')->assertOk();
        $this->get('/up/scheduler');
        $this->get('/auth/ecosystem')->assertRedirect('/login');
        $this->get('/guardian/health');
        $this->postJson('/webhooks/mines/1');

        // Signed in, in a company that would be refused if anyone asked: the same routes, the person's own account,
        // and signing out.
        $this->actingAs($owner);
        $this->get('/')->assertOk();
        $this->get(route('profile.show'))->assertOk();
        $this->get('/up')->assertOk();
        $this->post(route('logout'))->assertRedirect();

        Http::assertNothingSent();
    }

    public function test_a_second_company_that_is_entitled_can_still_be_switched_to(): void
    {
        [$owner, $acme] = $this->company();
        [, $globex] = $this->company(self::GLOBEX, 'Globex Corporation', $owner);
        $owner->forceFill(['current_team_id' => $acme->id])->save();

        $this->infodotSays([
            self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, false, 'suspended')),
            self::readUrl(self::GLOBEX) => Http::response(self::platforms(self::GLOBEX, true, 'active')),
        ]);

        // The refusal names the other company and offers the switch.
        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertStatus(403)
            ->assertSee('Globex Corporation')
            ->assertSee('action="'.route('current-team.update').'"', false)
            ->assertSee('name="team_id" value="'.$globex->id.'"', false);

        $this->put(route('current-team.update'), ['team_id' => $globex->id])->assertRedirect();
        $this->assertSame($globex->id, $owner->fresh()->current_team_id);

        $this->actingAs($owner->fresh())->get(route('dashboard'))->assertOk();
        $this->get(route('control-rooms.index'))->assertOk();

        // The suspended company's book stays shut even by its address.
        $this->get(route('teams.show', $acme))->assertStatus(403)->assertSee('Dot.Central is suspended for Acme Ltd');
    }

    public function test_another_companys_book_is_never_opened_or_asked_about_by_naming_it_in_the_address(): void
    {
        [$owner] = $this->company();
        [, $other] = $this->company(self::GLOBEX, 'Globex Corporation');
        $this->infodotSays([self::readUrl(self::ACME) => Http::response(self::platforms(self::ACME, true, 'active'))]);

        $this->actingAs($owner)->get(route('teams.show', $other))->assertForbidden()->assertDontSee('Globex Corporation');

        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), self::GLOBEX));
    }

    public function test_livewire_updates_from_the_signed_in_area_carry_the_check(): void
    {
        $this->assertContains(EnsureCompanyEntitled::class, app(PersistentMiddleware::class)->getPersistentMiddleware());
    }
}
