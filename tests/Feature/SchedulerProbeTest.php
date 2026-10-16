<?php

namespace Tests\Feature;

use App\Jobs\QueueHeartbeat;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SchedulerProbeTest extends TestCase
{
    public function test_probe_is_ok_when_both_heartbeats_are_fresh_and_503_otherwise(): void
    {
        Cache::forever('dot.scheduler_heartbeat', now()->subSeconds(40)->toIso8601String());
        Cache::forever(QueueHeartbeat::KEY, now()->subMinutes(4)->toIso8601String());
        $this->getJson('/up/scheduler')->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('scheduler.age_seconds', 40);

        Cache::forever('dot.scheduler_heartbeat', now()->subMinutes(10)->toIso8601String());
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('status', 'stale');

        Cache::forget('dot.scheduler_heartbeat');
        Cache::forget(QueueHeartbeat::KEY);
        $this->getJson('/up/scheduler')->assertStatus(503)->assertJsonPath('scheduler.age_seconds', null);
    }
}
