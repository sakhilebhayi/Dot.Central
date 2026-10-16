<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A loading face (loading unit + material + cut + blocks, trucks hauling)
 * or a work area (support work) on a shift's line-up.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $seq
 * @property string $kind
 * @property int|null $mine_area_id
 * @property string|null $location_source
 * @property int|null $location_ref
 * @property string|null $location_name
 * @property string|null $material
 * @property string|null $activity
 * @property string|null $cut
 * @property list<string>|null $blocks
 * @property string|null $notes
 * @property string $status
 * @property Carbon $active_from
 * @property Carbon|null $closed_at
 */
class LineupFace extends Model
{
    use Auditable, HasControlRoomTeamScope;

    public const KINDS = ['LOADING', 'AREA'];

    protected $guarded = ['id'];

    protected $casts = ['seq' => 'integer', 'blocks' => 'array', 'active_from' => 'datetime', 'closed_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<Shift, $this> */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /** @return BelongsTo<MineArea, $this> */
    public function mineArea(): BelongsTo
    {
        return $this->belongsTo(MineArea::class);
    }

    /** @return HasMany<LineupAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(LineupAllocation::class);
    }

    public function isClosed(): bool
    {
        return $this->status === 'CLOSED';
    }

    public function what(): string
    {
        return $this->kind === 'AREA' ? ($this->activity ?: 'Work area') : ($this->material ?: 'Loading face');
    }

    public function label(): string
    {
        $where = ($this->cut ? ' · '.$this->cut : '').($this->blocks ? ' / '.implode(' ', $this->blocks) : '');
        $location = $this->location_name !== null ? $this->location_name.' — ' : '';

        return 'Face '.$this->seq.' — '.$location.$this->what().$where;
    }

    /** Faces at the same cut sharing a block may share a loading unit. */
    public function sameWorkplaceAs(LineupFace $other): bool
    {
        return $this->cut !== null && $this->cut === $other->cut && array_intersect($this->blocks ?? [], $other->blocks ?? []) !== [];
    }
}
