<?php

namespace App\Jobs;

use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Synchronises one control room's Dot.Mines connection. Unique per
 * connection so overlapping schedules never run two syncs at once.
 */
class SyncMinesConnection implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $connectionId, public readonly string $trigger = 'schedule') {}

    public function uniqueId(): string
    {
        return (string) $this->connectionId;
    }

    public function handle(MinesSynchroniser $synchroniser): void
    {
        $connection = MinesConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection === null) {
            return;
        }

        $synchroniser->run($connection, $this->trigger);
    }
}
