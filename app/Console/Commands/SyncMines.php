<?php

namespace App\Console\Commands;

use App\Jobs\SyncMinesConnection;
use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Console\Command;

class SyncMines extends Command
{
    protected $signature = 'mines:sync {connection? : A mines_connections id; all usable connections when omitted} {--now : Run inline instead of queueing}';

    protected $description = 'Synchronise control rooms with their Dot.Mines connections (fleet delta, machine details, active alerts).';

    public function handle(MinesSynchroniser $synchroniser): int
    {
        $query = MinesConnection::withoutGlobalScopes()->whereNotIn('status', ['disabled', 'credential_invalid'])->whereNotNull('api_token');

        if ($this->argument('connection') !== null) {
            $query->whereKey((int) $this->argument('connection'));
        }

        $connections = $query->get();

        foreach ($connections as $connection) {
            if ($this->option('now')) {
                $run = $synchroniser->run($connection, 'manual')->refresh();
                $this->line("Connection {$connection->id}: {$run->status} (machines {$run->fetched_machines}, upserted {$run->upserted}, retired {$run->retired}, alerts {$run->alerts_upserted})".($run->error_message ? " — {$run->error_message}" : ''));
            } else {
                SyncMinesConnection::dispatch($connection->id, 'schedule');
                $this->line("Connection {$connection->id}: queued");
            }
        }

        $this->info('Dispatched '.$connections->count().' connection(s).');

        return self::SUCCESS;
    }
}
