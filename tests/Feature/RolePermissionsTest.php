<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The four control-room roles (operator, supervisor, manager,
 * administrator) are enforced by policies on the service layer, not only
 * hidden in Blade. See docs/dot-central/07-security-and-permissions.md.
 */
class RolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0:string,1:string,2:string,3:int}>
     */
    public static function roleMatrix(): array
    {
        return [
            'operator can view room' => ['operator', 'get', 'control-rooms.show', 200],
            'operator can create alert' => ['operator', 'post', 'control-rooms.alerts.store', 302],
            'operator can create decision' => ['operator', 'post', 'control-rooms.dispatch-decisions.store', 302],
            'operator can start session' => ['operator', 'post', 'control-rooms.operator-sessions.store', 302],
            'operator cannot delete alert' => ['operator', 'delete', 'alerts.destroy', 403],
            'operator cannot update room' => ['operator', 'put', 'control-rooms.update', 403],
            'operator cannot delete room' => ['operator', 'delete', 'control-rooms.destroy', 403],
            'operator cannot create room' => ['operator', 'post', 'control-rooms.store', 403],
            'supervisor can clear alert' => ['supervisor', 'patch', 'alerts.update', 302],
            'supervisor can delete alert' => ['supervisor', 'delete', 'alerts.destroy', 302],
            'supervisor can end session' => ['supervisor', 'patch', 'operator-sessions.update', 302],
            'supervisor can resolve stale proposal' => ['supervisor', 'patch', 'stale-session-proposals.dismiss', 302],
            'supervisor cannot update room' => ['supervisor', 'put', 'control-rooms.update', 403],
            'manager can view room' => ['manager', 'get', 'control-rooms.show', 200],
            'manager cannot create alert' => ['manager', 'post', 'control-rooms.alerts.store', 403],
            'manager cannot end session' => ['manager', 'patch', 'operator-sessions.update', 403],
            'manager cannot update room' => ['manager', 'put', 'control-rooms.update', 403],
            'administrator can create room' => ['administrator', 'post', 'control-rooms.store', 302],
            'administrator can update room' => ['administrator', 'put', 'control-rooms.update', 302],
            'administrator can delete room' => ['administrator', 'delete', 'control-rooms.destroy', 302],
            'administrator cannot create decision' => ['administrator', 'post', 'control-rooms.dispatch-decisions.store', 403],
            'administrator cannot create alert' => ['administrator', 'post', 'control-rooms.alerts.store', 403],
        ];
    }

    #[DataProvider('roleMatrix')]
    public function test_role_matrix(string $role, string $method, string $routeName, int $expectedStatus): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        /** @var Team $team */
        $team = $owner->currentTeam;

        $member = User::factory()->create();
        $team->users()->attach($member, ['role' => $role]);
        $member->switchTeam($team);

        $controlRoom = ControlRoom::factory()->for($team)->create();
        // Operators and supervisors act only in rooms they are assigned to.
        $controlRoom->users()->attach($member);
        $alert = Alert::factory()->for($controlRoom)->create();
        $decision = DispatchDecision::factory()->for($controlRoom)->for($owner, 'decidedBy')->create();
        $session = OperatorSession::factory()->for($controlRoom)->for($owner, 'operator')->create();
        $proposal = StaleSessionProposal::factory()->for($controlRoom)->for($session, 'operatorSession')->create();

        [$url, $payload] = match ($routeName) {
            'control-rooms.show' => [route($routeName, $controlRoom), []],
            'control-rooms.store' => [route($routeName), ['name' => 'Second Room']],
            'control-rooms.update' => [route($routeName, $controlRoom), ['name' => 'Renamed', 'is_active' => 1]],
            'control-rooms.destroy' => [route($routeName, $controlRoom), []],
            'control-rooms.alerts.store' => [route($routeName, $controlRoom), [
                'severity' => 'warning', 'title' => 'Telemetry missing', 'triggered_at' => now()->format('Y-m-d\TH:i'),
            ]],
            'control-rooms.dispatch-decisions.store' => [route($routeName, $controlRoom), [
                'workflow_type' => 'hold', 'decided_at' => now()->format('Y-m-d\TH:i'),
            ]],
            'control-rooms.operator-sessions.store' => [route($routeName, $controlRoom), [
                'user_id' => $member->id, 'shift_label' => 'day', 'started_at' => now()->format('Y-m-d\TH:i'),
            ]],
            'alerts.update', 'alerts.destroy' => [route($routeName, $alert), []],
            'operator-sessions.update' => [route($routeName, $session), []],
            'stale-session-proposals.dismiss' => [route($routeName, $proposal), []],
        };

        $response = $this->actingAs($member)->{$method}($url, $payload);

        $response->assertStatus($expectedStatus);
    }

    public function test_team_owner_is_treated_as_administrator(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();

        $this->actingAs($owner)
            ->post(route('control-rooms.store'), ['name' => 'Owner Room'])
            ->assertRedirect();

        $this->assertDatabaseHas('control_rooms', ['name' => 'Owner Room']);
    }

    public function test_legacy_jetstream_roles_are_renamed_by_migration(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $legacyAdmin = User::factory()->create();
        $legacyEditor = User::factory()->create();
        $team->users()->attach($legacyAdmin, ['role' => 'admin']);
        $team->users()->attach($legacyEditor, ['role' => 'editor']);

        $this->artisan('migrate:refresh', ['--path' => 'database/migrations/2026_10_15_000001_rename_legacy_team_roles.php', '--force' => true]);

        $this->assertSame('administrator', $team->users()->find($legacyAdmin->id)->membership->role);
        $this->assertSame('operator', $team->users()->find($legacyEditor->id)->membership->role);
    }
}
