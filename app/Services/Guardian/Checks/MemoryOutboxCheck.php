<?php

namespace App\Services\Guardian\Checks;

use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Services\Guardian\CheckResult;
use App\Services\Guardian\Contracts\GuardianCheck;
use Illuminate\Support\Carbon;

/**
 * Is what this platform owes Dot.Memory actually leaving? The outbox is
 * flushed every minute; rows that are still unsent after a quarter hour
 * are late, rows that exhausted their attempts are stuck. No enabled
 * connection means nothing is owed, so `unknown`.
 */
class MemoryOutboxCheck implements GuardianCheck
{
    public function key(): string
    {
        return 'memory_outbox';
    }

    public function run(): CheckResult
    {
        $enabled = MemoryConnection::withoutGlobalScopes()->where('enabled', true)->count();

        if ($enabled === 0) {
            return CheckResult::unknown('No enabled Dot.Memory connection -- nothing to send.');
        }

        $unsent = MemoryOutboxRow::whereNull('sent_at');
        $pending = (clone $unsent)->where('attempts', '<', MemoryOutboxRow::MAX_ATTEMPTS)->count();
        $stuck = (clone $unsent)->where('attempts', '>=', MemoryOutboxRow::MAX_ATTEMPTS)->count();
        $oldest = (clone $unsent)->where('attempts', '<', MemoryOutboxRow::MAX_ATTEMPTS)->min('created_at');
        $oldestAge = $oldest === null ? 0 : max(0, (int) floor(Carbon::parse($oldest)->diffInSeconds(now())));
        $lastSent = MemoryOutboxRow::whereNotNull('sent_at')->max('sent_at');

        $metrics = [
            'enabled_connections' => $enabled,
            'pending' => $pending,
            'stuck' => $stuck,
            'oldest_pending_seconds' => $oldestAge,
            'last_sent_at' => $lastSent,
        ];

        if ($stuck > 0 || $oldestAge >= 3600) {
            return CheckResult::critical('Dot.Memory outbox has stuck rows or an hour-old backlog.', $metrics);
        }

        if ($oldestAge >= 900 || $pending >= 100) {
            return CheckResult::warning('Dot.Memory outbox is draining late.', $metrics);
        }

        return CheckResult::healthy('Dot.Memory outbox draining normally.', $metrics);
    }
}
