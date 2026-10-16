<?php

namespace App\Console\Commands;

use App\Models\MemoryOutboxRow;
use Illuminate\Console\Command;

class PruneMemoryOutbox extends Command
{
    protected $signature = 'memory:prune';

    protected $description = 'Delete Dot.Memory outbox rows that were sent longer ago than the retention period. Unsent rows are never pruned.';

    public function handle(): int
    {
        $days = max(1, (int) config('memory.outbox_retention_days', 30));
        $deleted = MemoryOutboxRow::query()->whereNotNull('sent_at')->where('sent_at', '<', now()->subDays($days))->delete();

        $this->info("Pruned {$deleted} sent row(s) older than {$days} days.");

        return self::SUCCESS;
    }
}
