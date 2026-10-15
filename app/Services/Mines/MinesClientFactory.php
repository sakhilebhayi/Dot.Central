<?php

namespace App\Services\Mines;

use App\Models\MinesConnection;
use InvalidArgumentException;

/**
 * Builds a client for a control room's connection. The token leaves the
 * encrypted column only here, straight into the client.
 */
final class MinesClientFactory
{
    public function for(MinesConnection $connection): MinesClient
    {
        if ($connection->api_token === null || $connection->api_token === '') {
            throw new InvalidArgumentException('The connection for control room '.$connection->control_room_id.' has no API token.');
        }

        return new MinesClient(
            $connection->baseUrl(),
            $connection->api_token,
            (int) config('mines.request_timeout', 10),
        );
    }
}
