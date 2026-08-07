<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for the mining-dispatch control-room domain: index/show
 * views load, a control room can be created, and a control room only
 * scoped to the current team's members is browsable — mirroring the
 * team-based tenancy already used across the rest of this app.
 */
class ControlRoomTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/control-rooms')->assertRedirect('/login');
    }

    public function test_index_shows_the_current_teams_control_rooms_and_summary_kpis(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $controlRoom = ControlRoom::factory()->for($team)->create(['name' => 'Kolomela Control Room']);
        DispatchDecision::factory()->for($controlRoom)->for($user, 'decidedBy')->create();
        Alert::factory()->for($controlRoom)->create();
        OperatorSession::factory()->for($controlRoom)->for($user, 'operator')->create();

        $this->actingAs($user)
            ->get('/control-rooms')
            ->assertOk()
            ->assertSee('Kolomela Control Room')
            ->assertSee('Active Control Rooms')
            ->assertSee('Open Alerts');
    }

    public function test_user_can_create_a_control_room(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post('/control-rooms', [
            'name' => 'New Site Control Room',
            'mines_site_ref' => 'kolomela',
        ]);

        $this->assertDatabaseHas('control_rooms', [
            'name' => 'New Site Control Room',
            'team_id' => $user->currentTeam->id,
        ]);

        $controlRoom = ControlRoom::where('name', 'New Site Control Room')->firstOrFail();
        $response->assertRedirect(route('control-rooms.show', $controlRoom));
    }

    public function test_show_page_lists_decisions_alerts_and_operator_sessions(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;
        $controlRoom = ControlRoom::factory()->for($team)->create();

        DispatchDecision::factory()->for($controlRoom)->for($user, 'decidedBy')->create([
            'workflow_type' => 'reroute',
            'sequence' => 1,
            'summary' => 'Rerouted haul truck 12',
        ]);

        Alert::factory()->for($controlRoom)->create([
            'severity' => 'critical',
            'title' => 'Sentinel threshold breach',
        ]);

        $this->actingAs($user)
            ->get(route('control-rooms.show', $controlRoom))
            ->assertOk()
            ->assertSee('Rerouted haul truck 12')
            ->assertSee('Sentinel threshold breach');
    }

    /**
     * As of HasTeamScope (App\Models\Concerns\HasTeamScope), a control room
     * belonging to another team is invisible to implicit route-model
     * binding before ControlRoomController::authorizeAccess()'s
     * abort_unless() ever runs, so this now 404s rather than 403ing — a
     * stronger, fail-closed posture than before, since it no longer
     * depends on every route remembering that check. See
     * test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where
     * in HasTeamScopeTest for direct proof the scope itself is
     * load-bearing.
     */
    public function test_a_user_cannot_view_a_control_room_belonging_to_another_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('control-rooms.show', $controlRoom))
            ->assertNotFound();
    }

    public function test_raising_an_alert_notifies_the_rest_of_the_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $teammate = User::factory()->create();
        $team->users()->attach($teammate, ['role' => 'editor']);

        $controlRoom = ControlRoom::factory()->for($team)->create();

        $this->actingAs($owner)->post(route('control-rooms.alerts.store', $controlRoom), [
            'severity' => 'critical',
            'title' => 'Sentinel threshold breach',
            'triggered_at' => now()->format('Y-m-d\TH:i'),
        ]);

        $this->assertDatabaseHas('alerts', [
            'control_room_id' => $controlRoom->id,
            'title' => 'Sentinel threshold breach',
        ]);

        $this->assertSame(1, $teammate->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $owner->fresh()->unreadNotifications()->count());
    }
}
