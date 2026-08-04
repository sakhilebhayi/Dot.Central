<?php

namespace App\Models;

use App\Models\Concerns\HasUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user token usage log. HasUserScope scopes every query to the
 * authenticated user, replacing the ad-hoc where('user_id', ...) call
 * that used to live directly in the dashboard route closure.
 */
class AgentUsageLog extends Model
{
    use HasUserScope;

    protected $fillable = ['user_id', 'agent_id', 'tokens_input', 'tokens_output'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
