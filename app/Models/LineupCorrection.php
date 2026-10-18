<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction of when a machine arrived on (ARRIVAL) or left (DEPARTURE)
 * a face. The service writes the audit row with the reason itself.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $lineup_allocation_id
 * @property int|null $partner_allocation_id
 * @property int $equipment_id
 * @property string $edge
 * @property Carbon $old_at
 * @property Carbon $new_at
 * @property string $reason
 * @property int|null $corrected_by
 */
class LineupCorrection extends Model
{
    use HasControlRoomTeamScope;

    public const ARRIVAL = 'ARRIVAL';

    public const DEPARTURE = 'DEPARTURE';

    public const EDGES = [self::ARRIVAL, self::DEPARTURE];

    protected $guarded = ['id'];

    protected $casts = ['old_at' => 'datetime', 'new_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<LineupAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LineupAllocation::class, 'lineup_allocation_id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
