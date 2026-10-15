<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator's reason for an observed stoppage. It never changes the
 * machine fact; it annotates it.
 *
 * @property int $control_room_id
 * @property int $equipment_id
 * @property int|null $shift_id
 * @property int $downtime_reason_id
 * @property Carbon $started_at
 * @property Carbon|null $ended_at
 * @property string|null $note
 * @property int|null $classified_by
 */
class DowntimeClassification extends Model
{
    use Auditable, HasControlRoomTeamScope;

    protected $guarded = ['id'];

    protected $casts = ['started_at' => 'datetime', 'ended_at' => 'datetime'];

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

    /** @return BelongsTo<User, $this> */
    public function classifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'classified_by');
    }
}
