<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    protected $fillable = [
        'name', 'slug', 'description', 'system_prompt',
        'model', 'avatar_path', 'is_active', 'capabilities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capabilities' => 'array',
    ];

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(AgentSkill::class, 'agent_agent_skill');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
