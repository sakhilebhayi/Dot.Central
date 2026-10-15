<?php

namespace App\Console\Commands;

use App\Jobs\RefreshEngineHours;
use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Console\Command;

class RefreshMinesHours extends Command
{
    protected $signature = 'mines:refresh-hours {connection?} {--now : Run inline instead of queueing}';

    protected $description = 'Refresh a batch of engine hours per Dot.Mines connection from machine metrics.';

    public function handle(MinesSynchroniser $synchroniser): int
    {
        $query = MinesConnection::withoutGlobalScopes()->whereNotIn('status', ['disabled', 'credential_invalid'])->whereNotNull('api_token');

        if ($this->argument('connection') !== null) {
            $query->whereKey((int) $this->argument('connection'));
        }

        foreach ($query->get() as $connection) {
            if ($this->option('now')) {
                $this->line("Connection {$connection->id}: ".$synchroniser->refreshEngineHours($connection).' machine(s) updated');
            } else {
                RefreshEngineHours::dispatch($connection->id);
                $this->line("Connection {$connection->id}: queued");
            }
        }

        return self::SUCCESS;
    }
}
