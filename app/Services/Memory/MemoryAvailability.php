<?php

namespace App\Services\Memory;

use App\Models\MemoryConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Whether the signed-in user's team has a usable Dot.Memory connection.
 * Memoised on the current request: the recall strip asks once per page,
 * not once per card.
 */
final class MemoryAvailability
{
    private const KEY = 'dot-central.memory-enabled';

    public static function enabled(): bool
    {
        $request = request();

        if ($request->attributes->has(self::KEY)) {
            return (bool) $request->attributes->get(self::KEY);
        }

        $user = Auth::user();
        $team = $user instanceof User ? $user->currentTeam : null;
        $enabled = false;

        if ($team instanceof Team) {
            $connection = MemoryConnection::withoutGlobalScopes()->where('team_id', $team->id)->first();
            $enabled = $connection !== null && $connection->isUsable();
        }

        $request->attributes->set(self::KEY, $enabled);

        return $enabled;
    }
}
