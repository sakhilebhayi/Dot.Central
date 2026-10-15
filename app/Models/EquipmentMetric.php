<?php

namespace App\Models;

use Database\Factories\EquipmentMetricFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Dot.Mines metric row (provider clock). Cumulative counters
 * (operating_hours, idle_hours, load_count) are differenced per shift.
 *
 * @property int $equipment_id
 * @property Carbon $recorded_at
 * @property float|null $operating_hours
 * @property float|null $idle_hours
 * @property float|null $fuel_level
 * @property float|null $speed
 * @property bool|null $engine_running
 * @property int|null $load_count
 * @property float|null $payload_cumulative
 * @property float|null $odometer
 */
class EquipmentMetric extends Model
{
    /** @use HasFactory<EquipmentMetricFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'recorded_at' => 'datetime',
        'ingested_at' => 'datetime',
        'operating_hours' => 'float',
        'idle_hours' => 'float',
        'fuel_level' => 'float',
        'speed' => 'float',
        'engine_running' => 'boolean',
        'load_count' => 'integer',
        'payload_cumulative' => 'float',
        'odometer' => 'float',
    ];

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
