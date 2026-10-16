<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The line-up record of a shift: DRAFT until confirmed, then versioned.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property string $status
 * @property int $version
 * @property int|null $confirmed_by
 * @property Carbon|null $confirmed_at
 */
class ShiftLineup extends Model
{
    use Auditable, HasControlRoomTeamScope;

    protected $guarded = ['id'];

    protected $casts = ['version' => 'integer', 'confirmed_at' => 'datetime'];

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

    public function isConfirmed(): bool
    {
        return $this->status === 'CONFIRMED';
    }
}
