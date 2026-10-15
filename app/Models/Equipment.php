<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\EquipmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A Dot.Mines machine mirrored into a control room. Not Auditable: rows are
 * written by synchronisation (thousands per run); each run is audited once.
 *
 * @property int $id
 * @property int $control_room_id
 * @property int $mines_machine_id
 * @property string $name
 * @property string|null $machine_type
 * @property string|null $model
 * @property string|null $manufacturer
 * @property string|null $serial_number
 * @property string $mines_status
 * @property string|null $allocation_state
 * @property int|null $mine_area_id
 * @property string|null $mine_area_name
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $location_recorded_at
 * @property float|null $fuel_level
 * @property float|null $engine_hours
 * @property Carbon|null $engine_hours_recorded_at
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $last_heard_at
 * @property int|null $sync_version
 * @property array<string, mixed>|null $raw
 * @property Carbon|null $retired_at
 */
class Equipment extends Model
{
    /** @use HasFactory<EquipmentFactory> */
    use HasControlRoomTeamScope, HasFactory;

    protected $table = 'equipment';

    protected $guarded = ['id'];

    protected $casts = [
        'mines_machine_id' => 'integer',
        'mine_area_id' => 'integer',
        'capacity' => 'float',
        'fuel_capacity' => 'float',
        'latitude' => 'float',
        'longitude' => 'float',
        'fuel_level' => 'float',
        'engine_hours' => 'float',
        'sync_version' => 'integer',
        'raw' => 'array',
        'location_recorded_at' => 'datetime',
        'engine_hours_recorded_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_heard_at' => 'datetime',
        'first_synced_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'retired_at' => 'datetime',
    ];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return HasMany<EquipmentHourReading, $this> */
    public function hourReadings(): HasMany
    {
        return $this->hasMany(EquipmentHourReading::class);
    }

    /** @return HasMany<Alert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    /**
     * The newest provider clock we hold for this machine.
     */
    public function recomputeLastHeard(): void
    {
        $candidates = array_filter([$this->location_recorded_at, $this->engine_hours_recorded_at]);
        $this->last_heard_at = $candidates === [] ? null : max($candidates);
    }
}
