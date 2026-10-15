<?php

namespace App\Models;

use Database\Factories\EquipmentHourReadingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $equipment_id
 * @property float $hours
 * @property Carbon $recorded_at
 * @property string $source
 */
class EquipmentHourReading extends Model
{
    /** @use HasFactory<EquipmentHourReadingFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'hours' => 'float',
        'recorded_at' => 'datetime',
        'ingested_at' => 'datetime',
    ];

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
