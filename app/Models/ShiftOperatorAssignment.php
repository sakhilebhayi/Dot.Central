<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who operates a machine on a shift. One open row per machine and one
 * per operator per shift (LU-04).
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $equipment_id
 * @property int $operator_id
 * @property Carbon $from_at
 * @property Carbon|null $to_at
 */
class ShiftOperatorAssignment extends Model
{
    use Auditable, HasControlRoomTeamScope;

    protected $guarded = ['id'];

    protected $casts = ['from_at' => 'datetime', 'to_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<Operator, $this> */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }
}
