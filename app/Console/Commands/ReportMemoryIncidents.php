<?php

namespace App\Console\Commands;

use App\Models\MemoryConnection;
use App\Services\Memory\IncidentReporter;
use Illuminate\Console\Command;

class ReportMemoryIncidents extends Command
{
    protected $signature = 'memory:report-incidents';

    protected $description = 'Open or resolve ops incidents about this platform in Dot.Memory for every team with a usable connection.';

    public function handle(IncidentReporter $reporter): int
    {
        $connections = MemoryConnection::withoutGlobalScopes()->where('enabled', true)->get();

        foreach ($connections as $connection) {
            $result = $reporter->evaluate($connection->team_id);
            $this->line('Team '.$connection->team_id.': opened '.$result['opened'].', resolved '.$result['resolved']);
        }

        $this->info('Evaluated '.$connections->count().' team(s).');

        return self::SUCCESS;
    }
}
