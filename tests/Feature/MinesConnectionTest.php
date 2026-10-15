<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\MinesConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Each control room maps to one Dot.Mines team through a connection that
 * holds an encrypted, human-minted API token (architecture decision D1).
 */
class MinesConnectionTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role, ControlRoom $room): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $room->users()->attach($user);

        return $user;
    }

    public function test_control_rooms_no_longer_carry_a_site_reference(): void
    {
        $this->assertFalse(Schema::hasColumn('control_rooms', 'mines_site_ref'));
    }

    public function test_administrator_can_configure_a_connection_and_the_token_is_stored_encrypted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();

        $this->actingAs($owner)->get(route('control-rooms.connection.edit', $room))
            ->assertOk()
            ->assertSee('name="api_token"', false)
            ->assertSee('required', false);

        $response = $this->actingAs($owner)->put(route('control-rooms.connection.update', $room), [
            'base_url' => 'http://127.0.0.1:8010/',
            'mines_team_id' => 7,
            'api_token' => '1|plain-text-token-value',
            'webhook_secret' => 'whsec_example',
            'provider_cadence_seconds' => 900,
            'timezone' => 'Africa/Johannesburg',
        ]);

        $response->assertRedirect(route('control-rooms.show', $room));

        $connection = MinesConnection::where('control_room_id', $room->id)->firstOrFail();
        $this->assertSame('http://127.0.0.1:8010', $connection->base_url);
        $this->assertSame(7, $connection->mines_team_id);
        $this->assertSame('1|plain-text-token-value', $connection->api_token);
        $this->assertSame('unverified', $connection->status);

        $raw = DB::table('mines_connections')->where('id', $connection->id)->value('api_token');
        $this->assertNotSame('1|plain-text-token-value', $raw);
        $this->assertStringNotContainsString('plain-text-token-value', (string) $raw);

        $audit = AuditEntry::where('entity_type', MinesConnection::class)->where('entity_id', $connection->id)->firstOrFail();
        $this->assertSame('[redacted]', $audit->changes['api_token']['new']);
        $this->assertSame('[redacted]', $audit->changes['webhook_secret']['new']);
        $this->assertSame('http://127.0.0.1:8010', $audit->changes['base_url']['new']);
    }

    public function test_leaving_the_token_blank_keeps_the_existing_token(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $connection = MinesConnection::factory()->for($room)->create(['api_token' => 'keep-me']);

        $this->actingAs($owner)->put(route('control-rooms.connection.update', $room), [
            'base_url' => 'https://mines.example.test',
            'api_token' => '',
            'provider_cadence_seconds' => 300,
            'timezone' => 'UTC',
        ])->assertRedirect();

        $this->assertSame('keep-me', $connection->fresh()->api_token);
        $this->assertSame(300, $connection->fresh()->provider_cadence_seconds);
    }

    public function test_the_token_is_never_rendered(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($room)->create(['api_token' => 'super-secret-token']);

        $this->actingAs($owner)->get(route('control-rooms.connection.edit', $room))
            ->assertOk()
            ->assertSee('name="api_token"', false)
            ->assertDontSee('super-secret-token');
        $this->actingAs($owner)->get(route('control-rooms.show', $room))
            ->assertOk()->assertDontSee('super-secret-token');
    }

    public function test_operators_and_supervisors_cannot_configure_the_connection(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $supervisor = $this->member($owner->currentTeam, 'supervisor', $room);

        $this->actingAs($supervisor)->get(route('control-rooms.connection.edit', $room))->assertForbidden();
        $this->actingAs($supervisor)->put(route('control-rooms.connection.update', $room), [
            'base_url' => 'https://mines.example.test', 'api_token' => 'x', 'provider_cadence_seconds' => 900, 'timezone' => 'UTC',
        ])->assertForbidden();
    }

    public function test_validation_rejects_bad_values(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();

        $this->actingAs($owner)->put(route('control-rooms.connection.update', $room), [
            'base_url' => 'not a url', 'api_token' => 'x', 'provider_cadence_seconds' => 5, 'timezone' => 'Mars/Olympus',
        ])->assertSessionHasErrors(['base_url', 'provider_cadence_seconds', 'timezone']);
    }

    public function test_is_usable_requires_a_token_and_a_live_status(): void
    {
        $connection = MinesConnection::factory()->make(['api_token' => 'tok', 'status' => 'unverified']);
        $this->assertTrue($connection->isUsable());

        $connection->status = 'credential_invalid';
        $this->assertFalse($connection->isUsable());

        $connection->status = 'verified';
        $connection->api_token = null;
        $this->assertFalse($connection->isUsable());
    }
}
