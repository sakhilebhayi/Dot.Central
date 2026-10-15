<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\ShiftTypeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A room's shift pattern entry (e.g. DAY 06:00–18:00). Times are local to
 * the room's connection timezone.
 *
 * @property int $control_room_id
 * @property string $code
 * @property string $name
 * @property string $starts_at HH:MM
 * @property string $ends_at HH:MM
 * @property bool $crosses_midnight
 */
class ShiftType extends Model
{
    /** @use HasFactory<ShiftTypeFactory> */
    use Auditable, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['crosses_midnight' => 'boolean', 'is_active' => 'boolean'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /**
     * The window for a given calendar date in the given timezone, as UTC.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function windowFor(string $date, string $timezone): array
    {
        $start = Carbon::parse($date.' '.$this->starts_at, $timezone);
        $end = Carbon::parse($date.' '.$this->ends_at, $timezone);

        if ($this->crosses_midnight || $end->lessThanOrEqualTo($start)) {
            $end = $end->addDay();
        }

        return [$start->utc(), $end->utc()];
    }
}
