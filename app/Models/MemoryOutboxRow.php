<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record waiting to be sent to Dot.Memory. Written in the same
 * transaction as the operator's action; sent by FlushMemoryOutbox.
 *
 * @property int $id
 * @property int $team_id
 * @property string $stage
 * @property string|null $event_id
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 */
class MemoryOutboxRow extends Model
{
    public const MAX_ATTEMPTS = 20;

    /** @var list<int> minutes before the next attempt, by attempt number */
    public const BACKOFF_MINUTES = [1, 5, 15, 60];

    protected $table = 'memory_outbox';

    protected $fillable = ['team_id', 'stage', 'event_id', 'payload', 'attempts', 'last_error', 'next_attempt_at', 'sent_at'];

    protected $casts = ['payload' => 'array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    /** @param Builder<MemoryOutboxRow> $query */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('sent_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id');
    }

    public function backoff(): Carbon
    {
        $minutes = self::BACKOFF_MINUTES[min($this->attempts, count(self::BACKOFF_MINUTES)) - 1] ?? 60;

        return now()->addMinutes($minutes);
    }
}
