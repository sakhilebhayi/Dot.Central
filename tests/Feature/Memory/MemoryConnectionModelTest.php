<?php

namespace Tests\Feature\Memory;

use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryConnectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_encrypted_redacted_from_audits_and_usability_follows_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $connection = MemoryConnection::factory()->create(['team_id' => $team->id, 'api_token' => 'memory-token-value']);

        $raw = (string) \DB::table('memory_connections')->where('id', $connection->id)->value('api_token');
        $this->assertStringNotContainsString('memory-token-value', $raw);
        $this->assertSame('memory-token-value', $connection->fresh()->api_token);

        $audit = \DB::table('audit_log')->where('entity_type', MemoryConnection::class)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('memory-token-value', (string) $audit->changes);

        $this->assertTrue($connection->isUsable());
        $connection->forceFill(['status' => 'credential_invalid'])->save();
        $this->assertFalse($connection->fresh()->isUsable());
        $connection->forceFill(['status' => 'verified', 'enabled' => false])->save();
        $this->assertFalse($connection->fresh()->isUsable());
    }

    public function test_outbox_due_scope_excludes_sent_future_and_exhausted_rows(): void
    {
        $team = Team::factory()->create();
        $base = ['team_id' => $team->id, 'stage' => 'event', 'payload' => ['x' => 1]];
        MemoryOutboxRow::create($base + ['event_id' => 'a']);
        MemoryOutboxRow::create($base + ['event_id' => 'b', 'sent_at' => now()]);
        MemoryOutboxRow::create($base + ['event_id' => 'c', 'next_attempt_at' => now()->addHour()]);
        MemoryOutboxRow::create($base + ['event_id' => 'd', 'attempts' => MemoryOutboxRow::MAX_ATTEMPTS]);

        $this->assertSame(['a'], MemoryOutboxRow::due()->pluck('event_id')->all());
    }
}
