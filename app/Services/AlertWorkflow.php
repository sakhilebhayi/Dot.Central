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

    /** @return PushOutcome the local change always succeeds; the outcome describes the optional push */
    public function acknowledge(Alert $alert, User $user, bool $push = false): PushOutcome
    {
        $alert->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => $user->id])->save();
        $outcome = $push ? $this->push($alert, 'acknowledge') : PushOutcome::notRequested();
        $alert->events()->create(['action' => 'acknowledged', 'user_id' => $user->id, 'pushed_to_source' => $outcome->pushed, 'note' => $outcome->failure]);
        $this->audits->record('ack', $alert, ['acknowledged_at' => ['old' => null, 'new' => now()->toIso8601String()]], 'Acknowledged'.$outcome->suffix(), $alert->controlRoom, $user);

        return $outcome;
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

    /** @return PushOutcome the local change always succeeds; the outcome describes the optional push */
    public function resolve(Alert $alert, User $user, ?string $note, bool $push = false): PushOutcome
    {
        $alert->forceFill(['cleared_at' => now(), 'resolved_by' => $user->id, 'resolution_note' => $note])->save();
        $outcome = $push ? $this->push($alert, 'resolve') : PushOutcome::notRequested();
        $alert->events()->create(['action' => 'resolved', 'user_id' => $user->id, 'note' => trim(($note ?? '').($outcome->failure ? ' ['.$outcome->failure.']' : '')) ?: null, 'pushed_to_source' => $outcome->pushed]);
        $this->audits->record('updated', $alert, ['cleared_at' => ['old' => null, 'new' => now()->toIso8601String()]], ($note ?? 'Resolved').$outcome->suffix(), $alert->controlRoom, $user);

        return $outcome;
    }

    /**
     * Push the action back to Dot.Mines. Only alerts that came from Dot.Mines
     * can be pushed; a refusal or outage is reported, never hidden.
     */
    private function push(Alert $alert, string $action): PushOutcome
    {
        $connection = $alert->controlRoom->minesConnection;

        if ($alert->source !== 'dot_mines' || $alert->external_id === null) {
            return PushOutcome::failed('this alert did not come from Dot.Mines');
        }

        if ($connection === null || ! $connection->isUsable()) {
            return PushOutcome::failed('the Dot.Mines connection is not usable');
        }

        try {
            $client = $this->clients->for($connection);
            $action === 'acknowledge' ? $client->acknowledgeAlert((int) $alert->external_id) : $client->resolveAlert((int) $alert->external_id);

            return PushOutcome::pushed();
        } catch (MinesException $e) {
            return PushOutcome::failed($e->getMessage());
        }
    }
}
