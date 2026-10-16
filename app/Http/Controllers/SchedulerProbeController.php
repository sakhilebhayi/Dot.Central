<?php

namespace App\Http\Controllers;

use App\Jobs\QueueHeartbeat;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Public liveness probe for the two processes shared hosting gives us no
 * supervisor for: the scheduler tick and the cron-driven queue worker.
 * Says only how old each heartbeat is; 503 when either is stale.
 */
class SchedulerProbeController extends Controller
{
    public const SCHEDULER_STALE_SECONDS = 180;

    public const QUEUE_STALE_SECONDS = 900;

    public function __invoke(): JsonResponse
    {
        $scheduler = $this->age('dot.scheduler_heartbeat');
        $queue = $this->age(QueueHeartbeat::KEY);
        $healthy = $scheduler !== null && $scheduler <= self::SCHEDULER_STALE_SECONDS && $queue !== null && $queue <= self::QUEUE_STALE_SECONDS;

        return response()->json([
            'status' => $healthy ? 'ok' : 'stale',
            'scheduler' => ['age_seconds' => $scheduler, 'stale_after' => self::SCHEDULER_STALE_SECONDS],
            'queue' => ['age_seconds' => $queue, 'stale_after' => self::QUEUE_STALE_SECONDS],
        ], $healthy ? 200 : 503);
    }

    private function age(string $key): ?int
    {
        $value = Cache::get($key);

        return is_string($value) ? max(0, (int) round(Carbon::parse($value)->diffInSeconds(now()))) : null;
    }
}
