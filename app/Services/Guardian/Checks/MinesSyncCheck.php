<?php

namespace App\Services\Guardian\Checks;

use App\Models\MinesConnection;
use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;

/**
 * Is the control room still hearing from Dot.Mines? Every usable
 * connection is synced every config('mines.sync_interval_minutes'); a
 * last success older than 2x that is late, 4x means the feed has stopped,
 * and a run of consecutive failures is reported on its own. A connection
 * that never succeeded is `unknown`, not failed.
 */
class MinesSyncCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'mines_sync';
    }

    public function run(): CheckResult
    {
        $interval = max(60, (int) config('mines.sync_interval_minutes', 5) * 60);
        $warnX = (float) config('guardian.freshness.warning_multiplier', 2.0);
        $critX = (float) config('guardian.freshness.critical_multiplier', 4.0);

        $connections = MinesConnection::withoutGlobalScopes()->get()->filter(fn (MinesConnection $c) => $c->isUsable());

        if ($connections->isEmpty()) {
            return CheckResult::unknown('No usable Dot.Mines connections -- nothing to sync.');
        }

        $worst = CheckResult::healthy();
        $rows = [];

        foreach ($connections as $connection) {
            $age = $connection->last_success_at === null ? null : max(0, (int) floor($connection->last_success_at->diffInSeconds(now())));
            $failures = (int) $connection->consecutive_failures;

            if ($age === null) {
                $status = CheckResult::unknown('never synced');
            } elseif ($age >= $interval * $critX || $failures >= 5) {
                $status = CheckResult::critical('stopped');
            } elseif ($age >= $interval * $warnX || $failures >= 2) {
                $status = CheckResult::warning('late');
            } else {
                $status = CheckResult::healthy('fresh');
            }

            if ($status->isWorseThan($worst)) {
                $worst = $status;
            }

            $rows[] = [
                'control_room_id' => $connection->control_room_id,
                'status' => $status->status(),
                'last_success_age_seconds' => $age,
                'consecutive_failures' => $failures,
                'connection_status' => $connection->status,
            ];
        }

        $metrics = ['sync_interval_seconds' => $interval, 'connections' => $rows];

        return match ($worst->status()) {
            CheckResult::CRITICAL => CheckResult::critical('At least one Dot.Mines connection has stopped syncing.', $metrics),
            CheckResult::WARNING => CheckResult::warning('At least one Dot.Mines connection is syncing late or failing.', $metrics),
            CheckResult::UNKNOWN => CheckResult::unknown('At least one Dot.Mines connection has never synced.', $metrics),
            default => CheckResult::healthy('Every Dot.Mines connection synced recently.', $metrics),
        };
    }
}
