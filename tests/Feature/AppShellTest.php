<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\MinesConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The authenticated shell is built by Vite: no CDN scripts, Alpine comes
 * bundled with Livewire, and there is exactly one navigation.
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    public function test_shell_uses_no_cdn_assets_and_a_single_navigation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringNotContainsString('cdn.tailwindcss.com', $html);
        $this->assertStringNotContainsString('unpkg.com/alpinejs', $html);
        $this->assertSame(1, substr_count($html, 'class="sidebar-nav"'));
        $this->assertStringNotContainsString('x-data="{ open: false }"', $html, 'Jetstream navigation-menu must not render under the shell');
    }

    public function test_shell_speaks_the_control_room_language(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('IBM+Plex+Sans+Condensed', $html);
        $this->assertStringNotContainsString('Syne', $html);
        $this->assertStringNotContainsString('JetBrains', $html);
        $this->assertStringContainsString('class="statebar statebar--idle"', $html, 'a user with no control room gets the idle state');
        $this->assertStringContainsString('NO CONTROL ROOM', $html);
        $this->assertStringContainsString('No open shift', $html);
        $this->assertStringContainsString('Dot.Mines: not connected', $html);
        $this->assertStringContainsString('<html lang="en" class="dark">', $html, 'night is the default');
    }

    public function test_state_bar_reports_open_critical_alerts(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($user->currentTeam)->create();
        Alert::factory()->for($room)->create(['severity' => 'critical']);
        Alert::factory()->for($room)->create(['severity' => 'warning']);

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('statebar--bad', $html);
        $this->assertStringContainsString('NEEDS ATTENTION', $html);
        $this->assertStringContainsString('1 critical alert open, 1 warning.', $html);
    }

    public function test_state_bar_is_quiet_when_nothing_is_open(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($user->currentTeam)->create();
        MinesConnection::factory()->for($room)->verified()->create();

        $html = $this->actingAs($user)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('statebar--ok', $html);
        $this->assertStringContainsString('ALL QUIET', $html);
        $this->assertStringContainsString('Dot.Mines: 1 link verified', $html);
    }

    public function test_navigation_is_grouped_for_the_control_room(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Operate', 'Overview', 'Control Rooms', 'Control', 'Audit Log', 'AI Agents', 'Agents']);
    }
}
