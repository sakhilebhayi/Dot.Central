<?php

namespace Tests\Feature\Memory;

use App\Models\ControlRoom;
use App\Models\MemoryConnection;
use App\Models\MemoryIncident;
use App\Models\MemoryOutboxRow;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemoryHealthCardTest extends TestCase
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

    public function test_administrator_configures_the_connection_and_the_token_never_echoes(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;

        $this->actingAs($owner)->get(route('health.index'))->assertOk()->assertSee('Dot.Memory')->assertSee('not connected')->assertSee('name="api_token"', false);

        $this->actingAs($owner)->post(route('health.memory.update'), ['base_url' => 'http://memory.test', 'api_token' => 'memory-token-value', 'enabled' => '1'])
            ->assertRedirect(route('health.index').'#memory')->assertSessionHas('status');

        $connection = MemoryConnection::withoutGlobalScopes()->where('team_id', $team->id)->firstOrFail();
        $this->assertSame(['http://memory.test', 'memory-token-value', true, 'unverified'], [$connection->base_url, $connection->api_token, $connection->enabled, $connection->status]);

        $page = $this->actingAs($owner)->get(route('health.index'))->assertOk()->assertSee('leave blank to keep')->assertSee('unverified');
        $this->assertStringNotContainsString('memory-token-value', $page->getContent());

        // Saving without a token keeps the existing one; disabling shows as disabled.
        $this->actingAs($owner)->post(route('health.memory.update'), ['base_url' => 'http://memory.test'])->assertRedirect();
        $this->assertSame(['memory-token-value', false], [$connection->fresh()->api_token, $connection->fresh()->enabled]);
        $this->actingAs($owner)->get(route('health.index'))->assertSee('disabled');
    }

    public function test_test_credential_marks_verified_or_credential_invalid(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $connection = MemoryConnection::factory()->create(['team_id' => $owner->currentTeam->id, 'status' => 'unverified']);

        // A second Http::fake for the same URL does not replace the first stub; one sequence covers both calls.
        Http::fake(['memory.test/api/user' => Http::sequence()->push(['id' => 4, 'name' => 'Memory Service', 'email' => 'svc@example.com'])->push(['message' => 'Unauthenticated.'], 401)]);
        $this->actingAs($owner)->post(route('health.memory.test'))->assertRedirect()->assertSessionHas('status', fn ($v) => str_contains($v, 'Memory Service'));
        $this->assertSame('verified', $connection->fresh()->status);
        $this->assertNotNull($connection->fresh()->last_verified_at);

        $this->actingAs($owner)->post(route('health.memory.test'))->assertRedirect()->assertSessionHas('memory_error');
        $this->assertSame('credential_invalid', $connection->fresh()->status);
        $this->actingAs($owner)->get(route('health.index'))->assertSee('credential invalid');
    }

    public function test_supervisors_see_readouts_but_cannot_configure(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        MemoryConnection::factory()->create(['team_id' => $team->id]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'p', 'payload' => []]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'f', 'payload' => [], 'attempts' => 3, 'next_attempt_at' => now()->addHour()]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 's', 'payload' => [], 'attempts' => MemoryOutboxRow::MAX_ATTEMPTS]);
        MemoryIncident::create(['team_id' => $team->id, 'incident_uid' => 'u', 'signature' => 'queue:stalled', 'component' => 'queue', 'severity' => 'sev1', 'status' => 'open', 'detected_at' => now()]);
        $supervisor = $this->member($team, 'supervisor', $room);

        $page = $this->actingAs($supervisor)->get(route('health.index'))->assertOk()->assertSee('Outbox pending')->assertSee('1 / 1');
        $this->assertStringNotContainsString('name="api_token"', $page->getContent());
        $this->actingAs($supervisor)->post(route('health.memory.update'), ['base_url' => 'http://x.test', 'api_token' => 't'])->assertForbidden();
        $this->actingAs($supervisor)->post(route('health.memory.test'))->assertForbidden();
    }
}
