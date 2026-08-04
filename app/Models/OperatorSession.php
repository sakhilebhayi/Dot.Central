<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\OperatorSessionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Control-room staffing context: an operator + shift, tied to a control
 * room. Deliberately carries no individual performance data — Dot.Brain
 * platforms/dot-central.md §8 explicitly withholds operator decision-speed
 * / override metrics from any surface.
 *
 * HasControlRoomTeamScope scopes every query to the current team's control
 * rooms, so a route-model-bound {operatorSession} belonging to another
 * team is invisible before OperatorSessionController's abort_unless()
 * check ever runs.
 */
class OperatorSession extends Model
{
    /** @use HasFactory<OperatorSessionFactory> */
    use HasFactory, HasControlRoomTeamScope;

    protected $fillable = [
        'control_room_id', 'user_id', 'shift_label', 'started_at', 'ended_at',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at'   => 'datetime',
    ];

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    public function operator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
