<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_authenticated_users_can_view_the_dashboard(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $this->actingAs($user)
            ->get('/dashboard')
            ->assertOk()
            ->assertSee('Agent Hub Dashboard');
    }

    public function test_dashboard_links_to_the_real_agent_routes_not_placeholder_links(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('agents.create'), false);
        $response->assertSee(route('agents.index'), false);
    }
}
