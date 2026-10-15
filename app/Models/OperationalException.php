<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\OperationalExceptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A finding of the rule engine (DC-AL-1..7). Keyed per room so the same
 * condition never raises twice; human decisions are never overwritten.
 *
 * @property int $control_room_id
 * @property int|null $shift_id
 * @property int|null $equipment_id
 * @property string $key
 * @property string $rule_code
 * @property string $group
 * @property string $severity
 * @property string $outcome
 * @property string $title
 * @property string|null $message
 * @property string|null $fix
 * @property array<string, mixed>|null $detail
 * @property string $status
 * @property string|null $explanation
 * @property string|null $resolution
 * @property int|null $resolved_by
 */
class OperationalException extends Model
{
    /** @use HasFactory<OperationalExceptionFactory> */
    use Auditable, HasControlRoomTeamScope, HasFactory;

    public const SEVERITIES = ['CRITICAL', 'WARNING', 'REVIEW', 'INFO'];

    public const OUTCOMES = ['INVALID', 'SUSPICIOUS', 'REQUIRES_EXPLANATION', 'REQUIRES_APPROVAL', 'CONFLICT', 'MISSING', 'INFO'];

    public const STATUSES = ['OPEN', 'EXPLAINED', 'APPROVED', 'REJECTED', 'RESOLVED', 'DISMISSED'];

    public const RANK = ['CRITICAL' => 3, 'WARNING' => 2, 'REVIEW' => 1, 'INFO' => 0];

    protected $guarded = ['id'];

    protected $casts = [
        'detail' => 'array', 'raised_at' => 'datetime', 'last_seen_at' => 'datetime', 'explained_at' => 'datetime',
        'decided_at' => 'datetime', 'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return HasMany<ExceptionEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(ExceptionEvent::class);
    }

    /** @param Builder<OperationalException> $query */
    public function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereIn('status', ['OPEN', 'EXPLAINED']);
    }

    /** @param Builder<OperationalException> $query */
    public function scopeAwaitingDecision(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->where('status', 'EXPLAINED')->orWhere(fn (Builder $o) => $o->where('status', 'OPEN')->whereIn('outcome', ['REQUIRES_APPROVAL', 'CONFLICT'])));
    }

    public function isBlocking(): bool
    {
        return $this->status === 'OPEN' && $this->severity === 'CRITICAL';
    }

    public function tone(): string
    {
        return match ($this->severity) {
            'CRITICAL' => 'bad', 'WARNING' => 'warn', 'REVIEW' => 'review', default => 'info'
        };
    }
}
