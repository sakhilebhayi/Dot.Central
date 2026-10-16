<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\ShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A shift of a control room: PLANNED → OPEN → IN_REVIEW → CLOSED → APPROVED,
 * with REOPENED (supervisor, reason) looping back to editable.
 *
 * @property int $control_room_id
 * @property int $shift_type_id
 * @property Carbon $shift_date
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $status
 * @property string|null $notes
 * @property array<string, mixed>|null $summary
 */
class Shift extends Model
{
    /** @use HasFactory<ShiftFactory> */
    use Auditable, HasControlRoomTeamScope, HasFactory;

    public const CURRENT = ['OPEN', 'IN_REVIEW', 'REOPENED'];

    public const STATUSES = ['PLANNED', 'OPEN', 'IN_REVIEW', 'CLOSED', 'APPROVED', 'REOPENED'];

    protected $guarded = ['id'];

    protected $casts = [
        'shift_date' => 'date',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
        'approved_at' => 'datetime',
        'reopened_at' => 'datetime',
        'summary' => 'array',
    ];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<ShiftType, $this> */
    public function shiftType(): BelongsTo
    {
        return $this->belongsTo(ShiftType::class);
    }

    /** @return HasOne<ShiftHandover, $this> */
    public function handover(): HasOne
    {
        return $this->hasOne(ShiftHandover::class);
    }

    /** @return HasOne<ShiftLineup, $this> */
    public function lineup(): HasOne
    {
        return $this->hasOne(ShiftLineup::class);
    }

    /** @return HasMany<LineupFace, $this> */
    public function faces(): HasMany
    {
        return $this->hasMany(LineupFace::class);
    }

    /** @return HasMany<ShiftUnitSummary, $this> */
    public function unitSummaries(): HasMany
    {
        return $this->hasMany(ShiftUnitSummary::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function isCurrent(): bool
    {
        return in_array($this->status, self::CURRENT, true);
    }

    public function isClosed(): bool
    {
        return in_array($this->status, ['CLOSED', 'APPROVED'], true);
    }

    /** @param Builder<Shift> $query */
    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereIn('status', self::CURRENT);
    }

    public function label(): string
    {
        $type = $this->shiftType;

        return $this->shift_date->format('j M Y').' · '.($type instanceof ShiftType ? $type->name : 'Shift');
    }

    /** The window end to use for derivations: now while the shift is still running. */
    public function effectiveEnd(): Carbon
    {
        $now = now();

        return $this->isClosed() || $this->ends_at->lessThan($now) ? $this->ends_at : $now;
    }
}
