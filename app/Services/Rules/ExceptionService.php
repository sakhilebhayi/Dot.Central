<?php

namespace App\Services\Rules;

use App\Models\OperationalException;
use App\Models\User;
use App\Services\Audits;

/**
 * The human side of an exception: explain, approve, reject, dismiss,
 * resolve a conflict. Every step is an event and an audit row.
 */
final class ExceptionService
{
    public function __construct(private readonly Audits $audits) {}

    public function explain(OperationalException $exception, User $user, string $text): void
    {
        $toApproval = in_array($exception->outcome, ['REQUIRES_APPROVAL', 'CONFLICT', 'INVALID', 'MISSING'], true) || $exception->severity === 'CRITICAL';
        $old = $exception->status;

        $exception->forceFill([
            'explanation' => $text, 'explained_by' => $user->id, 'explained_at' => now(),
            'status' => $toApproval ? 'EXPLAINED' : 'RESOLVED',
            'resolution' => $toApproval ? null : 'Explained by operator',
            'resolved_by' => $toApproval ? null : $user->id,
            'resolved_at' => $toApproval ? null : now(),
        ])->save();

        $exception->events()->create(['action' => 'explained', 'user_id' => $user->id, 'comment' => $text]);
        $this->audits->record('updated', $exception, ['status' => ['old' => $old, 'new' => $exception->status]], $text, $exception->controlRoom, $user);
    }

    public function decide(OperationalException $exception, User $user, string $decision, ?string $comment): void
    {
        $old = $exception->status;
        $status = $decision === 'APPROVED' ? 'APPROVED' : 'REJECTED';
        $exception->forceFill(['status' => $status, 'decided_by' => $user->id, 'decided_at' => now(), 'decision_comment' => $comment, 'resolution' => $status.($comment ? ': '.$comment : '')])->save();
        $exception->events()->create(['action' => strtolower($status), 'user_id' => $user->id, 'comment' => $comment]);
        $this->audits->record($decision === 'APPROVED' ? 'approve' : 'reject', $exception, ['status' => ['old' => $old, 'new' => $status]], $comment, $exception->controlRoom, $user);
    }

    public function dismiss(OperationalException $exception, User $user, string $reason): void
    {
        $old = $exception->status;
        $exception->forceFill(['status' => 'DISMISSED', 'decided_by' => $user->id, 'decided_at' => now(), 'resolution' => 'Dismissed: '.$reason])->save();
        $exception->events()->create(['action' => 'dismissed', 'user_id' => $user->id, 'comment' => $reason]);
        $this->audits->record('updated', $exception, ['status' => ['old' => $old, 'new' => 'DISMISSED']], $reason, $exception->controlRoom, $user);
    }

    public function resolveConflict(OperationalException $exception, User $user, string $choice, ?string $note): void
    {
        $old = $exception->status;
        $detail = $exception->detail ?? [];
        $label = match ($choice) {
            'A' => ($detail['source_a'] ?? 'Source A').' = '.($detail['value_a'] ?? '—'),
            'B' => ($detail['source_b'] ?? 'Source B').' = '.($detail['value_b'] ?? '—'),
            default => 'Neither — corrected at source',
        };
        $exception->forceFill(['status' => 'RESOLVED', 'resolved_by' => $user->id, 'resolved_at' => now(), 'resolution' => 'Conflict resolved: '.$label.($note ? ' — '.$note : '')])->save();
        $exception->events()->create(['action' => 'resolved', 'user_id' => $user->id, 'comment' => $label.($note ? ' — '.$note : '')]);
        $this->audits->record('approve', $exception, ['status' => ['old' => $old, 'new' => 'RESOLVED']], $note, $exception->controlRoom, $user);
    }
}
