<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Database\Factories\ControlRoomFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tenant root of the mining-dispatch domain. Maps 1:1 to a Dot.Mines site
 * (via mines_site_ref — a plain external reference, not a live integration)
 * and is scoped to a Jetstream team, matching this repo's existing tenancy
 * pattern. HasTeamScope makes that scoping automatic at the query layer
 * instead of depending on every controller remembering belongsToTeam().
 */
class ControlRoom extends Model
{
    /** @use HasFactory<ControlRoomFactory> */
    use HasFactory, HasTeamScope;

    protected $fillable = [
        'team_id', 'name', 'slug', 'mines_site_ref', 'is_active',
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
}
