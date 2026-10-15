<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\AlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'acknowledged_at', 'acknowledged_by', 'assigned_to', 'escalated_at', 'escalation_level', 'resolved_by', 'resolution_note',
    ];

    protected $casts = [
        'triggered_at' => 'datetime',
        'source_triggered_at' => 'datetime',
        'cleared_at' => 'datetime',
        'acknowledged_at' => 'datetime',
        'escalated_at' => 'datetime',
        'escalation_level' => 'integer',
        'metadata' => 'array',
    ];

    /** @return HasMany<AlertEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(AlertEvent::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function workflowState(): string
    {
        return match (true) {
            $this->cleared_at !== null => 'resolved',
            $this->escalation_level > 0 => 'escalated',
            $this->assigned_to !== null => 'assigned',
            $this->acknowledged_at !== null => 'acknowledged',
            default => 'active',
        };
    }

    /** @return BelongsTo<ControlRoom, $this> */
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
