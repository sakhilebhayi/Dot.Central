<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Proves a queue worker is alive: the scheduler dispatches this every few
 * minutes and the Health page shows when it last ran (issue I-17).
 */
class QueueHeartbeat implements ShouldQueue
{
    use Queueable;

    public const KEY = 'dot.queue_heartbeat';

    public function handle(): void
    {
        Cache::forever(self::KEY, now()->toIso8601String());
    }
}
