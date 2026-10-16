<?php

namespace App\Services\Memory;

use App\Jobs\FlushMemoryOutbox;
use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MemoryOutboxRow;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The only way a record reaches Dot.Memory: into the local outbox in the
 * caller's transaction, sent later by FlushMemoryOutbox. Never throws
 * towards an operator.
 */
class MemoryRecorder
{
    public function __construct(private readonly MemoryClientFactory $clients, private readonly AuditLoopMapper $mapper) {}

    /** @param array<string, mixed> $payload */
    public function queue(int $teamId, string $stage, array $payload): ?MemoryOutboxRow
    {
        $connection = $this->clients->connectionFor($teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return null;
        }

        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        if ($eventId !== null) {
            $existing = MemoryOutboxRow::where('team_id', $teamId)->where('event_id', $eventId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $row = MemoryOutboxRow::create(['team_id' => $teamId, 'stage' => $stage, 'event_id' => $eventId, 'payload' => $payload]);

        FlushMemoryOutbox::dispatch($teamId)->afterCommit();

        return $row;
    }

    public function fromAudit(AuditEntry $entry): void
    {
        if ($entry->team_id === null) {
            return;
        }

        try {
            foreach ($this->mapper->map($entry) as $record) {
                $this->queue($entry->team_id, $record['stage'], $record['payload']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function observeAlert(Alert $alert): void
    {
        $teamId = $this->teamOf($alert);
        if ($teamId === null || $alert->source === 'system') {
            return;
        }
        $subject = LoopSubject::for($alert);
        $this->queue($teamId, 'event', [
            'loop_id' => 'dot-central:alert:'.$alert->id, 'event_id' => 'dot-central:alert:'.$alert->id.':triggered', 'source' => 'dot-mines-mirror',
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'subject_label' => $subject['label'], 'team_id' => $teamId, 'user_id' => null,
            'occurred_at' => ($alert->source_triggered_at ?? $alert->triggered_at)->toIso8601String(), 'signature' => 'dot-central:alert:'.strtolower($alert->severity),
            'detail' => ['title' => $alert->title, 'description' => $alert->description, 'severity' => $alert->severity, 'priority' => $alert->priority, 'source' => $alert->source, 'external_id' => $alert->external_id, 'control_room_id' => $alert->control_room_id],
        ]);
    }

    public function alertCleared(Alert $alert, string $by): void
    {
        $teamId = $this->teamOf($alert);
        if ($teamId === null || $alert->source === 'system' || $alert->cleared_at === null) {
            return;
        }
        $subject = LoopSubject::for($alert);
        $minutes = max(0, (int) round(($alert->source_triggered_at ?? $alert->triggered_at)->diffInMinutes($alert->cleared_at)));
        $this->queue($teamId, 'outcome', [
            'loop_id' => 'dot-central:alert:'.$alert->id, 'event_id' => 'dot-central:alert:'.$alert->id.':cleared', 'source' => 'dot-mines-mirror',
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'subject_label' => $subject['label'], 'team_id' => $teamId, 'user_id' => null,
            'occurred_at' => $alert->cleared_at->toIso8601String(), 'signature' => 'dot-central:alert:'.strtolower($alert->severity),
            'verdict' => 'improved', 'measure' => 'alert.time_to_clear_minutes', 'value_before' => null, 'value_after' => $minutes, 'notes' => 'cleared by '.$by,
            'detail' => ['control_room_id' => $alert->control_room_id],
        ]);
    }

    /** @param list<string> $blockers */
    public function assignmentRefused(Shift $shift, Equipment $unit, Operator $operator, array $blockers, ?User $user): void
    {
        $teamId = $this->teamOf($shift);
        if ($teamId === null) {
            return;
        }
        $this->queue($teamId, 'action', [
            'loop_id' => 'dot-central:shift:'.$shift->id, 'event_id' => 'dot-central:assignment-refused:'.$shift->id.':'.$unit->id.':'.$operator->id.':'.now()->timestamp, 'source' => 'dot-central-lineup',
            'subject_type' => 'equipment', 'subject_id' => (string) $unit->mines_machine_id, 'subject_label' => $unit->name, 'team_id' => $teamId, 'user_id' => $user?->id,
            'occurred_at' => now()->toIso8601String(), 'signature' => 'dot-central:operator:assign',
            'action_kind' => 'operator.assign', 'executor_platform' => 'dot-mines', 'approval_status' => 'not_required', 'execution_status' => 'refused',
            'detail' => ['blockers' => $blockers, 'operator_id' => $operator->id, 'shift_id' => $shift->id],
        ]);
    }

    private function teamOf(Model $model): ?int
    {
        $roomId = $model->getAttribute('control_room_id');
        $teamId = $roomId === null ? null : ControlRoom::withoutGlobalScopes()->where('id', $roomId)->value('team_id');

        return $teamId === null ? null : (int) $teamId;
    }
}
