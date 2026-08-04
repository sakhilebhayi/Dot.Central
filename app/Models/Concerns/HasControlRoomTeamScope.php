<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * DispatchDecision, Alert, and OperatorSession are one level removed from
 * the tenant boundary: they carry control_room_id, not team_id directly.
 * ControlRoom (the tenant root) is already scoped by HasTeamScope, but that
 * only protects queries that go *through* a ControlRoom relation — every
 * controller in this domain also does direct route-model binding straight
 * to these child models (Alert $alert, OperatorSession $operatorSession,
 * DispatchDecision $dispatchDecision), bypassing that. Each controller
 * currently re-derives $model->controlRoom and manually
 * abort_unless($user->belongsToTeam(...)) after the fact — this trait moves
 * that check into the query layer itself, the same way HasTeamScope does
 * for ControlRoom, so a forgotten abort_unless() in a future controller
 * can no longer leak another team's row.
 *
 * Requires the using model to define a controlRoom(): BelongsTo relation.
 * mass-assignment still sets control_room_id explicitly at create time
 * (each controller creates via $controlRoom->relation()->create(...));
 * this scope only governs reads.
 */
trait HasControlRoomTeamScope
{
    protected static function bootHasControlRoomTeamScope(): void
    {
        static::addGlobalScope('control_room_team', function (Builder $builder): void {
            if (Auth::check() && Auth::user()->currentTeam) {
                $teamId = Auth::user()->currentTeam->id;
                $builder->whereHas('controlRoom', function (Builder $q) use ($teamId): void {
                    $q->where('team_id', $teamId);
                });
            }
        });
    }
}
