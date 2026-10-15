<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $shift_id
 * @property string|null $notes
 * @property array<string, mixed> $snapshot
 * @property Carbon $issued_at
 * @property int|null $acknowledged_by
 * @property Carbon|null $acknowledged_at
 */
class ShiftHandover extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'array', 'issued_at' => 'datetime', 'acknowledged_at' => 'datetime'];

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** @return BelongsTo<User, $this> */
    public function acknowledger(): BelongsTo
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function auditControlRoom(): ?ControlRoom
    {
        return $this->shift?->controlRoom;
    }
}
