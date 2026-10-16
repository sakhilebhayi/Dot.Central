<?php

namespace App\Services;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\User;
use App\Services\Memory\MemoryRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Writes the append-only audit trail (docs/dot-central/07-security-and-permissions.md §5).
 * Every operational action calls record() in the same request/transaction.
 */
class Audits
{
    public const REDACTED = '[redacted]';

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @param  list<string>  $redact  attribute names whose values must never be stored
     */
    public function record(
        string $action,
        ?Model $subject = null,
        array $changes = [],
        ?string $reason = null,
        ?ControlRoom $controlRoom = null,
        ?User $actor = null,
        ?bool $automatic = null,
        ?int $approvedBy = null,
        ?int $shiftId = null,
        array $redact = [],
        ?string $entityType = null,
    ): AuditEntry {
        $actor ??= Auth::user();
        $controlRoom ??= $this->controlRoomOf($subject);
        $team = $controlRoom?->team ?? $actor?->currentTeam;

        foreach ($redact as $attribute) {
            if (array_key_exists($attribute, $changes)) {
                $changes[$attribute] = ['old' => self::REDACTED, 'new' => self::REDACTED];
            }
        }

        $entry = AuditEntry::query()->create([
            'occurred_at' => now(),
            'user_id' => $actor?->id,
            'user_role' => ($actor !== null && $team !== null) ? $actor->roleOn($team)?->value : null,
            'team_id' => $team?->id,
            'control_room_id' => $controlRoom?->id,
            'shift_id' => $shiftId,
            'entity_type' => $entityType ?? ($subject !== null ? $subject::class : 'system'),
            'entity_id' => $subject?->getKey(),
            'action' => $action,
            'changes' => $changes === [] ? null : $changes,
            'reason' => $reason,
            'automatic' => $automatic ?? ($actor === null),
            'approved_by' => $approvedBy,
            'ip' => app()->runningInConsole() ? null : request()->ip(),
        ]);

        // Dot.Memory remembers decisions and outcomes; derived from this row,
        // queued locally, never able to fail the caller.
        app(MemoryRecorder::class)->fromAudit($entry);

        return $entry;
    }

    private function controlRoomOf(?Model $subject): ?ControlRoom
    {
        if ($subject instanceof ControlRoom) {
            return $subject;
        }

        if ($subject !== null && method_exists($subject, 'auditControlRoom')) {
            return $subject->auditControlRoom();
        }

        return null;
    }
}
