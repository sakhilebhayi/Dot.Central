<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\ProductionRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Dot.Mines production record (per machine per day/shift), mirrored
 * from the /sync production scope. Telemetry-derived in Dot.Mines.
 *
 * @property int $control_room_id
 * @property int $mines_record_id
 * @property int|null $equipment_id
 * @property Carbon $record_date
 * @property string|null $shift
 * @property float|null $quantity
 * @property string|null $unit
 */
class ProductionRecord extends Model
{
    /** @use HasFactory<ProductionRecordFactory> */
    use HasControlRoomTeamScope, HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'record_date' => 'date',
        'quantity' => 'float',
        'sync_version' => 'integer',
        'last_synced_at' => 'datetime',
    ];

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
}
