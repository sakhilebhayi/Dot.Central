<?php

namespace App\Services\Lineup;

use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use App\Models\User;
use App\Services\Audits;
use App\Support\RoleClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * The shift line-up (13-shift-lineup-design.md): faces, placements,
 * standby reasons, operator per machine, copy from the previous shift,
 * checks and confirmation. DOWN is derived from Dot.Mines and the
 * operator's classifications; it is never typed here.
 *
 * Refusals carry the prototype's codes as LU-xx in a ValidationException.
 *
 * @phpstan-type PoolRow array{equipment: Equipment, role_class: string, state: string, placements: Collection<int, LineupAllocation>, placement: LineupAllocation|null, standby: LineupStandby|null, down: array{cause: string, since: Carbon|null}|null, assignment: ShiftOperatorAssignment|null, operator: Operator|null}
 */
final class LineupService
{
    public const STOPPED = ['offline', 'maintenance'];

    public function __construct(private readonly Audits $audits) {}

    // ---- state -----------------------------------------------------------------------------------------------

    public function lineup(Shift $shift): ShiftLineup
    {
        return ShiftLineup::withoutGlobalScopes()->firstOrNew(['shift_id' => $shift->id], ['control_room_id' => $shift->control_room_id, 'status' => 'DRAFT', 'version' => 0]);
    }

    public function isConfirmed(Shift $shift): bool
    {
        return $this->lineup($shift)->isConfirmed();
    }

    /** Planning: not confirmed and the shift has not started; changes take effect from shift start. */
    public function isPlanning(Shift $shift): bool
    {
        return ! $this->isConfirmed($shift) && now()->lessThan($shift->starts_at);
    }

    /** "Now" kept inside the shift window (a minute before the end at the latest). */
    public function refTime(Shift $shift): Carbon
    {
        $latest = $shift->ends_at->copy()->subMinute();

        return now()->max($shift->starts_at)->min($latest);
    }

    /**
     * The time a change is recorded at: shift start while planning,
     * otherwise the HH:MM the operator set (inside the shift, not in the
     * future; LU-05) or now.
     */
    public function changeAt(Shift $shift, ?string $hhmm = null): Carbon
    {
        if ($this->isPlanning($shift)) {
            return $shift->starts_at->copy();
        }

        $hhmm = trim((string) $hhmm);
        if ($hhmm === '') {
            return $this->refTime($shift);
        }

        if (preg_match('/^(\d{1,2}):?(\d{2})$/', $hhmm, $m) !== 1 || (int) $m[1] > 23 || (int) $m[2] > 59) {
            $this->refuse('LU-05', '"'.$hhmm.'" is not a time. Enter the time as HH:MM.');
        }

        $timezone = $shift->controlRoom->timezone();
        $startLocal = $shift->starts_at->copy()->setTimezone($timezone);
        $at = $startLocal->copy()->setTime((int) $m[1], (int) $m[2]);
        if ($at->lessThan($startLocal)) {
            $at->addDay();
        }
        $at = $at->utc();

        if ($at->lessThan($shift->starts_at) || $at->greaterThan($shift->ends_at)) {
            $this->refuse('LU-05', $hhmm.' is outside '.$shift->label().' ('.$startLocal->format('H:i').'–'.$shift->ends_at->copy()->setTimezone($timezone)->format('H:i').'). Record the change at a time inside this shift.');
        }
        if ($at->greaterThan(now()->addMinute())) {
            $this->refuse('LU-05', $hhmm.' has not happened yet. Record moves when they happen, or at an earlier time.');
        }

        return $at;
    }

    /** @return Collection<int, LineupFace> */
    public function faces(Shift $shift, bool $includeClosed = false): Collection
    {
        return LineupFace::withoutGlobalScopes()->where('shift_id', $shift->id)->when(! $includeClosed, fn ($q) => $q->where('status', 'ACTIVE'))->with('mineArea')->orderBy('seq')->get();
    }

