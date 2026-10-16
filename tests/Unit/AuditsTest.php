<?php

namespace Tests\Unit;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\User;
use App\Services\Audits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditsTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_fills_team_and_role_from_the_actor_and_redacts_fields(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();

        $entry = app(Audits::class)->record(
            action: 'override',
            subject: $room,
            changes: ['api_token' => ['old' => 'secret-a', 'new' => 'secret-b'], 'name' => ['old' => 'A', 'new' => 'B']],
            reason: 'rotated credentials',
            actor: $owner,
            redact: ['api_token'],
        );

        $this->assertInstanceOf(AuditEntry::class, $entry);
        $this->assertSame($owner->currentTeam->id, $entry->team_id);
        $this->assertSame($room->id, $entry->control_room_id);
        $this->assertSame('administrator', $entry->user_role);
        $this->assertSame('rotated credentials', $entry->reason);
        $this->assertSame(['old' => '[redacted]', 'new' => '[redacted]'], $entry->changes['api_token']);
        $this->assertSame('B', $entry->changes['name']['new']);
    }

    public function test_record_without_an_actor_is_automatic(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();

        $entry = app(Audits::class)->record(action: 'import', subject: $room);

        $this->assertTrue($entry->automatic);
        $this->assertNull($entry->user_id);
        $this->assertSame($owner->currentTeam->id, $entry->team_id);
    }
}
