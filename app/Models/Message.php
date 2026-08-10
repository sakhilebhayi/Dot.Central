<?php

namespace App\Models;

use App\Models\Concerns\HasConversationUserScope;
use Database\Factories\MessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
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
    /** @use HasFactory<MessageFactory> */
    use HasConversationUserScope, HasFactory;

    protected $fillable = ['conversation_id', 'role', 'content', 'tokens_used'];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
