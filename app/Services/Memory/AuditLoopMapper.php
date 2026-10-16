<?php

namespace App\Services\Memory;

use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\LineupAllocation;
use App\Models\LineupStandby;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * Pure translation of one audit row into intelligence-loop records
 * (spec §5). Nothing here is typed by anyone: the decision text is the
 * operator's own reason or explanation, the verdicts are computed.
 */
final class AuditLoopMapper
{
    private const RISK = ['critical' => 0.9, 'warning' => 0.5, 'review' => 0.2, 'info' => 0.1];

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    public function map(AuditEntry $entry): array
    {
        $subject = $this->subject($entry);

        if ($subject === null || $entry->team_id === null) {
            return [];
        }

        return match (true) {
            $subject instanceof OperationalException => $this->exception($entry, $subject),
            $subject instanceof Alert => $this->alert($entry, $subject),
            $subject instanceof DowntimeClassification => $this->downtime($entry, $subject),
            $subject instanceof ShiftLineup => $this->lineup($entry, $subject),
            $subject instanceof ShiftOperatorAssignment => $this->assignment($entry, $subject),
            $subject instanceof Shift => $this->shift($entry, $subject),
            default => [],
        };
    }

    private function subject(AuditEntry $entry): ?Model
    {
        $class = $entry->entity_type;

        if ($entry->entity_id === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::withoutGlobalScopes()->find($entry->entity_id);
    }

    /** @return array<string, mixed> */
    private function base(AuditEntry $entry, Model $model, string $loopKey, string $signature, string $eventKey): array
    {
        $subject = LoopSubject::for($model);

        return [
            'loop_id' => 'dot-central:'.$loopKey,
            'event_id' => 'dot-central:'.$loopKey.':'.$eventKey,
            'source' => 'dot-central-audit',
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'subject_label' => $subject['label'],
            'team_id' => $entry->team_id,
            'user_id' => $entry->user_id,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'signature' => $signature,
            'detail' => ['audit_id' => $entry->id, 'control_room_id' => $entry->control_room_id, 'shift_id' => $entry->shift_id, 'reason' => $entry->reason],
        ];
    }

    private function newValue(AuditEntry $entry, string $key): mixed
    {
        return $entry->changes[$key]['new'] ?? null;
    }

    private function has(AuditEntry $entry, string $key): bool
    {
        return is_array($entry->changes) && array_key_exists($key, $entry->changes);
    }

    private function risk(string $severity): float
    {
        return self::RISK[strtolower($severity)] ?? 0.5;
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function exception(AuditEntry $entry, OperationalException $e): array
    {
        $signature = 'dot-central:exception:'.$e->rule_code;
        $base = fn (string $key) => $this->base($entry, $e, 'exception:'.$e->id, $signature, $key);
        $status = $this->newValue($entry, 'status');

        if ($entry->action === 'created') {
            return [['stage' => 'event', 'payload' => $base('raised') + ['detail' => $base('raised')['detail'] + ['title' => $e->title, 'message' => $e->message, 'outcome' => $e->outcome, 'severity' => $e->severity, 'raised_by' => $e->raised_by]]]];
        }

        if ($status === 'EXPLAINED') {
            return [['stage' => 'decision', 'payload' => $base('explained') + ['recommendation' => (string) ($entry->reason ?? $e->explanation ?? 'explained'), 'confidence' => 1.0, 'risk' => $this->risk($e->severity), 'autonomy_level' => 'approve', 'requires_approval' => true]]];
        }

        if (in_array($entry->action, ['approve', 'reject'], true) && in_array($status, ['APPROVED', 'REJECTED'], true)) {
            $approved = $status === 'APPROVED';

            return [['stage' => 'action', 'payload' => $base($approved ? 'approved' : 'rejected') + ['action_kind' => $approved ? 'exception.approve' : 'exception.reject', 'executor_platform' => 'dot-central', 'approval_status' => $approved ? 'approved' : 'rejected', 'execution_status' => 'succeeded']]];
        }

        if ($status === 'RESOLVED') {
            return [['stage' => 'outcome', 'payload' => $base('resolved') + ['verdict' => 'improved', 'measure' => 'exception.status', 'value_before' => 'OPEN', 'value_after' => 'RESOLVED', 'notes' => $entry->reason]]];
        }

        if ($status === 'DISMISSED') {
            return [['stage' => 'outcome', 'payload' => $base('dismissed') + ['verdict' => 'inconclusive', 'measure' => 'exception.status', 'value_before' => 'OPEN', 'value_after' => 'DISMISSED', 'notes' => $entry->reason]]];
        }

        return [];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function alert(AuditEntry $entry, Alert $a): array
    {
        // Mirror writes (no actor) are recorded by the synchroniser's own
        // hooks; the model's automatic audit rows carry no reason, while the
        // workflow's explicit rows always do, and only those know the push
        // result. Records are keyed by state, so a duplicate collapses.
        if ($a->source === 'system' || $entry->action === 'created' || $entry->user_id === null || ($entry->action !== 'ack' && $entry->reason === null && ! $this->has($entry, 'assigned_to'))) {
            return [];
        }

        $signature = 'dot-central:alert:'.strtolower($a->severity);
        $base = fn (string $key) => $this->base($entry, $a, 'alert:'.$a->id, $signature, $key);
        $push = $this->newValue($entry, 'push');
        $execution = match ($push) {
            'pushed' => 'succeeded', 'refused' => 'refused', default => 'pending'
        };
        $action = fn (string $kind, string $key) => ['stage' => 'action', 'payload' => $base($key) + ['action_kind' => $kind, 'executor_platform' => $push === 'pushed' ? 'dot-mines' : 'dot-central', 'approval_status' => 'not_required', 'execution_status' => $execution]];

        if ($entry->action === 'ack') {
            return [$action('alert.acknowledge', 'acknowledge')];
        }

        if ($this->has($entry, 'cleared_at')) {
            $minutes = $a->cleared_at !== null ? max(0, (int) round(($a->source_triggered_at ?? $a->triggered_at)->diffInMinutes($a->cleared_at))) : null;

            return [$action('alert.resolve', 'resolve'), ['stage' => 'outcome', 'payload' => $base('cleared') + ['verdict' => 'improved', 'measure' => 'alert.time_to_clear_minutes', 'value_before' => null, 'value_after' => $minutes, 'notes' => $entry->reason]]];
        }

        if ($this->has($entry, 'escalation_level')) {
            return [$action('alert.escalate', 'escalate:'.(int) $this->newValue($entry, 'escalation_level'))];
        }

        if ($this->has($entry, 'assigned_to')) {
            return [$action('alert.assign', 'assign:'.(int) $this->newValue($entry, 'assigned_to'))];
        }

        return [];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function downtime(AuditEntry $entry, DowntimeClassification $d): array
    {
        if ($entry->action !== 'created') {
            return [];
        }

        $reason = DowntimeReason::withoutGlobalScopes()->find($d->downtime_reason_id);
        $signature = 'dot-central:downtime:'.($reason->code ?? 'unknown');
        $detail = ['reason' => $reason?->name, 'category' => $reason?->category, 'counts_against_availability' => (bool) $reason?->counts_against_availability, 'note' => $d->note, 'started_at' => $d->started_at->toIso8601String(), 'ended_at' => $d->ended_at?->toIso8601String()];

        return [
            ['stage' => 'decision', 'payload' => $this->base($entry, $d, 'downtime:'.$d->id, $signature, 'decision') + ['recommendation' => (string) ($reason->name ?? 'classified'), 'confidence' => 1.0, 'risk' => $reason?->counts_against_availability ? 0.5 : 0.2, 'autonomy_level' => 'execute', 'requires_approval' => false, 'detail' => $detail]],
            ['stage' => 'action', 'payload' => $this->base($entry, $d, 'downtime:'.$d->id, $signature, 'action') + ['action_kind' => 'downtime.classify', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded', 'detail' => $detail]],
        ];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function lineup(AuditEntry $entry, ShiftLineup $l): array
    {
        if ($entry->action !== 'approve') {
            return [];
        }

        $version = (int) ($this->newValue($entry, 'version') ?? $l->version);
        $faces = $l->shift()->withoutGlobalScopes()->first()?->faces()->withoutGlobalScopes()->whereNull('closed_at')->count() ?? 0;
        $placed = LineupAllocation::withoutGlobalScopes()->where('shift_lineup_id', $l->id)->whereNull('to_at')->count();
        $standby = LineupStandby::withoutGlobalScopes()->where('shift_lineup_id', $l->id)->whereNull('to_at')->count();
        $detail = ['version' => $version, 'faces' => $faces, 'placed' => $placed, 'standby' => $standby];
        $signature = 'dot-central:lineup:confirmed';

        return [
            ['stage' => 'decision', 'payload' => $this->base($entry, $l, 'lineup:'.$l->id, $signature, 'decision:v'.$version) + ['recommendation' => 'line-up v'.$version, 'confidence' => 1.0, 'risk' => 0.3, 'autonomy_level' => 'execute', 'requires_approval' => false, 'detail' => $detail]],
            ['stage' => 'action', 'payload' => $this->base($entry, $l, 'lineup:'.$l->id, $signature, 'action:v'.$version) + ['action_kind' => 'lineup.confirm', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded', 'detail' => $detail]],
        ];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function assignment(AuditEntry $entry, ShiftOperatorAssignment $s): array
    {
        if ($entry->action !== 'created') {
            return [];
        }

        return [['stage' => 'action', 'payload' => $this->base($entry, $s, 'shift:'.$s->shift_id, 'dot-central:operator:assign', 'assignment:'.$s->id) + ['action_kind' => 'operator.assign', 'executor_platform' => $s->pushed_to_source ? 'dot-mines' : 'dot-central', 'approval_status' => 'not_required', 'execution_status' => $s->pushed_to_source ? 'succeeded' : 'pending', 'detail' => ['push_note' => $s->push_note, 'operator_id' => $s->operator_id, 'shift_id' => $s->shift_id]]]];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function shift(AuditEntry $entry, Shift $shift): array
    {
        $status = $this->newValue($entry, 'status');
        $base = fn (string $sig, string $key) => $this->base($entry, $shift, 'shift:'.$shift->id, $sig, $key);

        if ($status === 'OPEN') {
            return [['stage' => 'event', 'payload' => $base('dot-central:shift:started', 'started')]];
        }

        if ($status === 'CLOSED') {
            $after = $this->stoppageHours($shift);
            $previous = Shift::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->whereIn('status', ['CLOSED', 'APPROVED'])->where('id', '!=', $shift->id)->where('starts_at', '<', $shift->starts_at)->orderByDesc('starts_at')->first();
            $before = $previous === null ? null : $this->stoppageHours($previous);
            $verdict = match (true) {
                $before === null => 'inconclusive', $after < $before => 'improved', $after > $before => 'worsened', default => 'unchanged'
            };

            return [['stage' => 'outcome', 'payload' => $base('dot-central:shift:closed', 'closed') + ['verdict' => $verdict, 'measure' => 'shift.stoppage_hours', 'value_before' => $before, 'value_after' => $after, 'notes' => $entry->reason]]];
        }

        if ($status === 'APPROVED') {
            return [['stage' => 'action', 'payload' => $base('dot-central:shift:approved', 'approved') + ['action_kind' => 'shift.approve', 'executor_platform' => 'dot-central', 'approval_status' => 'approved', 'execution_status' => 'succeeded']]];
        }

        if ($status === 'REOPENED') {
            return [['stage' => 'action', 'payload' => $base('dot-central:shift:reopened', 'reopened:'.$entry->id) + ['action_kind' => 'shift.reopen', 'executor_platform' => 'dot-central', 'approval_status' => 'approved', 'execution_status' => 'succeeded']]];
        }

        return [];
    }

    private function stoppageHours(Shift $shift): float
    {
        return round(((int) $shift->unitSummaries()->sum('stoppage_minutes')) / 60, 2);
    }
}
