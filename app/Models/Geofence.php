<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Dot.Mines geofence (pit, stockpile, dump, facility) mirrored into the
 * room. Not Auditable: written by synchronisation only.
 *
 * @property int $control_room_id
 * @property int $mines_geofence_id
 * @property string $name
 * @property string|null $type
 * @property string|null $status
 * @property int|null $mine_area_id
 * @property Carbon|null $retired_at
 */
class Geofence extends Model
{
    use HasControlRoomTeamScope;

    protected $guarded = ['id'];

    protected $casts = ['mines_geofence_id' => 'integer', 'mine_area_id' => 'integer', 'last_synced_at' => 'datetime', 'retired_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }
}
