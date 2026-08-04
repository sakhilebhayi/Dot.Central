<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Conversation and AgentUsageLog are private per-user AI-agent chat data —
 * unlike the mining-dispatch domain (team-tenanted, see HasTeamScope),
 * a conversation with an agent belongs to one person, not their whole
 * team, so it is scoped by user_id rather than team_id. Applying this
 * trait replaces the ad-hoc where('user_id', ...) calls that were
 * previously scattered directly in routes/web.php's dashboard closure
 * with a single scope every query goes through, the same defense-in-depth
 * goal as HasTeamScope: a forgotten where('user_id', ...) in a future
 * controller/route can no longer leak another user's rows.
 *
 * mass-assignment still sets user_id explicitly at create time (see
 * AgentChat Livewire component); this scope only governs reads.
 */
trait HasUserScope
{
    protected static function bootHasUserScope(): void
    {
        static::addGlobalScope('user', function (Builder $builder): void {
            if (Auth::check()) {
                $builder->where($builder->getModel()->getTable().'.user_id', Auth::id());
            }
        });
    }
}
