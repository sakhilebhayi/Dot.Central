<?php

namespace App\Services\Memory;

use App\Jobs\FlushMemoryOutbox;
use App\Models\MemoryOutboxRow;

/**
 * The only way a record reaches Dot.Memory: into the local outbox in the
 * caller's transaction, sent later by FlushMemoryOutbox. Never throws
 * towards an operator.
 */
class MemoryRecorder
{
    public function __construct(private readonly MemoryClientFactory $clients) {}

    /** @param array<string, mixed> $payload */
    public function queue(int $teamId, string $stage, array $payload): ?MemoryOutboxRow
    {
        $connection = $this->clients->connectionFor($teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return null;
        }

        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        if ($eventId !== null) {
            $existing = MemoryOutboxRow::where('team_id', $teamId)->where('event_id', $eventId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $row = MemoryOutboxRow::create(['team_id' => $teamId, 'stage' => $stage, 'event_id' => $eventId, 'payload' => $payload]);

        FlushMemoryOutbox::dispatch($teamId)->afterCommit();

        return $row;
    }
}
