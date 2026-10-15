<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\OperatorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A machine operator known to a control room. Room configuration until
 * Dot.Mines exposes its operator register (I-28); columns mirror it.
 *
 * @property int $id
 * @property int $control_room_id
 * @property int|null $mines_operator_id
 * @property string $employee_number
 * @property string $first_name
 * @property string $last_name
 * @property string|null $preferred_name
 * @property string|null $tag_id
 * @property bool $is_active
 */
class Operator extends Model
{
    /** @use HasFactory<OperatorFactory> */
    use Auditable, HasControlRoomTeamScope, HasFactory;

    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'mines_operator_id' => 'integer'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    public function name(): string
    {
        return trim(($this->preferred_name ?: $this->first_name).' '.$this->last_name);
    }

    /** "Name · employee number", the form the line-up shows and resolves. */
    public function label(): string
    {
        return $this->name().' · '.$this->employee_number;
    }
}
