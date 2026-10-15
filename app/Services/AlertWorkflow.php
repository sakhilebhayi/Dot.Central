<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\User;
use App\Services\Mines\Exceptions\MinesException;
use App\Services\Mines\MinesClientFactory;

/**
 * Acknowledge → assign → escalate → resolve, each an event and an audit
 * row. Resolving (or acknowledging) a Dot.Mines alert may be pushed back
 * to Dot.Mines when the user asks; that push is recorded and never assumed.
 */
final class AlertWorkflow
{
    public function __construct(private readonly Audits $audits, private readonly MinesClientFactory $clients) {}

    public function acknowledge(Alert $alert, User $user, bool $push = false): void
    {
        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $user->id])->save();
        $pushed = $push && $this->push($alert, 'acknowledge');
        $alert->events()->create(['action' => 'acknowledged', 'user_id' => $user->id, 'pushed_to_source' => $pushed]);
        $this->audits->record('ack', $alert, ['acknowledged_at' => ['old' => null, 'new' => now()->toIso8601String()]], $pushed ? 'Acknowledged (pushed to Dot.Mines)' : 'Acknowledged', $alert->controlRoom, $user);
    }

    public function assign(Alert $alert, User $user, User $assignee, ?string $note): void
    {
        $old = $alert->assigned_to;
        $alert->forceFill(['assigned_to' => $assignee->id, 'acknowledged_at' => $alert->acknowledged_at ?? now(), 'acknowledged_by' => $alert->acknowledged_by ?? $user->id])->save();
        $alert->events()->create(['action' => 'assigned', 'user_id' => $user->id, 'target_user_id' => $assignee->id, 'note' => $note]);
        $this->audits->record('updated', $alert, ['assigned_to' => ['old' => $old, 'new' => $assignee->id]], $note, $alert->controlRoom, $user);
    }

    public function escalate(Alert $alert, User $user, string $note): void
    {
        $level = $alert->escalation_level + 1;
        $alert->forceFill(['escalation_level' => $level, 'escalated_at' => now()])->save();
        $alert->events()->create(['action' => 'escalated', 'user_id' => $user->id, 'note' => $note]);
        $this->audits->record('updated', $alert, ['escalation_level' => ['old' => $level - 1, 'new' => $level]], $note, $alert->controlRoom, $user);
    }

    public function resolve(Alert $alert, User $user, ?string $note, bool $push = false): void
    {
        $alert->forceFill(['cleared_at' => now(), 'resolved_by' => $user->id, 'resolution_note' => $note])->save();
        $pushed = $push && $this->push($alert, 'resolve');
        $alert->events()->create(['action' => 'resolved', 'user_id' => $user->id, 'note' => $note, 'pushed_to_source' => $pushed]);
        $this->audits->record('updated', $alert, ['cleared_at' => ['old' => null, 'new' => now()->toIso8601String()]], ($note ?? 'Resolved').($pushed ? ' (pushed to Dot.Mines)' : ''), $alert->controlRoom, $user);
    }

    private function push(Alert $alert, string $action): bool
    {
        $connection = $alert->controlRoom->minesConnection;

        if ($alert->source !== 'dot_mines' || $alert->external_id === null || $connection === null || ! $connection->isUsable()) {
            return false;
        }

        try {
            $client = $this->clients->for($connection);
            $action === 'acknowledge' ? $client->acknowledgeAlert((int) $alert->external_id) : $client->resolveAlert((int) $alert->external_id);

            return true;
        } catch (MinesException) {
            return false;
        }
    }
}
