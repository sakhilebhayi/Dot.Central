<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Dot.Central is Jetstream-Teams tenanted, not single-user (see Team,
 * TeamPolicy). ControlRoom is the mining-dispatch domain's tenant root and
 * carries a team_id column directly, so it applies this trait the same way
 * Dot.Mines' HasTeamFilters scopes every tenant-owned model to the current
 * team — the goal is that a forgotten where('team_id', ...)/belongsToTeam()
 * check in a future controller can no longer leak another team's rows,
 * because the model itself never returns unscoped results while a user is
 * authenticated with a current team.
 *
 * Only apply this trait to models that carry team_id directly. Models one
 * level removed (control_room_id, not team_id) use HasControlRoomTeamScope
 * instead, which joins through control_rooms to reach the same team_id.
 *
 * mass-assignment still sets team_id explicitly at create time (see
 * ControlRoomController::store()); this scope only governs reads.
 */
trait HasTeamScope
{
    protected static function bootHasTeamScope(): void
    {
        static::addGlobalScope('team', function (Builder $builder): void {
            if (Auth::check() && Auth::user()->currentTeam) {
                $builder->where($builder->getModel()->getTable().'.team_id', Auth::user()->currentTeam->id);
            }
        });
    }
}
