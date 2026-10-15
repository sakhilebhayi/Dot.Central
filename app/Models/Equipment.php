<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use App\Support\RoleClass;
use Carbon\CarbonInterface;
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
 * @property string|null $role_class
 * @property string|null $role_class_source
 * @property int|null $mines_operator_id
 * @property int|null $mines_operator_assignment_id
 * @property Carbon|null $mines_operator_assigned_at
 * @property string|null $mines_operator_shift
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
        'mines_operator_id' => 'integer',
        'mines_operator_assignment_id' => 'integer',
        'mines_operator_assigned_at' => 'datetime',
    ];

    /**
     * Dot.Mines' canonical equipment type (App\Support\EquipmentType there)
     * decides the role class; the heuristic only runs when Dot.Mines does not
     * know ('other' / missing) and an administrator has not set it (I-30).
     */
    public function applyEquipmentType(?string $equipmentType): void
    {
        if ($this->role_class_source === 'manual' && $this->role_class !== null) {
            return;
        }

        $class = match ($equipmentType) {
            'adt', 'haul_truck' => RoleClass::HAUL,
            'excavator', 'loader' => RoleClass::LOADER,
            'dozer', 'grader', 'water_truck', 'drill_rig', 'support' => RoleClass::SUPPORT,
            default => null,
        };

        if ($class === null) {
            $this->applyRoleClassGuess();

            return;
        }

        $this->role_class = $class;
        $this->role_class_source = 'mines';
    }

    /** Re-derive the role class unless an administrator set it. */
    public function applyRoleClassGuess(): void
    {
        if ($this->role_class_source === 'manual' && $this->role_class !== null) {
            return;
        }

        $this->role_class = RoleClass::guess($this->name, $this->model, $this->manufacturer, $this->machine_type, $this->capacity);
        $this->role_class_source = 'auto';
    }

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return HasMany<EquipmentMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(EquipmentMetric::class);
    }

    /** @return HasMany<EquipmentStatusEvent, $this> */
    public function statusEvents(): HasMany
    {
        return $this->hasMany(EquipmentStatusEvent::class);
    }

    /**
     * Record an observed transition of a Dot.Mines fact, once per change.
     */
    public function recordTransition(string $field, ?string $from, ?string $to, CarbonInterface $observedAt, string $source): void
    {
        if ($from === $to) {
            return;
        }

        $this->statusEvents()->create(['field' => $field, 'from_value' => $from, 'to_value' => $to, 'observed_at' => $observedAt, 'source' => $source]);
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
