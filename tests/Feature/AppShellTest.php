<?php

namespace Tests\Feature;

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

    public function test_navigation_is_grouped_for_the_control_room(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)->get('/dashboard')
            ->assertOk()
            ->assertSeeInOrder(['Operate', 'Control Rooms', 'Control', 'Audit Log', 'AI Agents', 'Agents']);
    }
}
