<?php

namespace App\Jobs;

use App\Models\MemoryOutboxRow;
use App\Services\Memory\Exceptions\MemoryCredentialRejected;
use App\Services\Memory\Exceptions\MemoryRefused;
use App\Services\Memory\Exceptions\MemoryUnavailable;
use App\Services\Memory\MemoryClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a team's pending Dot.Memory records in order. One failure stops
 * the run for that team: an unreachable Memory backs the row off, a
 * refused payload is given up on (kept, visible on Health), a rejected
 * credential disables the connection until an administrator fixes it.
 */
class FlushMemoryOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $teamId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('memory-outbox:'.$this->teamId))->dontRelease()];
    }

    public function handle(MemoryClientFactory $clients): void
    {
        $connection = $clients->connectionFor($this->teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return;
        }

        $client = $clients->for($connection);

        foreach (MemoryOutboxRow::where('team_id', $this->teamId)->due()->limit(200)->get() as $row) {
            try {
                match ($row->stage) {
                    'incident' => $client->storeIncident($row->payload),
                    'incident_update' => $client->updateIncident((string) ($row->payload['incident_uid'] ?? ''), $row->payload),
                    default => $client->record($row->stage, $row->payload),
                };
                $row->forceFill(['sent_at' => now(), 'last_error' => null])->save();
                $connection->forceFill(['status' => 'verified', 'last_write_at' => now(), 'last_error' => null])->save();
            } catch (MemoryCredentialRejected $e) {
                $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => $e->getMessage(), 'next_attempt_at' => $row->backoff()])->save();
                $connection->forceFill(['status' => 'credential_invalid', 'last_error' => $e->getMessage()])->save();

                return;
            } catch (MemoryRefused $e) {
                $row->forceFill(['attempts' => MemoryOutboxRow::MAX_ATTEMPTS, 'last_error' => $e->getMessage()])->save();
            } catch (MemoryUnavailable $e) {
                $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => $e->getMessage()])->save();
                $row->forceFill(['next_attempt_at' => $row->backoff()])->save();
                $connection->forceFill(['status' => 'unreachable', 'last_error' => $e->getMessage()])->save();

                return;
            }
        }
    }
}
