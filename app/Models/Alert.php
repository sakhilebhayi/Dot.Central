<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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
    use Auditable, HasControlRoomTeamScope, HasFactory;

    public const SEVERITIES = ['info', 'warning', 'critical'];

    public const SOURCES = ['manual', 'dot_mines', 'system'];

    protected $fillable = [
        'control_room_id', 'source', 'external_id', 'severity', 'priority', 'equipment_id',
        'title', 'description', 'metadata', 'triggered_at', 'source_triggered_at', 'cleared_at',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'source_triggered_at' => 'datetime',
        'cleared_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }
}
