<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Why a machine is not on the line-up: standby, no operator, off site.
 * Ends automatically when the machine is placed.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $equipment_id
 * @property int $downtime_reason_id
 * @property Carbon $from_at
 * @property Carbon|null $to_at
 */
class LineupStandby extends Model
{
    use Auditable, HasControlRoomTeamScope;

    public const CATEGORIES = ['STANDBY', 'NO_OPERATOR', 'OFFSITE'];

    protected $table = 'lineup_standbys';

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

    /** @return BelongsTo<DowntimeReason, $this> */
    public function reason(): BelongsTo
    {
        return $this->belongsTo(DowntimeReason::class, 'downtime_reason_id');
    }
}
