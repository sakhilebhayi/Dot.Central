<?php

namespace App\Models;

use Database\Factories\AgentSkillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentSkill extends Model
{
    /** @use HasFactory<AgentSkillFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description', 'icon'];

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_agent_skill');
    }
}
