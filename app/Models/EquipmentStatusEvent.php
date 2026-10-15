<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An observed transition of a Dot.Mines fact (mines_status or
 * engine_running). Stoppages are intervals between these.
 *
 * @property int $equipment_id
 * @property string $field
 * @property string|null $from_value
 * @property string|null $to_value
 * @property Carbon $observed_at
 * @property string $source
 */
class EquipmentStatusEvent extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['observed_at' => 'datetime'];

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
