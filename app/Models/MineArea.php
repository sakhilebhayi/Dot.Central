<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Dot.Mines mine area mirrored into the room (reference sync scope).
 * Not Auditable: written by synchronisation only.
 *
 * @property int $id
 * @property int $control_room_id
 * @property int $mines_area_id
 * @property string $name
 * @property string|null $status
 */
class MineArea extends Model
{
    use HasControlRoomTeamScope;

    protected $guarded = ['id'];

    protected $casts = ['mines_area_id' => 'integer', 'sync_version' => 'integer', 'last_synced_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }
}
