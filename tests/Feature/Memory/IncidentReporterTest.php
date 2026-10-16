<?php

namespace Tests\Feature\Memory;

use App\Jobs\QueueHeartbeat;
use App\Models\ControlRoom;
use App\Models\IntegrationSyncRun;
use App\Models\MemoryConnection;
use App\Models\MemoryIncident;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use App\Models\User;
use App\Services\Memory\IncidentReporter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IncidentReporterTest extends TestCase
{
    use RefreshDatabase;

    private int $teamId;

    private ControlRoom $room;

    private MinesConnection $mines;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->teamId = $owner->currentTeam->id;
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $this->mines = MinesConnection::factory()->for($this->room)->verified()->create();
        MemoryConnection::factory()->create(['team_id' => $this->teamId]);
        MemoryOutboxRow::query()->delete();
        Cache::forever('dot.scheduler_heartbeat', now()->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->toIso8601String());
    }

    /** @return list<string> */
    private function queuedStages(): array
    {
        return MemoryOutboxRow::orderBy('id')->get()->map(fn ($r) => $r->stage.':'.($r->payload['status'] ?? '?').':'.($r->payload['signature'] ?? ''))->all();
    }

    public function test_credential_invalid_opens_once_and_resolves_when_verified_again(): void
    {
        $reporter = app(IncidentReporter::class);
        $this->mines->forceFill(['status' => 'credential_invalid', 'last_error' => 'HTTP 401'])->save();

        $this->assertSame(['opened' => 1, 'resolved' => 0], $reporter->evaluate($this->teamId));
        $this->assertSame(['opened' => 0, 'resolved' => 0], $reporter->evaluate($this->teamId));
        $incident = MemoryIncident::firstOrFail();
        $this->assertSame(['mines_connection:credential_invalid', 'mines_connection:'.$this->mines->id, 'sev2', 'open'], [$incident->signature, $incident->component, $incident->severity, $incident->status]);
        $this->assertStringStartsWith('dot-central:'.$this->teamId.':mines_connection:credential_invalid:mines_connection:'.$this->mines->id.':', $incident->incident_uid);
        $this->assertSame(['incident:open:dot-central:mines_connection:credential_invalid'], $this->queuedStages());

        $this->mines->forceFill(['status' => 'verified', 'last_error' => null])->save();
        $this->assertSame(['opened' => 0, 'resolved' => 1], $reporter->evaluate($this->teamId));
        $this->assertSame('resolved', $incident->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);
        $stages = $this->queuedStages();
        $this->assertSame('incident_update:resolved:', end($stages));
    }

    public function test_three_failed_syncs_in_a_row_open_an_incident_but_two_do_not(): void
    {
        $reporter = app(IncidentReporter::class);
        IntegrationSyncRun::factory()->for($this->mines, 'connection')->succeeded()->create(['started_at' => now()->subMinutes(30)]);
        IntegrationSyncRun::factory()->for($this->mines, 'connection')->failed()->create(['started_at' => now()->subMinutes(20)]);
        IntegrationSyncRun::factory()->for($this->mines, 'connection')->failed()->create(['started_at' => now()->subMinutes(10)]);
        $this->assertSame(0, $reporter->evaluate($this->teamId)['opened']);

        IntegrationSyncRun::factory()->for($this->mines, 'connection')->failed()->create(['started_at' => now()->subMinutes(5)]);
        $this->assertSame(1, $reporter->evaluate($this->teamId)['opened']);
        $this->assertSame('mines_sync:failing', MemoryIncident::firstOrFail()->signature);
    }

    public function test_queue_stall_and_stuck_outbox(): void
    {
        $reporter = app(IncidentReporter::class);
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(20)->toIso8601String());
        MemoryOutboxRow::create(['team_id' => $this->teamId, 'stage' => 'event', 'event_id' => 'stuck', 'payload' => [], 'attempts' => MemoryOutboxRow::MAX_ATTEMPTS]);

        $this->assertSame(2, $reporter->evaluate($this->teamId)['opened']);
        $this->assertEqualsCanonicalizing([['queue:stalled', 'sev1'], ['memory_outbox:stuck', 'sev3']], MemoryIncident::get()->map(fn ($i) => [$i->signature, $i->severity])->all());

        // A dead scheduler cannot be blamed on the queue: no stall without a fresh scheduler heartbeat.
        MemoryIncident::query()->delete();
        Cache::forget('dot.scheduler_heartbeat');
        $this->assertSame(0, collect($reporter->conditions($this->teamId))->where('signature', 'queue:stalled')->count());
    }

    public function test_nothing_is_evaluated_without_a_usable_memory_connection_and_the_command_is_scheduled(): void
    {
        MemoryConnection::withoutGlobalScopes()->update(['enabled' => false]);
        $this->mines->forceFill(['status' => 'credential_invalid'])->save();
        $this->assertSame(['opened' => 0, 'resolved' => 0], app(IncidentReporter::class)->evaluate($this->teamId));

        $this->artisan('memory:report-incidents')->expectsOutputToContain('Evaluated 0 team(s)')->assertSuccessful();

        $events = collect(app(Schedule::class)->events())->map(fn ($e) => [$e->command, $e->expression]);
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'memory:report-incidents') && $e[1] === '*/5 * * * *'));
        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'memory:flush') && $e[1] === '* * * * *'));
    }
}
