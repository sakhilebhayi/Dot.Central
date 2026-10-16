<?php

namespace App\Console\Commands;

use App\Jobs\FlushMemoryOutbox;
use App\Models\MemoryOutboxRow;
use Illuminate\Console\Command;

class FlushMemoryOutboxes extends Command
{
    protected $signature = 'memory:flush';

    protected $description = 'Queue a flush of every team\'s pending Dot.Memory records (backstop for the after-commit dispatch).';

    public function handle(): int
    {
        $teams = MemoryOutboxRow::query()->due()->reorder()->distinct()->pluck('team_id');

        foreach ($teams as $teamId) {
            FlushMemoryOutbox::dispatch((int) $teamId);
        }

        $this->info('Queued flushes for '.$teams->count().' team(s).');

        return self::SUCCESS;
    }
}
