<?php

namespace App\Jobs;

use App\Models\MinesConnection;
use App\Services\Mines\MinesSynchroniser;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Refreshes one batch of engine hours for a connection. */
class RefreshEngineHours implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $connectionId) {}

    public function uniqueId(): string
    {
        return 'hours:'.$this->connectionId;
    }

    public function handle(MinesSynchroniser $synchroniser): void
    {
        $connection = MinesConnection::withoutGlobalScopes()->find($this->connectionId);

        if ($connection !== null) {
            $synchroniser->refreshEngineHours($connection);
        }
    }
}
