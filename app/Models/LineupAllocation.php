<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A machine on a face for a span of the shift. to_at null = still there;
 * a move ends one row and starts another.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $lineup_face_id
 * @property int $equipment_id
 * @property string $role
 * @property Carbon $from_at
 * @property Carbon|null $to_at
 */
class LineupAllocation extends Model
{
    use Auditable, HasControlRoomTeamScope;

    public const ROLES = ['LOADER', 'HAUL', 'SUPPORT'];

    public const ROLE_LABELS = ['LOADER' => 'Loading unit', 'HAUL' => 'Haul truck', 'SUPPORT' => 'Support machine'];

    protected $guarded = ['id'];

    protected $casts = ['from_at' => 'datetime', 'to_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<LineupFace, $this> */
    public function face(): BelongsTo
    {
        return $this->belongsTo(LineupFace::class, 'lineup_face_id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return HasMany<LineupCorrection, $this> */
    public function corrections(): HasMany
    {
        return $this->hasMany(LineupCorrection::class);
    }
}
