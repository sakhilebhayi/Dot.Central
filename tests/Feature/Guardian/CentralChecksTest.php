<?php

namespace Tests\Feature\Guardian;

use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use App\Models\Team;
use App\Services\Guardian\Checks\MemoryOutboxCheck;
use App\Services\Guardian\Checks\MinesSyncCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The two checks that make Dot.Central's report more than "the app is up":
 * is it still hearing from Dot.Mines, and is what it owes Dot.Memory leaving.
 */
class CentralChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_mines_sync_is_unknown_without_a_usable_connection(): void
    {
        $this->assertSame('unknown', app(MinesSyncCheck::class)->run()->status());

        MinesConnection::factory()->create(['status' => 'disabled']);
        $this->assertSame('unknown', app(MinesSyncCheck::class)->run()->status());
    }

    public function test_mines_sync_is_unknown_for_a_connection_that_never_succeeded(): void
    {
        MinesConnection::factory()->create();

        $result = app(MinesSyncCheck::class)->run();

        $this->assertSame('unknown', $result->status());
        $this->assertNull($result->toArray()['metrics']['connections'][0]['last_success_age_seconds']);
    }

    public function test_mines_sync_grades_freshness_against_the_sync_interval(): void
    {
        config(['mines.sync_interval_minutes' => 5]);
        $connection = MinesConnection::factory()->verified()->create();

        $this->assertSame('healthy', app(MinesSyncCheck::class)->run()->status());

        $connection->forceFill(['last_success_at' => now()->subMinutes(12)])->save(); // > 2x
        $this->assertSame('warning', app(MinesSyncCheck::class)->run()->status());

        $connection->forceFill(['last_success_at' => now()->subMinutes(25)])->save(); // > 4x
        $this->assertSame('critical', app(MinesSyncCheck::class)->run()->status());
    }

    public function test_mines_sync_reports_a_run_of_failures_even_when_the_last_success_is_recent(): void
    {
        MinesConnection::factory()->verified()->create(['consecutive_failures' => 3]);

        $result = app(MinesSyncCheck::class)->run();

        $this->assertSame('warning', $result->status());
        $this->assertSame(3, $result->toArray()['metrics']['connections'][0]['consecutive_failures']);
    }

    public function test_memory_outbox_is_unknown_without_an_enabled_connection(): void
    {
        $this->assertSame('unknown', app(MemoryOutboxCheck::class)->run()->status());
    }

    public function test_memory_outbox_is_healthy_when_rows_leave_and_critical_when_they_get_stuck(): void
    {
        $team = Team::factory()->create();
        MemoryConnection::factory()->create(['team_id' => $team->id]);

        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'observation', 'payload' => [], 'attempts' => 1, 'sent_at' => now()->subMinute()]);
        $this->assertSame('healthy', app(MemoryOutboxCheck::class)->run()->status());

        $late = MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'observation', 'payload' => [], 'attempts' => 2]);
        $late->forceFill(['created_at' => now()->subMinutes(20)])->save();
        $this->assertSame('warning', app(MemoryOutboxCheck::class)->run()->status());

        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'observation', 'payload' => [], 'attempts' => MemoryOutboxRow::MAX_ATTEMPTS]);
        $result = app(MemoryOutboxCheck::class)->run();
        $this->assertSame('critical', $result->status());
        $this->assertSame(1, $result->toArray()['metrics']['stuck']);
    }
}
