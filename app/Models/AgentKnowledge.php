<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Database\Factories\AgentKnowledgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentKnowledge extends Model
{
    /** @use HasFactory<AgentKnowledgeFactory> */
    use HasFactory, HasTeamScope;

    protected $table = 'agent_knowledge';

    protected $fillable = ['team_id', 'title', 'content', 'source_type', 'original_filename'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_agent_knowledge');
    }
}
