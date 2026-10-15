<?php

namespace App\Models;

use App\Support\Role;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One row of the append-only audit trail. Rows are written by
 * App\Services\Audits and can never be updated or deleted through the
 * application.
 *
 * @property int $id
 * @property Carbon $occurred_at
 * @property int|null $user_id
 * @property string|null $user_role
 * @property int|null $team_id
 * @property int|null $control_room_id
 * @property int|null $shift_id
 * @property string $entity_type
 * @property int|null $entity_id
 * @property string $action
 * @property array<string, array{old: mixed, new: mixed}>|null $changes
 * @property string|null $reason
 * @property bool $automatic
 * @property int|null $approved_by
 * @property string|null $ip
 */
class AuditEntry extends Model
{
    public const UPDATED_AT = null;

    public const ACTIONS = [
        'created', 'updated', 'deleted', 'override', 'approve', 'reject',
        'generate', 'import', 'login', 'ack', 'permission_denied',
    ];

    protected $table = 'audit_log';

    protected $fillable = [
        'occurred_at', 'user_id', 'user_role', 'team_id', 'control_room_id', 'shift_id',
        'entity_type', 'entity_id', 'action', 'changes', 'reason', 'automatic', 'approved_by', 'ip',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'changes' => 'array',
        'automatic' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /**
     * Entries the user may read: managers and administrators see the whole
     * team; supervisors see their rooms and team-level rows.
     *
     * @param  Builder<AuditEntry>  $query
     * @return Builder<AuditEntry>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        $team = $user->currentTeam;

        if ($team === null) {
            return $query->whereRaw('1 = 0');
        }

        $query->where('team_id', $team->id);

        if ($user->hasAnyRoleOn($team, Role::Manager, Role::Administrator)) {
            return $query;
        }

        $roomIds = ControlRoom::accessibleBy($user)->pluck('id');

        return $query->whereIn('control_room_id', $roomIds);
    }

    protected function performUpdate(Builder $query): bool
    {
        throw new LogicException('Audit entries are append-only and cannot be updated.');
    }

    protected function performDeleteOnModel(): void
    {
        throw new LogicException('Audit entries are append-only and cannot be deleted.');
    }
}
