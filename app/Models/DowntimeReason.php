<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A room's classification catalogue for observed stoppages.
 *
 * @property int $control_room_id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property bool $counts_against_availability
 * @property bool $requires_description
 */
class DowntimeReason extends Model
{
    public const CATEGORIES = ['BREAKDOWN', 'PLANNED_MAINTENANCE', 'STANDBY', 'NO_OPERATOR', 'OPERATIONAL_DELAY', 'WEATHER', 'BLASTING', 'OFFSITE', 'SAFETY', 'OTHER'];

    protected $guarded = ['id'];

    protected $casts = ['counts_against_availability' => 'boolean', 'requires_description' => 'boolean', 'is_active' => 'boolean'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }
}
