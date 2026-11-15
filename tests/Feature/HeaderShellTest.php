<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * R-52: Roboto, the shared dot-ui header with a real search on every page, the platform's own sidebar as the phone menu
 * (dot-ui adopt mode), and no external font host.
 */
class HeaderShellTest extends TestCase
{
    use RefreshDatabase;

    private function owner(): User
    {
        $user = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($user->currentTeam)->create(['name' => 'Pit Two']);
        Equipment::factory()->for($room)->create();

        return $user;
    }

    /** @return array<string, string> a page of every kind the shell wraps, by name */
    private function pages(User $user): array
    {
        $room = ControlRoom::withoutGlobalScopes()->where('team_id', $user->current_team_id)->firstOrFail();
        $equipment = Equipment::withoutGlobalScopes()->where('control_room_id', $room->id)->firstOrFail();

        return [
            'overview' => route('dashboard'),
            'rooms' => route('control-rooms.index'),
            'room' => route('control-rooms.show', $room),
            'equipment' => route('control-rooms.equipment.index', $room),
            'machine' => route('control-rooms.equipment.show', [$room, $equipment]),
            'admin' => route('control-rooms.admin', $room),
            'alerts' => route('alerts.index'),
            'exceptions' => route('exceptions.index'),
            'reports' => route('reports.index'),
            'notifications' => route('notifications.index'),
            'agents' => route('agents.hub'),
            'profile' => route('profile.show'),
            'search' => route('search', ['q' => 'ht']),
        ];
    }

