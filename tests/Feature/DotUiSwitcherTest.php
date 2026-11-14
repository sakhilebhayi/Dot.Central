<?php

namespace Tests\Feature;

use App\Livewire\DotApps;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request as OutgoingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The state bar carries the shared Dot UI platform switcher (dot-ui). Central has no search, so none is added. The
 * switcher is lazy: the page never waits for InfoDot. Once drawn, it only shows what InfoDot says the CURRENT company
 * holds and links through InfoDot's /go (never straight to a host, never with a token).
 */
class DotUiSwitcherTest extends TestCase
{
    use RefreshDatabase;

    private const ACME = '11111111-1111-4111-8111-111111111111';

    private const GLOBEX = '22222222-2222-4222-8222-222222222222';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config([
            'ecosystem.infodot' => ['redeem_url' => 'https://infodot.test/api/ecosystem/redeem', 'platform' => 'central', 'secret' => 'a-test-secret', 'timeout' => 2, 'provision' => true, 'entitlement_check' => false],
            'ecosystem.platforms.infodot.url' => 'https://infodot.test',
        ]);
    }

    private static function url(string $uuid): string
    {
        return 'https://infodot.test/api/ecosystem/companies/'.$uuid.'/platforms*';
    }

    private static function body(string $uuid, array $held = ['central', 'files', 'infodot']): array
    {
        $rows = [];
        foreach (['central' => 'Dot.Central', 'files' => 'Dot.Files', 'finance' => 'Dot.Finance', 'hr' => 'Dot.HR', 'infodot' => 'InfoDot', 'press' => 'Dot.Press'] as $key => $name) {
            $rows[] = ['key' => $key, 'name' => $name, 'status' => 'active', 'entitled' => in_array($key, $held, true), 'is_core' => false, 'access_mode' => 'everyone'];
        }

        return ['platform' => 'central', 'company' => ['uuid' => $uuid, 'personal' => false], 'platforms' => $rows];
    }

    private function fakeInfoDot(array $stubs): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake($stubs);
    }

    private function memberOf(string $uuid, string $name): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = Team::forceCreate(['user_id' => $user->id, 'name' => $name, 'personal_team' => false, 'infodot_team_uuid' => $uuid]);
        $user->forceFill(['current_team_id' => $team->id])->save();

        return $user->fresh();
    }

    /** The drawn switcher only, so assertions cannot be satisfied by the rest of the page. */
    private function switcherHtml(string $html): string
    {
        $this->assertSame(1, preg_match('/<div class="dsw dsw--header".*?<p class="dsw-sr" data-dsw-live[^>]*><\/p>\s*<\/div>\s*<\/div>\s*<\/div>/s', $html, $m), 'the switcher is drawn');

        return $m[0];
    }

    private function drawn(User $user): string
    {
        $this->actingAs($user);
        Livewire::withoutLazyLoading();

        return Livewire::test(DotApps::class)->html();
    }

    // ── The page: placement, no search, never waits for InfoDot ─────────────

    public function test_the_page_never_waits_for_infodot_and_carries_a_plain_link_in_the_switchers_place(): void
    {
        // Any request to InfoDot while the page renders fails the test (stray requests are prevented, nothing is faked).
        $this->fakeInfoDot([]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');

        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        Http::assertNothingSent();
        $this->assertStringContainsString('__lazyLoad', $html, 'the switcher is drawn by its own small request, after the page');
        $this->assertStringContainsString('data-state="loading"', $html);
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $html, 'until then, a plain way to InfoDot');
        $this->assertStringNotContainsString('data-dsw-trigger', $html, 'no half-drawn control');
    }

    public function test_the_switcher_sits_in_the_tools_bay_after_the_state_sentence_which_is_untouched(): void
    {
        $this->fakeInfoDot([]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertSame(1, preg_match('/<span class="statebar__state">[^<]+<\/span>.*?<div class="statebar__bay statebar__bay--tools">\s*<div[^>]*dsw-slot/s', $html), 'state sentence first, switcher first in the tools bay');
        $this->assertSame(1, substr_count($html, 'class="statebar__state"'));
        $this->assertStringContainsString('class="statebar__detail"', $html);
        $this->assertSame(1, preg_match('/dsw-slot.*?<button type="button" class="topbar-btn"/s', $html), 'theme toggle, profile and bell keep their place after it');
    }

    public function test_central_has_no_search_so_none_is_added(): void
    {
        $this->fakeInfoDot([]);
        $html = $this->actingAs($this->memberOf(self::ACME, 'Acme Ltd'))->get(route('dashboard'))->getContent();

        $this->assertStringNotContainsString('role="search"', $html);
        $this->assertStringNotContainsString('dsr-input', $html);
        $this->assertStringNotContainsString('type="search"', $html);
        $this->assertFileExists(public_path('dot-ui/css/dot-search.css'), 'the package copy is whole, even though this platform links only the switcher half');
    }

    public function test_a_guest_sees_no_switcher(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('data-dsw', false)->assertDontSee('dsw-slot', false);
    }

    // ── The drawn switcher ─────────────────────────────────────────────────

    public function test_a_member_sees_what_their_company_holds_with_the_current_platform_marked(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $html = $this->drawn($this->memberOf(self::ACME, 'Acme Ltd'));
        $panel = $this->switcherHtml($html);

        $this->assertStringContainsString('data-state="ok"', $panel);
        $this->assertStringContainsString('data-platform="central"', $panel);
        $this->assertStringContainsString('Acme Ltd', $panel);
        $this->assertSame(1, preg_match('/<div class="dsw-bay is-current" aria-current="page">.*?You are here/s', $panel, $here));
        $this->assertStringContainsString('Dot.Central', $here[0]);
        $this->assertStringContainsString('href="https://infodot.test/go/files?company='.self::ACME.'"', $panel);
        $this->assertStringContainsString('href="https://infodot.test/home"', $panel);
        $this->assertStringNotContainsString('/go/hr', $panel);
        $this->assertStringNotContainsString('/go/press', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms/hr"', $panel);

        $this->assertStringNotContainsString('token', strtolower($panel));
        $this->assertStringNotContainsString('a-test-secret', $html);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href, 'every way out of the switcher goes through InfoDot');
        }
    }

    public function test_it_asks_infodot_about_the_current_company_only_with_the_service_secret(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);

        $this->drawn($this->memberOf(self::ACME, 'Acme Ltd'));

        Http::assertSent(fn (OutgoingRequest $r) => str_contains($r->url(), '/companies/'.self::ACME.'/platforms')
            && $r->hasHeader('Authorization', 'Bearer a-test-secret')
            && $r->hasHeader('X-Dot-Platform', 'central'));
        Http::assertNotSent(fn (OutgoingRequest $r) => str_contains($r->url(), self::GLOBEX));
    }

    public function test_two_companies_never_see_each_others_switcher(): void
    {
        $this->fakeInfoDot([
            self::url(self::ACME) => Http::response(self::body(self::ACME, ['central', 'infodot', 'press'])),
            self::url(self::GLOBEX) => Http::response(self::body(self::GLOBEX, ['central', 'infodot', 'hr'])),
        ]);

        $acme = $this->switcherHtml($this->drawn($this->memberOf(self::ACME, 'Acme Ltd')));
        $globex = $this->switcherHtml($this->drawn($this->memberOf(self::GLOBEX, 'Globex Ltd')));

        $this->assertStringContainsString('/go/press?company='.self::ACME, $acme);
        $this->assertStringNotContainsString('/go/hr', $acme);
        $this->assertStringNotContainsString(self::GLOBEX, $acme);
        $this->assertStringContainsString('/go/hr?company='.self::GLOBEX, $globex);
        $this->assertStringNotContainsString('/go/press', $globex);
        $this->assertStringNotContainsString(self::ACME, $globex);
    }

    public function test_a_503_degrades_the_switcher_and_never_says_active(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response('down', 503)]);

        $panel = $this->switcherHtml($this->drawn($this->memberOf(self::ACME, 'Acme Ltd')));

        $this->assertStringContainsString('data-state="degraded"', $panel);
        $this->assertStringContainsString('Could not check your platforms just now', $panel);
        $this->assertStringNotContainsString('Active', $panel);
        preg_match_all('/href="([^"]+)"/', $panel, $hrefs);
        foreach ($hrefs[1] as $href) {
            $this->assertStringStartsWith('https://infodot.test/', $href);
        }
    }

    public function test_a_connection_failure_never_breaks_it(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => fn () => throw new ConnectionException('refused')]);

        $this->assertStringContainsString('data-state="degraded"', $this->drawn($this->memberOf(self::ACME, 'Acme Ltd')));
    }

    public function test_a_personal_workspace_asks_infodot_nothing(): void
    {
        $this->fakeInfoDot([]);
        $user = User::factory()->withPersonalTeam()->create();

        $panel = $this->switcherHtml($this->drawn($user));

        $this->assertStringContainsString('data-state="personal"', $panel);
        $this->assertStringNotContainsString('dsw-bay', $panel);
        $this->assertStringContainsString('href="https://infodot.test/platforms"', $panel);
        Http::assertNothingSent();
    }

    public function test_a_slow_infodot_answer_is_cached_so_the_next_draw_does_not_ask_again(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');

        $this->drawn($user);
        $this->drawn($user);

        Http::assertSentCount(1);
    }

    // ── Assets: self-hosted, nothing inline, nothing external ───────────────

    public function test_the_shared_assets_are_served_from_this_site_and_resolve(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $user = $this->memberOf(self::ACME, 'Acme Ltd');
        $html = $this->actingAs($user)->get(route('dashboard'))->getContent();

        foreach (['css/dot-fonts.css', 'css/dot-tokens.css', 'css/dot-switcher-fonts.css', 'css/dot-switcher.css', 'js/dot-switcher.js'] as $asset) {
            $this->assertStringContainsString(asset('dot-ui/'.$asset), $html, $asset.' is linked from this site');
            $this->assertFileExists(public_path('dot-ui/'.$asset));
        }

        foreach (glob(public_path('dot-ui/css/*.css')) as $css) {
            preg_match_all('/url\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/', (string) file_get_contents($css), $urls);
            foreach ($urls[1] as $url) {
                $this->assertDoesNotMatchRegularExpression('#^(https?:)?//#', $url, basename($css).' reaches outside this site');
                $this->assertFileExists(dirname($css).'/'.$url, basename($css).' -> '.$url);
            }
        }

        $panel = $this->switcherHtml($this->drawn($user));
        preg_match_all('/<img src="([^"]+)" alt="Dot\./', $panel, $imgs);
        $this->assertNotEmpty($imgs[1]);
        foreach ($imgs[1] as $src) {
            $this->assertStringStartsWith('/dot-ui/img/platforms/', $src);
            $this->assertFileExists(public_path($src));
        }
    }

    public function test_the_shared_markup_has_no_inline_style_or_script(): void
    {
        $this->fakeInfoDot([self::url(self::ACME) => Http::response(self::body(self::ACME))]);
        $panel = $this->switcherHtml($this->drawn($this->memberOf(self::ACME, 'Acme Ltd')));

        $this->assertStringNotContainsString(' style=', $panel);
        $this->assertStringNotContainsString('<style', $panel);
        $this->assertStringNotContainsString('<script', $panel);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/', $panel);
    }

    public function test_the_deploy_serves_dot_ui_from_the_document_root(): void
    {
        $this->assertStringContainsString('build images dot-ui favicon-16x16.png', (string) file_get_contents(base_path('.github/workflows/deploy.yml')));
    }
}
