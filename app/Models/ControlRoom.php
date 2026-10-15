<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTeamScope;
use App\Support\Role;
use Database\Factories\ControlRoomFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tenant root of the control-room domain (one room = one site). Maps to
 * one Dot.Mines team through its MinesConnection and is scoped to a
 * Jetstream team, matching this repo's existing tenancy pattern. HasTeamScope makes that scoping automatic at the query layer
 * instead of depending on every controller remembering belongsToTeam().
 */
class ControlRoom extends Model
{
    /** @use HasFactory<ControlRoomFactory> */
    use Auditable, HasFactory, HasTeamScope;

    protected $fillable = [
        'team_id', 'name', 'slug', 'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function dispatchDecisions(): HasMany
    {
        return $this->hasMany(DispatchDecision::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function operatorSessions(): HasMany
    {
        return $this->hasMany(OperatorSession::class);
    }

    public function staleSessionProposals(): HasMany
    {
        return $this->hasMany(StaleSessionProposal::class);
    }

    /**
     * Machines mirrored from Dot.Mines into this room.
     */
    public function equipment(): HasMany
    {
        return $this->hasMany(Equipment::class);
    }

    /**
     * The Dot.Mines connection (one per room), if configured.
     */
    public function minesConnection(): HasOne
    {
        return $this->hasOne(MinesConnection::class);
    }

    /**
     * Team members assigned to this room (site scope).
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    /**
     * Whether the user may see this room. Managers and administrators see
     * every room of the team; other members see rooms they are assigned to.
     */
    public function isAccessibleBy(User $user): bool
    {
        if (! $user->belongsToTeam($this->team)) {
            return false;
        }

        if ($user->hasAnyRoleOn($this->team, Role::Manager, Role::Administrator)) {
            return true;
        }

        return $this->users()->whereKey($user->id)->exists();
    }

    /**
     * Rooms the user may see within their current team.
     *
     * @param  Builder<ControlRoom>  $query
     * @return Builder<ControlRoom>
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->hasAnyRoleOn($team, Role::Manager, Role::Administrator)) {
            return $query->where('team_id', $team->id);
        }

        return $query->where('team_id', $team->id)
            ->whereHas('users', fn (Builder $users) => $users->whereKey($user->id));
    }
}