    public function test_the_shared_header_renders_with_logo_state_search_tools_and_account(): void
    {
        $user = $this->owner();
        $html = $this->actingAs($user)->get(route('dashboard'))->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, '<header '), 'one header');
        $this->assertStringContainsString('class="dh', $html);
        $this->assertStringContainsString('aria-label="Dot.Central, overview"', $html, 'the logo links home');
        $this->assertStringContainsString('images/logo-light.png', $html, 'the original logo files, not redrawn');
        $this->assertStringContainsString('class="statebar statebar--', $html, 'the state of the room stays in the header');
        $this->assertStringContainsString('class="dh-account"', $html);
        $this->assertStringContainsString('Profile &amp; settings', $html);
        $this->assertStringContainsString('Team &amp; roles', $html);
        $this->assertSame(1, substr_count($html, 'class="dh-skip skip-link"'), 'one skip link');
        $this->assertStringContainsString('class="dh-signout"', $html, 'sign out is in the account menu');
    }

    public function test_the_search_is_in_the_header_on_every_kind_of_page(): void
    {
        $user = $this->owner();

        foreach ($this->pages($user) as $name => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $header = substr($html, (int) strpos($html, '<header '), (int) strpos($html, '</header>') - (int) strpos($html, '<header '));

            $this->assertStringContainsString('role="search"', $header, "search missing on $name");
            $this->assertStringContainsString('class="dsr-input"', $header, "pill missing on $name");
            $this->assertStringContainsString('data-dh-search-toggle', $header, "mobile search button missing on $name");
            $this->assertStringContainsString('action="'.route('search').'"', $header, "search does not submit to the results page on $name");
        }
    }

    public function test_the_phone_menu_is_the_platforms_own_sidebar_in_adopt_mode(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('data-dh-menu-button aria-expanded="false" aria-controls="dot-sidebar"', $html);
        $this->assertStringContainsString('id="dot-sidebar"', $html);
        $this->assertStringContainsString('data-dh-close', $html, 'a close button in the sidebar');
        $this->assertStringNotContainsString('class="dh-drawer', $html, 'no second drawer');
        $this->assertSame(1, substr_count($html, 'class="sidebar-nav"'), 'one navigation');
        $this->assertStringNotContainsString('sidebar-toggle', $html, 'the old hamburger and its inline onclick are gone');
        $this->assertStringNotContainsString('onclick=', $html);
    }

    public function test_roboto_is_declared_and_its_files_are_served_from_this_site(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        // dot-ui 0.6.1: Roboto, the type scale, tokens, search, switcher and header are one stylesheet.
        $this->assertStringContainsString('dot-ui/css/dot-ui.min.css', $html);
        $this->assertMatchesRegularExpression('/<html [^>]*class="[^"]*dot-roboto/', $html);
        $this->assertStringNotContainsString('as="font"', $html, 'no font preload: it delayed the dashboard text LCP (dot-ui 0.6.1 guidance)');

        $css = (string) file_get_contents(public_path('dot-ui/css/dot-roboto.css'));
        $this->assertStringContainsString("font-family: 'Roboto'", $css);
        foreach (['roboto-latin-wght-normal.woff2', 'roboto-latin-ext-wght-normal.woff2', 'OFL.txt'] as $file) {
            $this->assertFileExists(public_path('dot-ui/fonts/roboto/'.$file));
        }
        $this->assertFileExists(resource_path('fonts/material-symbols-rounded.woff2'), 'the icon font is self-hosted');
    }

    public function test_no_page_layout_loads_a_font_from_another_host(): void
    {
        $user = $this->owner();
        $pages = $this->pages($user) + ['login' => route('login'), 'welcome' => '/'];
        foreach ($pages as $name => $url) {
            $html = $this->actingAs($user)->get($url)->getContent();
            $this->assertDoesNotMatchRegularExpression('#fonts\.(googleapis|gstatic)\.com|fonts\.bunny\.net|use\.typekit|IBM\+Plex|IBM Plex#i', $html, "external font on $name");
        }

        $this->auth_free_pages();

        $css = glob(public_path('build/assets/app-*.css'))[0] ?? null;
        $this->assertNotNull($css, 'npm run build was run');
        $built = (string) file_get_contents($css);
        $this->assertStringNotContainsString('IBM Plex', $built);
        $this->assertDoesNotMatchRegularExpression('#https?://(?!www\.w3\.org/)#', (string) preg_replace('#/\*.*?\*/#s', '', $built), 'the app CSS names no external host (the w3.org namespace in an SVG is not a request)');
    }

    private function auth_free_pages(): void
    {
        foreach ([view('errors.404')->render(), view('errors.500')->render()] as $html) {
            $this->assertDoesNotMatchRegularExpression('#googleapis|gstatic|IBM\+Plex#i', $html);
            $this->assertStringContainsString('dot-ui/css/dot-roboto.min.css', $html);
        }
    }

    public function test_the_header_and_search_carry_no_inline_style_or_script(): void
    {
        $user = $this->owner();
        foreach (['overview' => route('dashboard'), 'search' => route('search', ['q' => 'ht'])] as $name => $url) {
            $html = $this->actingAs($user)->get($url)->assertOk()->getContent();
            $head = substr($html, 0, (int) strpos($html, '<main'));
            // Livewire prints its own small <style> in <head> (@livewireStyles): the framework's, not the page's.
            $head = (string) preg_replace('#<!-- Livewire Styles --><style[^>]*>.*?</style>#s', '', $head);

            $this->assertDoesNotMatchRegularExpression('/<script(?![^>]*\bsrc=)[^>]*>/i', $head, "inline script on $name");
            $this->assertStringNotContainsString('<style', $head, "inline style block on $name");
            $shell = substr($html, (int) strpos($html, '<header '), (int) strpos($html, '<main') - (int) strpos($html, '<header '));
            $this->assertStringNotContainsString(' style="', $shell, "inline style attribute in the header/sidebar on $name");
            $foot = substr($html, (int) strpos($html, '<footer class="shellfoot"'));
            $this->assertStringNotContainsString(' style="', substr($foot, 0, (int) strpos($foot, '</footer>')), "inline style in the foot on $name");
        }
    }

    public function test_the_theme_script_is_an_external_file_and_the_toggle_has_a_hook(): void
    {
        $this->withVite();
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<script src="[^"]*theme-init[^"]*\.js"></script>#', $html);
        $this->assertStringContainsString('data-theme-toggle', $html);
        $this->assertStringNotContainsString('dotToggleTheme', $html);
    }

    public function test_the_header_scripts_are_linked_from_this_site(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        // One script holds search, switcher and nav (the results controller is lazy).
        $this->assertStringContainsString('dot-ui/js/dot-ui-core.min.js', $html);
        $this->assertFileExists(public_path('dot-ui/js/dot-ui-core.min.js'));
        $this->assertStringContainsString('dot-ui/css/dot-ui.min.css', $html);
    }

    public function test_the_state_word_has_a_short_form_for_the_narrowest_phones(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('statebar__long', $html);
        $this->assertStringContainsString('statebar__short', $html);
    }

    public function test_the_phone_navigation_carries_the_account_items_the_header_menu_has_on_a_wide_screen(): void
    {
        $html = $this->actingAs($this->owner())->get(route('dashboard'))->assertOk()->getContent();
        $sidebar = substr($html, (int) strpos($html, '<aside'), (int) strpos($html, '</aside>') - (int) strpos($html, '<aside'));

        $this->assertStringContainsString('class="nav-account"', $sidebar);
        $this->assertStringContainsString(route('notifications.index'), $sidebar);
        $this->assertStringContainsString(route('profile.show'), $sidebar);
        $this->assertStringContainsString('action="'.route('logout').'"', $sidebar);
    }
}
