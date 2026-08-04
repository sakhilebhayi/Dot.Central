<?php

namespace App\Models;

use App\Models\Concerns\HasConversationUserScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * HasConversationUserScope scopes every query to conversations owned by
 * the authenticated user, replacing the ad-hoc
 * Message::whereHas('conversation', fn ($q) => $q->where('user_id', ...))
 * call that used to live directly in the dashboard route closure.
 */
class Message extends Model
{
    use HasConversationUserScope;

    protected $fillable = ['conversation_id', 'role', 'content', 'tokens_used'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
