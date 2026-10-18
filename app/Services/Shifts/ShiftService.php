<?php

namespace App\Services\Shifts;

use App\Models\ControlRoom;
use App\Models\LineupCorrection;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Audits;
use App\Services\Lineup\LineupService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The shift lifecycle (12-derived-operations-design.md §3). Operators
 * confirm a proposal, close with notes and acknowledge; supervisors approve
 * and reopen. Everything else is derived.
 */
final class ShiftService
{
    public function __construct(
        private readonly ShiftDerivation $derivation,
        private readonly ShiftBlockers $blockers,
        private readonly Audits $audits,
        private readonly LineupService $lineup,
    ) {}

    public function current(ControlRoom $room): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('control_room_id', $room->id)->current()->orderByDesc('starts_at')->first();
    }

    public function previous(ControlRoom $room): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('control_room_id', $room->id)->whereIn('status', ['CLOSED', 'APPROVED'])->orderByDesc('ends_at')->first();
    }

    /**
     * The next shift to open: the one after the last shift of the room, or
     * the shift type whose window contains now.
     *
     * @return array{shift_type: ShiftType|null, shift_date: string, starts_at: Carbon|null, ends_at: Carbon|null, previous: Shift|null}
     */
    public function propose(ControlRoom $room): array
    {
        $timezone = $room->timezone();
        $types = $room->shiftTypes()->where('is_active', true)->get();
        $last = Shift::withoutGlobalScopes()->where('control_room_id', $room->id)->orderByDesc('ends_at')->first();
        $previous = $this->previous($room);

        if ($types->isEmpty()) {
            return ['shift_type' => null, 'shift_date' => now($timezone)->toDateString(), 'starts_at' => null, 'ends_at' => null, 'previous' => $previous];
        }

        if ($last !== null) {
            $index = $types->search(fn (ShiftType $t) => $t->id === $last->shift_type_id);
            $next = $types[($index === false ? -1 : $index) + 1] ?? null;
            $date = $last->shift_date->copy();
            if ($next === null) {
                $next = $types->first();
                $date = $date->addDay();
            }
            [$start, $end] = $next->windowFor($date->toDateString(), $timezone);

            return ['shift_type' => $next, 'shift_date' => $date->toDateString(), 'starts_at' => $start, 'ends_at' => $end, 'previous' => $previous];
        }

        $now = now();
        foreach ([now($timezone)->toDateString(), now($timezone)->subDay()->toDateString()] as $date) {
            foreach ($types as $type) {
                [$start, $end] = $type->windowFor($date, $timezone);
                if ($now->betweenIncluded($start, $end)) {
                    return ['shift_type' => $type, 'shift_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'previous' => $previous];
                }
            }
        }

        $type = $types->first();
        [$start, $end] = $type->windowFor(now($timezone)->toDateString(), $timezone);

        return ['shift_type' => $type, 'shift_date' => now($timezone)->toDateString(), 'starts_at' => $start, 'ends_at' => $end, 'previous' => $previous];
    }

    public function open(ControlRoom $room, ShiftType $type, string $date, User $user): Shift
    {
        if ($type->control_room_id !== $room->id) {
            throw ValidationException::withMessages(['shift_type_id' => 'That shift type belongs to another control room.']);
        }

        if (($current = $this->current($room)) !== null) {
            throw ValidationException::withMessages(['shift' => $current->label().' is still '.$current->status.'. Close it under End Shift before starting the next one.']);
        }

        $existing = Shift::withoutGlobalScopes()->where('control_room_id', $room->id)->where('shift_type_id', $type->id)->whereDate('shift_date', $date)->first();

        if ($existing !== null && $existing->status !== 'PLANNED') {
            throw ValidationException::withMessages(['shift' => 'A '.$type->name.' for '.$date.' already exists (status '.$existing->status.'). Open the existing shift instead.']);
        }

        [$start, $end] = $type->windowFor($date, $room->timezone());

        $shift = $existing ?? new Shift(['control_room_id' => $room->id, 'shift_type_id' => $type->id, 'shift_date' => $date]);
        $shift->fill(['starts_at' => $start, 'ends_at' => $end, 'status' => 'OPEN', 'opened_by' => $user->id, 'opened_at' => now()])->save();

        $previous = $this->previous($room);
        if ($previous?->handover !== null && $previous->handover->acknowledged_at === null) {
            $this->acknowledge($previous->handover, $user);
        }

        $this->audits->record('updated', $shift, ['status' => ['old' => $existing?->status, 'new' => 'OPEN']], 'Shift started', $room, $user);

        return $shift;
    }

    /**
     * Recompute the derivation, move an overdue OPEN shift to IN_REVIEW and
     * report readiness.
     *
     * @return array{units: Collection<int, array<string, mixed>>, totals: array<string, mixed>, blocking: Collection<int, array{code: string, title: string}>, unknown: list<string>}
     */
    public function review(Shift $shift): array
    {
        if ($shift->status === 'OPEN' && $shift->ends_at->lessThan(now())) {
            $shift->forceFill(['status' => 'IN_REVIEW'])->save();
        }

        $units = $this->derivation->unitSummaries($shift);
        $totals = $this->derivation->totals($units);

        return ['units' => $units, 'totals' => $totals, 'blocking' => $this->blockers->blocking($shift), 'unknown' => $totals['unknown_now']];
    }

    public function close(Shift $shift, ?string $notes, User $user): Shift
    {
        if ($shift->isClosed()) {
            throw ValidationException::withMessages(['shift' => 'This shift is already closed.']);
        }

        $review = $this->review($shift);

        if ($review['blocking']->isNotEmpty()) {
            throw ValidationException::withMessages(['shift' => $review['blocking']->count().' critical item(s) are still open. Explain them or wait for the data to correct before closing.']);
        }

        $shift->unitSummaries()->delete();
        foreach ($review['units'] as $unit) {
            $shift->unitSummaries()->create(collect($unit)->except(['equipment'])->all());
        }

        $snapshot = $this->snapshot($shift, $review, $notes);
        $shift->fill(['status' => 'CLOSED', 'closed_by' => $user->id, 'closed_at' => now(), 'notes' => $notes !== null && $notes !== '' ? $notes : $shift->notes, 'summary' => $review['totals']])->save();

        $handover = ShiftHandover::updateOrCreate(['shift_id' => $shift->id], ['notes' => $shift->notes, 'snapshot' => $snapshot, 'issued_by' => $user->id, 'issued_at' => now(), 'acknowledged_by' => null, 'acknowledged_at' => null]);
        $this->audits->record('updated', $shift, ['status' => ['old' => 'OPEN', 'new' => 'CLOSED']], 'Shift closed and handover issued', $shift->controlRoom, $user);

        return $shift;
    }

    public function acknowledge(ShiftHandover $handover, User $user): void
    {
        $handover->forceFill(['acknowledged_by' => $user->id, 'acknowledged_at' => now()])->save();
        $this->audits->record('ack', $handover, [], 'Handover acknowledged', $handover->shift->controlRoom, $user);
    }

    public function approve(Shift $shift, User $user, ?string $comment): void
    {
        if ($shift->status !== 'CLOSED') {
            throw ValidationException::withMessages(['shift' => 'Only a CLOSED shift can be approved.']);
        }

        $shift->forceFill(['status' => 'APPROVED', 'approved_by' => $user->id, 'approved_at' => now()])->save();
        $this->audits->record('approve', $shift, ['status' => ['old' => 'CLOSED', 'new' => 'APPROVED']], $comment, $shift->controlRoom, $user);
    }

    public function reopen(Shift $shift, User $user, string $reason): void
    {
        if (! $shift->isClosed()) {
            throw ValidationException::withMessages(['shift' => 'Only a closed or approved shift can be reopened.']);
        }

        if (($current = $this->current($shift->controlRoom)) !== null && $current->id !== $shift->id) {
            throw ValidationException::withMessages(['shift' => $current->label().' is open; close it before reopening another shift.']);
        }

        $old = $shift->status;
        $shift->forceFill(['status' => 'REOPENED', 'reopened_by' => $user->id, 'reopened_at' => now(), 'reopen_reason' => $reason, 'approved_by' => null, 'approved_at' => null])->save();
        $this->audits->record('override', $shift, ['status' => ['old' => $old, 'new' => 'REOPENED']], $reason, $shift->controlRoom, $user);
    }

    /**
     * @param  array{units: Collection<int, array<string, mixed>>, totals: array<string, mixed>, blocking: Collection<int, array{code: string, title: string}>}  $review
     * @return array<string, mixed>
     */
    private function snapshot(Shift $shift, array $review, ?string $notes): array
    {
        return [
            'shift' => ['label' => $shift->label(), 'starts_at' => $shift->starts_at->toIso8601String(), 'ends_at' => $shift->ends_at->toIso8601String()],
            'fleet' => $review['totals'],
            'open_items' => $review['blocking']->values()->all(),
            'lineup' => $this->lineupSnapshot($shift),
            'notes' => $notes,
            'built_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Faces, machines and operators as they stand at the end of the shift,
     * plus the machines on standby; what the next shift starts from.
     *
     * @return array<string, mixed>
     */
    private function lineupSnapshot(Shift $shift): array
    {
        $record = $this->lineup->lineup($shift);
        $pool = $this->lineup->pool($shift)->keyBy(fn (array $r) => $r['equipment']->id);
        $faces = [];

        foreach ($this->lineup->faces($shift) as $face) {
            $members = $face->allocations()->whereNull('to_at')->get();
            $names = fn (string $role) => $members->where('role', $role)->map(fn ($a) => $pool[$a->equipment_id]['equipment']->name ?? '?')->values()->all();
            $faces[] = ['label' => $face->label(), 'kind' => $face->kind, 'what' => $face->what(), 'cut' => $face->cut, 'blocks' => $face->blocks ?? [], 'loaders' => $names('LOADER'), 'trucks' => $names('HAUL'), 'support' => $names('SUPPORT')];
        }

        return [
            'status' => $record->exists ? $record->status : 'NONE',
            'version' => $record->version,
            'faces' => $faces,
            'operators' => $pool->filter(fn (array $r) => $r['operator'] !== null)->map(fn (array $r) => $r['operator']->label())->mapWithKeys(fn ($label, $id) => [$pool[$id]['equipment']->name => $label])->all(),
            'standby_now' => $pool->where('state', 'STANDBY')->map(fn (array $r) => ['name' => $r['equipment']->name, 'reason' => $r['standby']?->reason?->name, 'since' => $r['standby']?->from_at?->toIso8601String()])->values()->all(),
            'down_now' => $pool->where('state', 'DOWN')->map(fn (array $r) => ['name' => $r['equipment']->name, 'cause' => $r['down']['cause'] ?? null, 'since' => $r['down']['since']?->toIso8601String()])->values()->all(),
            'corrections' => LineupCorrection::withoutGlobalScopes()->with('correctedBy')->where('shift_id', $shift->id)->orderBy('id')->get()
                ->map(fn (LineupCorrection $c) => ['text' => $this->lineup->describeCorrection($c), 'reason' => $c->reason, 'by' => $c->correctedBy?->name, 'at' => $c->created_at?->toIso8601String()])->values()->all(),

        ];
    }
}
