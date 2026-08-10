<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, HasTeamScope;

    protected $fillable = [
        'team_id', 'name', 'slug', 'description', 'system_prompt',
        'model', 'avatar_path', 'is_active', 'capabilities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capabilities' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(AgentSkill::class, 'agent_agent_skill');
    }

    public function knowledge(): BelongsToMany
    {
        return $this->belongsToMany(AgentKnowledge::class, 'agent_agent_knowledge');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
