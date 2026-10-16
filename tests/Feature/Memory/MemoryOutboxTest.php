<?php

namespace Tests\Feature\Memory;

use App\Jobs\FlushMemoryOutbox;
use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\Team;
use App\Services\Memory\MemoryClientFactory;
use App\Services\Memory\MemoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MemoryOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_writes_a_row_once_per_event_id_and_dispatches_the_flush(): void
    {
        Queue::fake();
        $connection = MemoryConnection::factory()->create();
        $recorder = app(MemoryRecorder::class);

        $row = $recorder->queue($connection->team_id, 'decision', ['event_id' => 'e1', 'loop_id' => 'l']);
        $again = $recorder->queue($connection->team_id, 'decision', ['event_id' => 'e1', 'loop_id' => 'l']);

        $this->assertNotNull($row);
        $this->assertSame($row->id, $again?->id);
        $this->assertSame(1, MemoryOutboxRow::count());
        Queue::assertPushed(FlushMemoryOutbox::class);
    }

    public function test_queue_is_a_no_op_without_a_usable_connection(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $this->assertNull(app(MemoryRecorder::class)->queue($team->id, 'event', ['event_id' => 'x']));
        $this->assertSame(0, MemoryOutboxRow::count());
        Queue::assertNothingPushed();
    }

    public function test_flush_sends_in_order_and_marks_sent(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake(['memory.test/api/intelligence/*' => Http::response(['data' => ['id' => 1]], 201)]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'a', 'payload' => ['event_id' => 'a']]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'outcome', 'event_id' => 'b', 'payload' => ['event_id' => 'b']]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(MemoryClientFactory::class));

        $this->assertSame(0, MemoryOutboxRow::whereNull('sent_at')->count());
        $this->assertNotNull($connection->fresh()->last_write_at);
        $this->assertSame('verified', $connection->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_flush_backs_off_when_memory_is_unavailable_and_stops_for_the_team(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake(['memory.test/*' => Http::response('', 503)]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'a', 'payload' => []]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'b', 'payload' => []]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(MemoryClientFactory::class));

        $first = MemoryOutboxRow::where('event_id', 'a')->first();
        $this->assertSame(1, $first->attempts);
        $this->assertTrue($first->next_attempt_at->gt(now()));
        $this->assertStringContainsString('503', (string) $first->last_error);
        $this->assertSame(0, MemoryOutboxRow::where('event_id', 'b')->value('attempts'));
        $this->assertSame('unreachable', $connection->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_flush_gives_up_on_a_refused_payload_and_marks_credentials_invalid_on_401(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake([
            'memory.test/api/intelligence/actions' => Http::response(['errors' => ['execution_status' => ['invalid']]], 422),
            'memory.test/api/intelligence/events' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'action', 'event_id' => 'a', 'payload' => []]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'b', 'payload' => []]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(MemoryClientFactory::class));

        $this->assertSame(MemoryOutboxRow::MAX_ATTEMPTS, MemoryOutboxRow::where('event_id', 'a')->value('attempts'));
        $this->assertSame('credential_invalid', $connection->fresh()->status);
        $this->assertNull(MemoryOutboxRow::where('event_id', 'b')->value('sent_at'));
    }

    public function test_prune_removes_old_sent_rows_only(): void
    {
        $team = Team::factory()->create();
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'old', 'payload' => [], 'sent_at' => now()->subDays(40)]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'new', 'payload' => [], 'sent_at' => now()->subDays(2)]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'stuck', 'payload' => [], 'attempts' => 20, 'created_at' => now()->subDays(40)]);

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['new', 'stuck'], MemoryOutboxRow::pluck('event_id')->all());
    }
}