    /**
     * DOWN at a moment: Dot.Mines reported the machine offline / in
     * maintenance (observed status events), or an availability-counting
     * classification covers the moment.
     *
     * @return array{cause: string, since: Carbon|null}|null
     */
    public function downAt(Equipment $unit, Carbon $at): ?array
    {
        $event = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '<=', $at)->orderByDesc('observed_at')->first();
        $status = $event->to_value ?? $unit->mines_status;

        if (in_array($status, self::STOPPED, true)) {
            return ['cause' => 'Dot.Mines: '.$status, 'since' => $event?->observed_at];
        }

        $classification = DowntimeClassification::withoutGlobalScopes()->with('reason')->where('equipment_id', $unit->id)
            ->where('started_at', '<=', $at)->where(fn ($q) => $q->whereNull('ended_at')->orWhere('ended_at', '>', $at))
            ->get()->first(fn (DowntimeClassification $c) => $c->reason?->counts_against_availability === true);

        if ($classification !== null) {
            return ['cause' => (string) $classification->reason?->name, 'since' => $classification->started_at];
        }

        return null;
    }

    /**
     * Every machine on the shift with its line-up state at the reference time.
     *
     * @return Collection<int, PoolRow>
     */
    public function pool(Shift $shift): Collection
    {
        $at = $this->refTime($shift);
        $units = Equipment::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->whereNull('retired_at')->orderBy('name')->get();
        $faces = $this->faces($shift)->keyBy('id');
        $allocations = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('to_at')->get()->filter(fn (LineupAllocation $a) => $faces->has($a->lineup_face_id))->each(fn (LineupAllocation $a) => $a->setRelation('face', $faces[$a->lineup_face_id]))->groupBy('equipment_id');
        $standbys = LineupStandby::withoutGlobalScopes()->with('reason')->where('shift_id', $shift->id)->whereNull('to_at')->get()->keyBy('equipment_id');
        $assignments = ShiftOperatorAssignment::withoutGlobalScopes()->with('operator')->where('shift_id', $shift->id)->whereNull('to_at')->get()->keyBy('equipment_id');

        return $units->toBase()->map(function (Equipment $unit) use ($at, $allocations, $standbys, $assignments): array {
            $placements = $allocations->get($unit->id, collect())->filter(fn ($a): bool => $a instanceof LineupAllocation)->values();
            $down = $this->downAt($unit, $at);
            $standby = $standbys->get($unit->id);
            $assignment = $assignments->get($unit->id);

            return [
                'equipment' => $unit,
                'role_class' => $unit->role_class ?? RoleClass::SUPPORT,
                'state' => $down !== null ? 'DOWN' : ($placements->isNotEmpty() ? 'PLACED' : ($standby !== null ? 'STANDBY' : 'FREE')),
                'placements' => $placements,
                'placement' => $placements->first(),
                'standby' => $standby,
                'down' => $down,
                'assignment' => $assignment,
                'operator' => $assignment?->operator,
            ];
        })->values();
    }

    /** @return array<string, int> */
    public function summary(Shift $shift): array
    {
        $pool = $this->pool($shift);
        $faces = $this->faces($shift);
        $count = fn (callable $f) => $pool->filter($f)->count();
        $isHaul = fn (array $p) => $p['role_class'] === RoleClass::HAUL;
        $isLoader = fn (array $p) => $p['role_class'] === RoleClass::LOADER;

        return [
            'faces' => $faces->count(), 'loading_faces' => $faces->where('kind', 'LOADING')->count(),
            'trucks_placed' => $count(fn ($p) => $isHaul($p) && $p['state'] === 'PLACED'), 'trucks_total' => $count($isHaul), 'trucks_free' => $count(fn ($p) => $isHaul($p) && $p['state'] === 'FREE'),
            'loaders_placed' => $count(fn ($p) => $isLoader($p) && $p['state'] === 'PLACED'), 'loaders_total' => $count($isLoader),
            'placed' => $count(fn ($p) => $p['state'] === 'PLACED'), 'down' => $count(fn ($p) => $p['state'] === 'DOWN'), 'standby' => $count(fn ($p) => $p['state'] === 'STANDBY'), 'free' => $count(fn ($p) => $p['state'] === 'FREE'),
            'placed_with_operator' => $count(fn ($p) => $p['state'] === 'PLACED' && $p['operator'] !== null), 'units' => $pool->count(),
        ];
    }

    // ---- faces -----------------------------------------------------------------------------------------------

    /**
     * @param  array{kind?: string, mine_area_id?: int|null, material?: string|null, activity?: string|null, cut?: string|null, blocks?: list<string>|null, notes?: string|null, loader_ids?: list<int>}  $attrs
     */
    public function addFace(Shift $shift, array $attrs, User $user, ?string $hhmm = null): LineupFace
    {
        $this->guardWritable($shift);
        $at = $this->changeAt($shift, $hhmm);
        $this->validateFace($attrs);

        $seq = (int) LineupFace::withoutGlobalScopes()->where('shift_id', $shift->id)->max('seq') + 1;
        $face = LineupFace::create([
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'seq' => $seq,
            'kind' => $attrs['kind'] ?? 'LOADING', 'mine_area_id' => $attrs['mine_area_id'] ?? null,
            'material' => ($attrs['kind'] ?? 'LOADING') === 'LOADING' ? ($attrs['material'] ?? null) : null,
            'activity' => ($attrs['kind'] ?? 'LOADING') === 'AREA' ? ($attrs['activity'] ?? null) : null,
            'cut' => isset($attrs['cut']) && $attrs['cut'] !== '' ? strtoupper(trim($attrs['cut'])) : null,
            'blocks' => array_values(array_unique(array_map('strtoupper', array_filter(array_map('trim', $attrs['blocks'] ?? []))))) ?: null,
            'notes' => isset($attrs['notes']) && trim($attrs['notes']) !== '' ? trim($attrs['notes']) : null,
            'status' => 'ACTIVE', 'active_from' => $at,
        ]);
        $this->lineup($shift)->save();

        foreach ($attrs['loader_ids'] ?? [] as $id) {
            $unit = Equipment::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->findOrFail($id);
            $this->place($shift, $face, $unit, $user, $hhmm, true);
        }

        return $face;
    }

    /** @param  array{kind?: string, mine_area_id?: int|null, material?: string|null, activity?: string|null, cut?: string|null, blocks?: list<string>|null, notes?: string|null}  $attrs */
    public function editFace(LineupFace $face, array $attrs, User $user): LineupFace
    {
        $shift = $face->shift;
        $this->guardWritable($shift);
        if ($face->isClosed()) {
            $this->refuse('LU-05', $face->label().' is closed. Add a new face instead.');
        }
        $this->validateFace($attrs + ['kind' => $face->kind]);

        $kind = $attrs['kind'] ?? $face->kind;
        if ($kind === 'AREA' && $face->kind === 'LOADING' && $face->allocations()->whereNull('to_at')->where('role', 'HAUL')->exists()) {
            $this->refuse('LU-03', 'Trucks are still on '.$face->label().'. Take them off before turning it into a work area.');
        }

        $face->fill([
            'kind' => $kind, 'mine_area_id' => $attrs['mine_area_id'] ?? null,
            'material' => $kind === 'LOADING' ? ($attrs['material'] ?? null) : null,
            'activity' => $kind === 'AREA' ? ($attrs['activity'] ?? null) : null,
            'cut' => isset($attrs['cut']) && $attrs['cut'] !== '' ? strtoupper(trim($attrs['cut'])) : null,
            'blocks' => array_values(array_unique(array_map('strtoupper', array_filter(array_map('trim', $attrs['blocks'] ?? []))))) ?: null,
            'notes' => isset($attrs['notes']) && trim($attrs['notes']) !== '' ? trim($attrs['notes']) : null,
        ])->save();

        return $face;
    }

    public function closeFace(LineupFace $face, string $reason, User $user, ?string $hhmm = null): void
    {
        $shift = $face->shift;
        $this->guardWritable($shift);
        if (trim($reason) === '') {
            $this->refuse('LU-05', 'Say why the face is closing (material finished, loading unit moved, breakdown …).');
        }
        $at = $this->changeAt($shift, $hhmm);

        foreach ($face->allocations()->whereNull('to_at')->get() as $allocation) {
            $this->endAllocation($allocation, $at, 'Face closed: '.$reason, $user, $this->isPlanning($shift));
        }

        $face->fill(['status' => 'CLOSED', 'closed_at' => $at, 'close_reason' => trim($reason), 'closed_by' => $user->id])->save();
    }

    // ---- placements ------------------------------------------------------------------------------------------

    /** The role a machine takes on a face, from its class (LU-03 when it cannot take one). */
    public function roleFor(Equipment $unit, LineupFace $face): string
    {
        $class = $unit->role_class ?? RoleClass::SUPPORT;

        if ($face->kind === 'AREA') {
            if ($class === RoleClass::HAUL) {
                $this->refuse('LU-03', $face->label().' is a work area, not a loading face; trucks are allocated to the machine that loads them.');
            }

            return 'SUPPORT';
        }

        return match ($class) {
            RoleClass::LOADER => 'LOADER',
            RoleClass::HAUL => 'HAUL',
            default => 'SUPPORT',
        };
    }

    public function place(Shift $shift, LineupFace $face, Equipment $unit, User $user, ?string $hhmm = null, bool $move = false): LineupAllocation
    {
        $this->guardWritable($shift);
        if ($face->shift_id !== $shift->id || $unit->control_room_id !== $shift->control_room_id) {
            $this->refuse('LU-03', 'Unknown face or machine.');
        }
        if ($face->isClosed()) {
            $this->refuse('LU-05', $face->label().' was closed'.($face->closed_at ? ' at '.$face->closed_at->format('H:i') : '').'. Allocate to an active face.');
        }
        $at = $this->changeAt($shift, $hhmm);
        $role = $this->roleFor($unit, $face);

        if (($down = $this->downAt($unit, $at)) !== null) {
            $this->refuse('LU-02', $unit->name.' is down: '.$down['cause'].($down['since'] ? ' since '.$down['since']->format('H:i') : '').'. It cannot be put on a face until Dot.Mines reports it running again or the stoppage is reclassified as not counting against availability.');
        }

        $open = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->get();
        $here = $open->first(fn (LineupAllocation $a) => $a->lineup_face_id === $face->id);
        if ($here !== null) {
            return $here;
        }

        $elsewhere = $open->filter(fn (LineupAllocation $a) => $a->face !== null && ! $a->face->isClosed());
        $shared = $role === 'LOADER' && $elsewhere->isNotEmpty() && $elsewhere->every(fn (LineupAllocation $a) => $a->role === 'LOADER' && $a->face->sameWorkplaceAs($face));

        if ($elsewhere->isNotEmpty() && ! $move && ! $shared) {
            $this->refuse('LU-01', $unit->name.' is already on '.$elsewhere->map(fn (LineupAllocation $a) => $a->face->label())->implode(' and ').'. Choose "Move here" to move it at '.($this->isPlanning($shift) ? 'shift start' : $at->format('H:i')).'.');
        }

        $planning = $this->isPlanning($shift);

        if (! $shared) {
            foreach ($elsewhere as $allocation) {
                $this->endAllocation($allocation, $at, 'Moved to '.$face->label(), $user, $planning);
            }
        }

        $standby = LineupStandby::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
        if ($standby !== null) {
            $planning ? $standby->delete() : $standby->fill(['to_at' => $at, 'ended_by' => $user->id])->save();
        }

        $this->lineup($shift)->save();

        return LineupAllocation::create([
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'lineup_face_id' => $face->id, 'equipment_id' => $unit->id,
            'role' => $role, 'from_at' => $at, 'created_by' => $user->id,
        ]);
    }

    public function release(LineupAllocation $allocation, ?string $reason, User $user, ?string $hhmm = null): void
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($allocation->shift_id);
        $this->guardWritable($shift);
        $planning = $this->isPlanning($shift);
        if (! $planning && trim((string) $reason) === '') {
            $this->refuse('LU-05', 'Say why '.$allocation->equipment?->name.' is taken off the face.');
        }
        $at = $this->changeAt($shift, $hhmm);
        if (! $planning && $at->lessThan($allocation->from_at)) {
            $this->refuse('LU-05', 'Time before the machine arrived ('.$allocation->from_at->format('H:i').').');
        }

        $this->endAllocation($allocation, $at, $planning ? 'Removed while planning' : trim((string) $reason), $user, $planning);
    }

    private function endAllocation(LineupAllocation $allocation, Carbon $at, string $reason, User $user, bool $planning): void
    {
        if ($planning || $at->lessThanOrEqualTo($allocation->from_at)) {
            $allocation->delete();

            return;
        }

        $allocation->fill(['to_at' => $at, 'ended_reason' => $reason, 'ended_by' => $user->id])->save();
    }

    // ---- standby ---------------------------------------------------------------------------------------------

    public function setStandby(Shift $shift, Equipment $unit, DowntimeReason $reason, User $user, ?string $hhmm = null, ?string $note = null): LineupStandby
    {
        $this->guardWritable($shift);
        if ($reason->control_room_id !== $shift->control_room_id || ! in_array($reason->category, LineupStandby::CATEGORIES, true)) {
            $this->refuse('LU-05', 'Pick a standby, no-operator or off-site reason. Breakdowns and services are derived from Dot.Mines and classified on the Downtime page.');
        }
        $at = $this->changeAt($shift, $hhmm);

        $existing = LineupStandby::withoutGlobalScopes()->with('reason')->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
        if ($existing !== null) {
            $this->refuse('LU-05', $unit->name.' already has "'.$existing->reason?->name.'" from '.$existing->from_at->format('H:i').'. End it first, or place the machine.');
        }

        $planning = $this->isPlanning($shift);
        $open = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->get();
        if (! $planning && $open->contains(fn (LineupAllocation $a) => $at->lessThan($a->from_at))) {
            $this->refuse('LU-05', 'Time before the machine arrived on its face ('.$open->min('from_at')->format('H:i').').');
        }
        foreach ($open as $allocation) {
            $this->endAllocation($allocation, $at, 'Set to '.$reason->name, $user, $planning);
        }
        $this->lineup($shift)->save();

        return LineupStandby::create([
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'equipment_id' => $unit->id, 'downtime_reason_id' => $reason->id,
            'from_at' => $at, 'note' => $note !== null && trim($note) !== '' ? trim($note) : null, 'created_by' => $user->id,
        ]);
    }

    public function endStandby(LineupStandby $standby, User $user, ?string $hhmm = null): void
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($standby->shift_id);
        $this->guardWritable($shift);
        $at = $this->changeAt($shift, $hhmm);
        $this->isPlanning($shift) || $at->lessThanOrEqualTo($standby->from_at) ? $standby->delete() : $standby->fill(['to_at' => $at, 'ended_by' => $user->id])->save();
    }

    // ---- operators -------------------------------------------------------------------------------------------

    public function assignOperator(Shift $shift, Equipment $unit, Operator $operator, User $user, bool $move = false): ShiftOperatorAssignment
    {
        $this->guardWritable($shift);
        if ($operator->control_room_id !== $shift->control_room_id || ! $operator->is_active) {
            $this->refuse('LU-04', 'Pick the operator from the list. If the person is new, the administrator adds them under Administration → Operators.');
        }
        $at = $this->changeAt($shift);

        $current = ShiftOperatorAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
        if ($current !== null && $current->operator_id === $operator->id) {
            return $current;
        }

        $elsewhere = ShiftOperatorAssignment::withoutGlobalScopes()->with('equipment')->where('shift_id', $shift->id)->where('operator_id', $operator->id)->whereNull('to_at')->where('equipment_id', '!=', $unit->id)->first();
        if ($elsewhere !== null && ! $move) {
            $this->refuse('LU-04', $operator->name().' is already the operator of '.$elsewhere->equipment?->name.' on this shift. One person cannot operate two machines at once. Choose "Move operator" if they moved to '.$unit->name.'.');
        }

        $planning = $this->isPlanning($shift);
        foreach (array_filter([$current, $elsewhere]) as $row) {
            $planning ? $row->delete() : $row->fill(['to_at' => $at, 'ended_by' => $user->id])->save();
        }
        $this->lineup($shift)->save();

        return ShiftOperatorAssignment::create([
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'equipment_id' => $unit->id, 'operator_id' => $operator->id,
            'from_at' => $at, 'assigned_by' => $user->id,
        ]);
    }

    public function clearOperator(Shift $shift, Equipment $unit, User $user): void
    {
        $this->guardWritable($shift);
        $current = ShiftOperatorAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
        if ($current === null) {
            return;
        }
        $this->isPlanning($shift) ? $current->delete() : $current->fill(['to_at' => $this->changeAt($shift), 'ended_by' => $user->id])->save();
    }

    // ---- copy / fill -----------------------------------------------------------------------------------------

    public function previousShift(Shift $shift): ?Shift
    {
        return Shift::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->where('id', '!=', $shift->id)
            ->where('starts_at', '<', $shift->starts_at)->where('status', '!=', 'PLANNED')->orderByDesc('starts_at')->first();
    }

    /**
     * Faces as they stood at the end of the previous shift, with the machines and operators on them then.
     *
     * @return array{previous: Shift|null, faces: list<array{face: LineupFace, loaders: list<Equipment>, trucks: list<Equipment>, support: list<Equipment>}>, operators: array<int, Operator>}
     */
    public function proposal(Shift $shift): array
    {
        $previous = $this->previousShift($shift);
        if ($previous === null) {
            return ['previous' => null, 'faces' => [], 'operators' => []];
        }

        $end = $previous->ends_at->copy()->subMinute();
        $atEnd = fn ($q) => $q->where(fn ($w) => $w->whereNull('to_at')->orWhere('to_at', '>=', $end));
        $allocations = LineupAllocation::withoutGlobalScopes()->with('equipment')->where('shift_id', $previous->id)->tap($atEnd)->get()->groupBy('lineup_face_id');
        $faces = [];
        foreach (LineupFace::withoutGlobalScopes()->where('shift_id', $previous->id)->where('status', 'ACTIVE')->with('mineArea')->orderBy('seq')->get() as $face) {
            $rows = $allocations->get($face->id, collect());
            $pick = fn (string $role) => $rows->where('role', $role)->map(fn (LineupAllocation $a) => $a->equipment)->filter()->values()->all();
            $faces[] = ['face' => $face, 'loaders' => $pick('LOADER'), 'trucks' => $pick('HAUL'), 'support' => $pick('SUPPORT')];
        }

        $operators = ShiftOperatorAssignment::withoutGlobalScopes()->with('operator')->where('shift_id', $previous->id)->tap($atEnd)->get()
            ->filter(fn (ShiftOperatorAssignment $a) => $a->operator !== null && $a->operator->is_active)->mapWithKeys(fn (ShiftOperatorAssignment $a) => [$a->equipment_id => $a->operator])->all();

        return ['previous' => $previous, 'faces' => $faces, 'operators' => $operators];
    }

    /**
     * @return array{faces: int, placed: int, operators: int, skipped: list<string>}
     */
    public function copyFromPrevious(Shift $shift, User $user): array
    {
        $this->guardWritable($shift);
        if ($this->faces($shift)->isNotEmpty()) {
            $this->refuse('LU-01', 'The line-up already has faces; copying would duplicate them.');
        }
        $proposal = $this->proposal($shift);
        if ($proposal['previous'] === null || $proposal['faces'] === []) {
            $this->refuse('LU-05', 'Nothing to copy: the previous shift has no faces on record.');
        }

        $placed = 0;
        $skipped = [];
        foreach ($proposal['faces'] as $row) {
            $source = $row['face'];
            $face = $this->addFace($shift, ['kind' => $source->kind, 'mine_area_id' => $source->mine_area_id, 'material' => $source->material, 'activity' => $source->activity, 'cut' => $source->cut, 'blocks' => $source->blocks ?? [], 'notes' => $source->notes], $user);
            foreach (array_merge($row['loaders'], $row['trucks'], $row['support']) as $unit) {
                if ($unit->retired_at !== null) {
                    $skipped[] = $unit->name.' (retired)';

                    continue;
                }
                try {
                    $this->place($shift, $face, $unit, $user, null, true);
                    $placed++;
                } catch (ValidationException $e) {
                    $skipped[] = $unit->name.' ('.$this->firstMessage($e).')';
                }
            }
        }

        $lineup = $this->lineup($shift);
        $lineup->copied_from_shift_id = $proposal['previous']->id;
        $lineup->save();
        $this->audits->record('updated', $lineup, ['copied_from_shift_id' => ['old' => null, 'new' => $proposal['previous']->id]], 'Line-up copied from '.$proposal['previous']->label(), $shift->controlRoom, $user, shiftId: $shift->id);

        return ['faces' => count($proposal['faces']), 'placed' => $placed, 'operators' => $this->fillOperators($shift, $user), 'skipped' => $skipped];
    }

    /** Fill empty operator fields from the previous shift; machines down or on standby, and people already assigned, are left alone. */
    public function fillOperators(Shift $shift, User $user): int
    {
        $this->guardWritable($shift);
        $proposal = $this->proposal($shift);
        $filled = 0;
        $taken = ShiftOperatorAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('to_at')->pluck('operator_id')->all();

        foreach ($this->pool($shift) as $row) {
            $unit = $row['equipment'];
            $operator = $proposal['operators'][$unit->id] ?? null;
            if ($operator === null || $row['operator'] !== null || in_array($row['state'], ['DOWN', 'STANDBY'], true) || in_array($operator->id, $taken, true)) {
                continue;
            }
            $this->assignOperator($shift, $unit, $operator, $user);
            $taken[] = $operator->id;
            $filled++;
        }

        return $filled;
    }

    // ---- checks and confirmation ----------------------------------------------------------------------------

    /**
     * What still keeps the line-up from being complete (LU-06, LU-07, LU-09, LU-10). Nothing is stored.
     *
     * @return Collection<int, array{code: string, severity: string, outcome: string, title: string, message: string, fix: string, equipment_id: int|null, face_id: int|null}>
     */
    public function checks(Shift $shift): Collection
    {
        $out = collect();
        $ceiling = max(1, (int) $shift->controlRoom->settings()->get('trucks_per_loader'));
        $pool = $this->pool($shift);
        $open = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('to_at')->get()->groupBy('lineup_face_id');

        foreach ($this->faces($shift) as $face) {
            if ($face->kind !== 'LOADING') {
                continue;
            }
            $rows = $open->get($face->id, collect());
            $loaders = $rows->where('role', 'LOADER')->count();
            $trucks = $rows->where('role', 'HAUL')->count();

            if ($loaders > 0 && $trucks === 0) {
                $out->push($this->check('LU-06', 'WARNING', 'SUSPICIOUS', $face->label().' has no trucks', 'Its loading unit has no trucks allocated.', 'Click trucks in the fleet panel to put them on this face, or close the face.', null, $face->id));
            } elseif ($trucks > 0 && $loaders === 0) {
                $out->push($this->check('LU-06', 'WARNING', 'SUSPICIOUS', $face->label().' has '.$trucks.' truck(s) but no loading unit', 'Trucks are allocated to a face without a machine to load them.', 'Edit the face and pick its loading unit, or move the trucks.', null, $face->id));
            }
            if ($loaders > 0 && $trucks > $ceiling * $loaders) {
                $out->push($this->check('LU-07', 'WARNING', 'SUSPICIOUS', $trucks.' trucks on '.$face->label().' for '.$loaders.' loading unit(s)', 'The room ceiling is '.$ceiling.' trucks per loading unit.', 'Move trucks to another face, or raise the ceiling under Administration if the mine runs more.', null, $face->id));
            }
        }

        $started = ! $this->isPlanning($shift);
        foreach ($pool as $row) {
            $unit = $row['equipment'];
            if ($started && $row['state'] === 'FREE') {
                $out->push($this->check('LU-09', 'REVIEW', 'REQUIRES_EXPLANATION', $unit->name.' is available but not on the line-up', 'No face, no standby reason, and Dot.Mines does not report it stopped.', 'Allocate it, or use Standby / No operator / Off site in the "Not on the line-up" list.', $unit->id, null));
            }
            if ($row['state'] === 'PLACED' && $row['operator'] === null) {
                $out->push($this->check('LU-10', 'REVIEW', 'REQUIRES_EXPLANATION', $unit->name.' is on '.$row['placement']?->face?->label().' with no operator', 'Every placed machine needs its operator for the engine-hour record.', 'Pick the operator on the face card.', $unit->id, $row['placement']?->lineup_face_id));
            }
        }

        return $out;
    }

    public function confirm(Shift $shift, User $user): ShiftLineup
    {
        $this->guardWritable($shift);
        if ($this->faces($shift)->isEmpty()) {
            $this->refuse('LU-06', 'There is nothing to confirm yet. Copy the previous shift or add the faces first.');
        }

        $lineup = $this->lineup($shift);
        $version = $lineup->version + 1;
        $lineup->fill(['status' => 'CONFIRMED', 'version' => $version, 'confirmed_by' => $user->id, 'confirmed_at' => now()])->save();
        $this->audits->record('approve', $lineup, ['version' => ['old' => $version - 1, 'new' => $version]], 'Line-up confirmed (version '.$version.')', $shift->controlRoom, $user, shiftId: $shift->id);

        return $lineup;
    }

    // ---- helpers ---------------------------------------------------------------------------------------------

    private function guardWritable(Shift $shift): void
    {
        if (! $shift->isCurrent()) {
            $this->refuse('SH-04', $shift->label().' is '.$shift->status.'. The line-up can only change after a supervisor reopens the shift.');
        }
    }

    /** @param array<string, mixed> $attrs */
    private function validateFace(array $attrs): void
    {
        $kind = $attrs['kind'] ?? 'LOADING';
        if (! in_array($kind, LineupFace::KINDS, true)) {
            $this->refuse('LU-05', 'A face is a loading face or a work area.');
        }
        if ($kind === 'AREA' && trim((string) ($attrs['activity'] ?? '')) === '') {
            $this->refuse('LU-05', 'Say what work the area is for (dozing, drilling, roads …).');
        }
    }

    /** @return array{code: string, severity: string, outcome: string, title: string, message: string, fix: string, equipment_id: int|null, face_id: int|null} */
    private function check(string $code, string $severity, string $outcome, string $title, string $message, string $fix, ?int $equipmentId, ?int $faceId): array
    {
        return compact('code', 'severity', 'outcome', 'title', 'message', 'fix') + ['equipment_id' => $equipmentId, 'face_id' => $faceId];
    }

    private function refuse(string $code, string $message): never
    {
        throw ValidationException::withMessages(['lineup' => $code.' '.$message]);
    }

    private function firstMessage(ValidationException $e): string
    {
        $messages = $e->errors();
        $first = reset($messages);

        return is_array($first) ? (string) reset($first) : (string) $first;
    }
}
