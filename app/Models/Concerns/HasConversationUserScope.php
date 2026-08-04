<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Message is one level removed from the user-tenancy boundary: it carries
 * conversation_id, not user_id directly. Conversation is already scoped by
 * HasUserScope, but the dashboard route previously queried Message
 * directly (Message::whereHas('conversation', fn ($q) => $q->where(
 * 'user_id', ...))) rather than only ever going through a Conversation
 * relation instance — this trait moves that same check into the query
 * layer itself, mirroring HasControlRoomTeamScope's role for the
 * mining-dispatch domain's child models.
 *
 * Requires the using model to define a conversation(): BelongsTo relation.
 */
trait HasConversationUserScope
{
    protected static function bootHasConversationUserScope(): void
    {
        static::addGlobalScope('conversation_user', function (Builder $builder): void {
            if (Auth::check()) {
                $userId = Auth::id();
                $builder->whereHas('conversation', function (Builder $q) use ($userId): void {
                    $q->where('user_id', $userId);
                });
            }
        });
    }
}
