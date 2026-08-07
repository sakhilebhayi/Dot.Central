<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A threshold or sentinel trip tied to a control room
 * (Dot.Brain platforms/dot-central.md §2).
 *
 * HasControlRoomTeamScope scopes every query to the current team's control
 * rooms, so a route-model-bound {alert} belonging to another team is
 * invisible before AlertController's abort_unless() check ever runs.
 */
class Alert extends Model
{
    /** @use HasFactory<AlertFactory> */
    use HasControlRoomTeamScope, HasFactory;

    public const SEVERITIES = ['info', 'warning', 'critical'];

    protected $fillable = [
        'control_room_id', 'severity', 'title', 'description',
        'triggered_at', 'cleared_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'cleared_at' => 'datetime',
    ];

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }
}
