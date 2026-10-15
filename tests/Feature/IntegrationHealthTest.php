<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use App\Jobs\SyncMinesConnection;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\IntegrationSyncRun;
use App\Models\MinesConnection;
use App\Models\Team;
use App\Models\User;
use App\Support\Format;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Operators must be able to tell an operational incident from an
 * information-system failure (brief §I). The Health page shows every
 * connection's last runs, freshness and failures; admins can act on it.
 */
class IntegrationHealthTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role, ?ControlRoom $room = null): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $room?->users()->attach($user);

        return $user;
    }

    public function test_health_is_gated_to_supervisors_and_above_and_shows_runs_and_freshness(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create(['name' => 'North Room']);
        $connection = MinesConnection::factory()->for($room)->verified()->create(['consecutive_failures' => 0]);
        IntegrationSyncRun::factory()->for($connection, 'connection')->succeeded()->create(['fetched_machines' => 12]);
        IntegrationSyncRun::factory()->for($connection, 'connection')->failed()->create();
        Equipment::factory()->for($room)->heard(60)->create();
        Equipment::factory()->for($room)->heard(2000)->create();
        Equipment::factory()->for($room)->create(['last_heard_at' => null]);

        $operator = $this->member($team, 'operator', $room);
        $supervisor = $this->member($team, 'supervisor', $room);

        $this->actingAs($operator)->get(route('health.index'))->assertForbidden();

        $this->actingAs($supervisor)->get(route('health.index'))
            ->assertOk()
            ->assertSee('North Room')
            ->assertSee('succeeded')
            ->assertSee('failed')
            ->assertSee('Dot.Mines returned HTTP 503.')
            ->assertSeeInOrder(['Fresh', '1', 'Stale', '1', 'Missing', '1']);
    }

    public function test_administrators_can_queue_a_sync_and_test_the_credential(): void
    {
        Queue::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $connection = MinesConnection::factory()->for($room)->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'status' => 'unverified']);
        Http::fake(['mines.test/api/v1/user' => Http::response(['id' => 2, 'name' => 'Demo', 'email' => 'demo@example.test', 'current_team_id' => 2])]);

        $this->actingAs($owner)->post(route('health.sync', $connection))->assertRedirect(route('health.index'));
        Queue::assertPushed(SyncMinesConnection::class, fn (SyncMinesConnection $job) => $job->connectionId === $connection->id && $job->trigger === 'manual');

        $this->actingAs($owner)->post(route('health.test', $connection))->assertRedirect(route('health.index'))->assertSessionHas('status');
        $this->assertSame('verified', $connection->fresh()->status);
        $this->assertSame(2, $connection->fresh()->mines_team_id);

        $this->assertDatabaseHas('audit_log', ['entity_type' => MinesConnection::class, 'entity_id' => $connection->id, 'action' => 'generate']);
        $this->assertStringNotContainsString('tok', (string) AuditEntry::where('entity_id', $connection->id)->where('action', 'generate')->first()?->changes['action']['new']);
    }

    public function test_a_rejected_credential_test_marks_the_connection(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $connection = MinesConnection::factory()->for($room)->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'status' => 'verified']);
        Http::fake(['mines.test/api/v1/user' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        $this->actingAs($owner)->post(route('health.test', $connection))->assertRedirect(route('health.index'))->assertSessionHas('error');
        $this->assertSame('credential_invalid', $connection->fresh()->status);
    }

    public function test_supervisors_cannot_run_actions(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        $connection = MinesConnection::factory()->for($room)->create();
        $supervisor = $this->member($team, 'supervisor', $room);

        $this->actingAs($supervisor)->post(route('health.sync', $connection))->assertForbidden();
    }

    public function test_health_shows_the_scheduler_and_queue_heartbeats(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $supervisor = $this->member($owner->currentTeam, 'supervisor', ControlRoom::factory()->for($owner->currentTeam)->create());
        Cache::forever('dot.scheduler_heartbeat', now()->subSeconds(30)->toIso8601String());

        $this->actingAs($supervisor)->get(route('health.index'))->assertOk()->assertSeeInOrder(['Scheduler last ran', Format::age(now()->subSeconds(30)), 'Queue worker last ran', 'never']);

        (new QueueHeartbeat)->handle();
        $this->assertNotNull(Cache::get(QueueHeartbeat::KEY));
        $this->actingAs($supervisor)->get(route('health.index'))->assertOk()->assertSeeInOrder(['Queue worker last ran', Format::age(now())]);

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->description ?? $e->command ?? '')->implode(' ');
        $this->assertStringContainsString('scheduler-heartbeat', $events);
        $this->assertStringContainsString('queue-heartbeat', $events);
    }
}
