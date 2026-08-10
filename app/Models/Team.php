<?php

namespace App\Models;

use Database\Factories\TeamFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Jetstream\Events\TeamCreated;
use Laravel\Jetstream\Events\TeamDeleted;
use Laravel\Jetstream\Events\TeamUpdated;
use Laravel\Jetstream\Team as JetstreamTeam;

class Team extends JetstreamTeam
{
    /** @use HasFactory<TeamFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'personal_team',
    ];

    /**
     * The event map for the model.
     *
     * @var array<string, class-string>
     */
    protected $dispatchesEvents = [
        'created' => TeamCreated::class,
        'updated' => TeamUpdated::class,
        'deleted' => TeamDeleted::class,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'personal_team' => 'boolean',
        ];
    }

    /**
     * The mining-dispatch control rooms owned by this team.
     */
    public function controlRooms(): HasMany
    {
        return $this->hasMany(ControlRoom::class);
    }

    /**
     * The AI agents owned by this team.
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }

    /**
     * The knowledge documents owned by this team, available to be
     * assigned to any of the team's agents.
     */
    public function agentKnowledge(): HasMany
    {
        return $this->hasMany(AgentKnowledge::class);
    }
}
