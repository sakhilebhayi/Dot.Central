<?php

namespace App\Services\Memory;

use App\Models\MemoryConnection;
use App\Models\Team;

/**
 * The token leaves the encrypted column only here, straight into the client.
 */
final class MemoryClientFactory
{
    public function connectionFor(Team|int $team): ?MemoryConnection
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return MemoryConnection::withoutGlobalScopes()->where('team_id', $teamId)->first();
    }

    public function forTeam(Team|int $team): ?MemoryClient
    {
        $connection = $this->connectionFor($team);

        return $connection !== null && $connection->isUsable() ? $this->for($connection) : null;
    }

    public function for(MemoryConnection $connection): MemoryClient
    {
        return new MemoryClient($connection->baseUrl(), (string) $connection->api_token, (int) config('memory.request_timeout', 10));
    }
}
