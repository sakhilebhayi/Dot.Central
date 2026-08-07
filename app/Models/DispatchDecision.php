<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\DispatchDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The core unit of record of the mining-dispatch domain: a dispatch decision
 * taken in a control room, identified by room + timestamp + sequence
 * (Dot.Brain platforms/dot-central.md §2).
 *
 * Dispatch "workflow" is intentionally kept as an enum on this table rather
 * than a separate model/table — per that doc it's a fixed four-value lookup
 * per site, not an entity with independent lifecycle.
 *
 * HasControlRoomTeamScope scopes every query to the current team's control
 * rooms, so a route-model-bound {dispatchDecision} belonging to another
 * team is invisible before DispatchDecisionController's abort_unless()
 * check ever runs.
 */
class DispatchDecision extends Model
{
    /** @use HasFactory<DispatchDecisionFactory> */
    use HasControlRoomTeamScope, HasFactory;

    public const WORKFLOW_TYPES = ['assign', 'reroute', 'hold', 'stagger'];

    protected $fillable = [
        'control_room_id', 'workflow_type', 'sequence',
        'decided_at', 'decided_by_user_id', 'summary',
    ];

    protected $casts = [
        'decided_at' => 'datetime',
    ];

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }
}
