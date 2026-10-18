<?php

namespace App\Services\Lineup;

use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use App\Models\User;
use App\Services\Audits;
use App\Services\Memory\MemoryRecorder;
use App\Services\Mines\Exceptions\MinesException;
use App\Services\Mines\Exceptions\MinesRefused;
use App\Services\Mines\MinesClientFactory;
use App\Support\RoleClass;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The shift line-up (13-shift-lineup-design.md): faces, placements,
 * standby reasons, operator per machine, copy from the previous shift,
 * checks and confirmation. DOWN is derived from Dot.Mines and the
 * operator's classifications; it is never typed here.
 *
 * Refusals carry the prototype's codes as LU-xx in a ValidationException.
 *
 * @phpstan-type PoolRow array{equipment: Equipment, role_class: string, state: string, placements: Collection<int, LineupAllocation>, placement: LineupAllocation|null, standby: LineupStandby|null, down: array{cause: string, since: Carbon|null}|null, assignment: ShiftOperatorAssignment|null, operator: Operator|null, mines_operator: Operator|null}
 */
final class LineupService
{
    public const STOPPED = ['offline', 'maintenance'];

    /** The width of lineup_corrections.reason. */
    public const REASON_LENGTH = 255;

    public function __construct(private readonly Audits $audits, private readonly LocationCatalogue $locations, private readonly MinesClientFactory $clients) {}

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
        $typed = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        if ($at->format('H:i') !== $typed) {
            $this->refuse('LU-05', $typed.' did not happen on '.$at->format('j M').' in '.$timezone.': the clocks went forward. Enter the time on the clock after the change.');
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
        /** @var array<int, list<LineupAllocation>> $allocations */
        $allocations = [];
        foreach (LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('to_at')->get() as $allocation) {
            if ($faces->has($allocation->lineup_face_id)) {
                $allocation->setRelation('face', $faces[$allocation->lineup_face_id]);
                $allocations[$allocation->equipment_id][] = $allocation;
            }
        }
        $standbys = LineupStandby::withoutGlobalScopes()->with('reason')->where('shift_id', $shift->id)->whereNull('to_at')->get()->keyBy('equipment_id');
        $assignments = ShiftOperatorAssignment::withoutGlobalScopes()->with('operator')->where('shift_id', $shift->id)->whereNull('to_at')->get()->keyBy('equipment_id');
        $minesOperators = Operator::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->whereIn('mines_operator_id', $units->pluck('mines_operator_id')->filter()->all())->get()->keyBy('mines_operator_id');

        $rows = [];
        foreach ($units as $unit) {
            $rows[] = $this->poolRow($unit, $at, $allocations[$unit->id] ?? [], $standbys->get($unit->id), $assignments->get($unit->id), $unit->mines_operator_id !== null ? $minesOperators->get($unit->mines_operator_id) : null);
        }

        return collect($rows);
    }

    /**
     * @param  list<LineupAllocation>  $placements
     * @return PoolRow
     */
    private function poolRow(Equipment $unit, Carbon $at, array $placements, mixed $standby, mixed $assignment, mixed $minesOperator = null): array
    {
        $placements = collect($placements);
        $down = $this->downAt($unit, $at);
        $standby = $standby instanceof LineupStandby ? $standby : null;
        $assignment = $assignment instanceof ShiftOperatorAssignment ? $assignment : null;

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
            'mines_operator' => $minesOperator instanceof Operator ? $minesOperator : null,
        ];
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
     * @param  array{location?: string|null, kind?: string, material?: string|null, activity?: string|null, cut?: string|null, blocks?: list<string>|null, notes?: string|null, loader_ids?: list<int>}  $attrs
     */
    public function addFace(Shift $shift, array $attrs, User $user, ?string $hhmm = null): LineupFace
    {
        $this->guardWritable($shift);
        $at = $this->changeAt($shift, $hhmm);
        $this->validateFace($attrs);
        $location = $this->locations->resolve($shift->controlRoom, $attrs['location'] ?? null);

        $seq = (int) LineupFace::withoutGlobalScopes()->where('shift_id', $shift->id)->max('seq') + 1;
        $face = LineupFace::create($location + [
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'seq' => $seq,
            'kind' => $attrs['kind'] ?? 'LOADING',
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

    /** @param  array{location?: string|null, kind?: string, material?: string|null, activity?: string|null, cut?: string|null, blocks?: list<string>|null, notes?: string|null}  $attrs */
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

        $location = isset($attrs['location']) ? $this->locations->resolve($shift->controlRoom, $attrs['location']) : [];
        $face->fill($location + [
            'kind' => $kind,
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

        return DB::transaction(function () use ($shift, $face, $unit, $user, $move, $at, $role): LineupAllocation {
            // Read again under the machine's lock: another operator may have placed or moved it since the screen showed it.
            $this->lockMachine($shift, $unit->id);

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
            if (! $planning) {
                $this->refuseOverlap($shift, $unit, $face, $role, $at);
                $early = $shared ? null : $elsewhere->first(fn (LineupAllocation $a) => $at->lessThan($a->from_at));
                if ($early !== null) {
                    $this->refuse('LU-05', 'Time before the machine arrived: '.$unit->name.' only arrived on '.$early->face?->label().' at '.$this->hm($shift, $early->from_at).'. Record the move at that time or later, or first correct when it arrived (select its time on that face).');
                }
            }

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

        });
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

    // ---- corrections (I-29) ----------------------------------------------------------------------------------

    /**
     * Correct when a machine arrived on (ARRIVAL) or left (DEPARTURE) a face:
     * a move clicked late, or a release recorded at the wrong time. A move is
     * one moment, so the stint it came from (or went to) moves with it. Only
     * Central's own line-up rows change; Dot.Mines is never called. Returns
     * null when the time is unchanged. $expected is the ISO time the screen
     * showed (LU-15 when someone changed it meanwhile).
     */
    public function correctTime(LineupAllocation $allocation, string $edge, string $hhmm, string $reason, User $user, ?string $expected = null): ?LineupCorrection
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($allocation->shift_id);
        $this->guardWritable($shift);
        if (! in_array($edge, LineupCorrection::EDGES, true)) {
            $this->refuse('LU-05', 'Say whether the arrival or the departure is corrected.');
        }
        if ($this->isPlanning($shift)) {
            $this->refuse('LU-05', 'Nothing to correct while planning: before the shift starts every machine is on its face from shift start.');
        }
        if (trim($reason) === '') {
            $this->refuse('LU-05', 'Say why the time is corrected, for example "the move was clicked late".');
        }
        if (mb_strlen(trim($reason)) > self::REASON_LENGTH) {
            $this->refuse('LU-05', 'The reason is '.mb_strlen(trim($reason)).' characters; keep it to '.self::REASON_LENGTH.' or fewer.');
        }
        if (trim($hhmm) === '') {
            $this->refuse('LU-05', 'Enter the time it really happened (HH:MM).');
        }
        $at = $this->changeAt($shift, $hhmm);

        return DB::transaction(function () use ($shift, $allocation, $edge, $reason, $user, $expected, $at): ?LineupCorrection {
            // Everything below is read again under the machine's lock: another supervisor may have changed this stint, the one
            // it moves with or a neighbour since the screen showed them (SQLite: the write takes its single lock, so every later
            // read sees the latest commit; Postgres: the same write row-locks the machine's stints until this commits).
            $this->lockMachine($shift, $allocation->equipment_id);
            $allocation = LineupAllocation::withoutGlobalScopes()->find($allocation->id);
            if ($allocation === null) {
                $this->refuse('LU-15', 'Someone removed this entry meanwhile. Check the line-up and correct again if it is still wrong.');
            }
            $arrival = $edge === LineupCorrection::ARRIVAL;
            $field = $arrival ? 'from_at' : 'to_at';
            $partnerField = $arrival ? 'to_at' : 'from_at';
            $face = LineupFace::withoutGlobalScopes()->findOrFail($allocation->lineup_face_id);
            $unit = Equipment::withoutGlobalScopes()->findOrFail($allocation->equipment_id);
            $who = $unit->name;
            $old = $arrival ? $allocation->from_at : $allocation->to_at;

            if ($expected !== null && ($old === null || ! $old->equalTo(Carbon::parse($expected)))) {
                $this->refuse('LU-15', 'Someone changed this time meanwhile: '.$who.' is now recorded '.($arrival ? 'on ' : 'off ').$face->label().' at '.$this->hm($shift, $old).'. Check it and correct again if it is still wrong.');
            }
            if ($old === null) {
                $this->refuse('LU-05', $who.' is still on '.$face->label().'. Take it off with "Remove" at the time it left.');
            }
            if ($at->equalTo($old)) {
                return null;
            }

            $from = $arrival ? $at : $allocation->from_at;
            $to = $arrival ? $allocation->to_at : $at;
            if ($to !== null && $to->lessThanOrEqualTo($from)) {
                $this->refuse('LU-05', 'Times out of order: '.$who.($arrival
                    ? ' left '.$face->label().' at '.$this->hm($shift, $to).'. Pick a time before that.'
                    : ' arrived on '.$face->label().' at '.$this->hm($shift, $from).'. Pick a time after that.'));
            }
            $this->refuseOutsideLife($shift, $face, $from, $to);

            $partner = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $allocation->equipment_id)
                ->where('id', '!=', $allocation->id)->where($partnerField, $old)->first();
            $partnerFace = null;
            $pFrom = $from;
            $pTo = $to;
            if ($partner !== null) {
                $partnerFace = LineupFace::withoutGlobalScopes()->findOrFail($partner->lineup_face_id);
                $pFrom = $arrival ? $partner->from_at : $at;
                $pTo = $arrival ? $at : $partner->to_at;
                if ($pTo !== null && $pTo->lessThanOrEqualTo($pFrom)) {
                    $this->refuse('LU-05', 'Times out of order: '.$who.($arrival
                        ? ' only arrived on '.$partnerFace->label().' at '.$this->hm($shift, $pFrom).'. Correct that time first, or pick a time after it.'
                        : ' left '.$partnerFace->label().' at '.$this->hm($shift, $pTo).'. Correct that time first, or pick a time before it.'));
                }
                $this->refuseOutsideLife($shift, $partnerFace, $pFrom, $pTo);
            }

            $end = $shift->ends_at;
            $overlaps = fn (Carbon $aFrom, ?Carbon $aTo, Carbon $bFrom, ?Carbon $bTo): bool => $aFrom->lessThan($bTo ?? $end) && $bFrom->lessThan($aTo ?? $end);
            $linkedOk = fn (LineupAllocation $x, LineupFace $f, string $role): bool => $x->role === 'LOADER' && $role === 'LOADER' && $x->face !== null && $x->face->sameWorkplaceAs($f);
            $clash = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $allocation->equipment_id)
                ->whereNotIn('id', array_values(array_filter([$allocation->id, $partner?->id])))->orderBy('from_at')->get()
                ->first(fn (LineupAllocation $x): bool => ($overlaps($x->from_at, $x->to_at, $from, $to) && ! $linkedOk($x, $face, $allocation->role))
                    || ($partner !== null && $overlaps($x->from_at, $x->to_at, $pFrom, $pTo) && ! $linkedOk($x, $partnerFace, $partner->role)));
            if ($clash !== null) {
                $this->refuse('LU-05', 'Overlaps another entry: '.$who.' was on '.$clash->face?->label().' '.$this->hm($shift, $clash->from_at).'–'.$this->hm($shift, $clash->to_at).'. Correct that entry first, or pick a time that does not overlap it.');
            }

            /** @var array{0: Carbon, 1: Carbon}|null $gained the span the machine now spends on a face that it did not before */
            $gained = $arrival
                ? ($at->lessThan($old) ? [$at, $old] : ($partner !== null ? [$old, $at] : null))
                : ($at->greaterThan($old) ? [$old, $at] : ($partner !== null ? [$at, $old] : null));
            if ($gained !== null && ($down = $this->downDuring($unit, $gained[0], $gained[1])) !== null) {
                $this->refuse('LU-02', $who.' was down then: '.$down['cause'].($down['since'] !== null ? ' since '.$this->hm($shift, $down['since']) : '').'. If the stoppage is wrong, correct it where it is recorded: Dot.Mines for its status, the Downtime page for a classification.');
            }

            if (LineupAllocation::withoutGlobalScopes()->whereKey($allocation->id)->update([$field => $at]) !== 1
                || ($partner !== null && LineupAllocation::withoutGlobalScopes()->whereKey($partner->id)->update([$partnerField => $at]) !== 1)) {
                $this->refuse('LU-15', 'Someone changed this entry meanwhile. Check it and correct again if it is still wrong.');
            }
            if ($gained !== null) {
                $this->trimStandby($shift, $unit, $gained[0], $gained[1], $user);
            }

            $correction = LineupCorrection::create([
                'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'lineup_allocation_id' => $allocation->id, 'partner_allocation_id' => $partner?->id,
                'equipment_id' => $allocation->equipment_id, 'edge' => $arrival ? LineupCorrection::ARRIVAL : LineupCorrection::DEPARTURE,
                'old_at' => $old, 'new_at' => $at, 'reason' => trim($reason), 'corrected_by' => $user->id,
            ]);
            $changes = [$field => ['old' => $old->toIso8601String(), 'new' => $at->toIso8601String()]];
            if ($partner !== null) {
                $changes['partner_'.$partnerField] = ['old' => $old->toIso8601String(), 'new' => $at->toIso8601String()];
            }
            $text = ($arrival ? 'Arrival on ' : 'Departure from ').$face->label().' corrected from '.$this->hm($shift, $old).' to '.$this->hm($shift, $at).': '.trim($reason);
            $this->audits->record('override', $correction, $changes, $text, $shift->controlRoom, $user, shiftId: $shift->id);
            $this->lineup($shift)->save();

            return $correction;
        });
    }

    /** "HT-01 on Face 2 — … at 10:00 (was 10:30); off Face 1 — … at the same time." */
    public function describeCorrection(LineupCorrection $correction): string
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($correction->shift_id);
        $allocation = LineupAllocation::withoutGlobalScopes()->with('face')->find($correction->lineup_allocation_id);
        $partner = $correction->partner_allocation_id !== null ? LineupAllocation::withoutGlobalScopes()->with('face')->find($correction->partner_allocation_id) : null;
        $name = Equipment::withoutGlobalScopes()->whereKey($correction->equipment_id)->value('name') ?? 'The machine';
        $arrival = $correction->edge === LineupCorrection::ARRIVAL;
        $text = $name.' '.($arrival ? 'on ' : 'off ').($allocation?->face?->label() ?? 'its face').' at '.$this->hm($shift, $correction->new_at).' (was '.$this->hm($shift, $correction->old_at).')';

        return $text.($partner?->face !== null ? '; '.($arrival ? 'off ' : 'on ').$partner->face->label().' at the same time.' : '.');
    }

    /**
     * DOWN at any moment of [from, to): at its start, or a Dot.Mines stopped
     * status or an availability-counting classification that begins inside
     * it. Explicit values only; missing telemetry is never "stopped".
     *
     * @return array{cause: string, since: Carbon|null}|null
     */
    public function downDuring(Equipment $unit, Carbon $from, Carbon $to): ?array
    {
        if (($down = $this->downAt($unit, $from)) !== null) {
            return $down;
        }

        $event = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '>', $from)->where('observed_at', '<', $to)
            ->whereIn('to_value', self::STOPPED)->orderBy('observed_at')->first();
        if ($event !== null) {
            return ['cause' => 'Dot.Mines: '.$event->to_value, 'since' => $event->observed_at];
        }

        $classification = DowntimeClassification::withoutGlobalScopes()->with('reason')->where('equipment_id', $unit->id)
            ->where('started_at', '>', $from)->where('started_at', '<', $to)->orderBy('started_at')->get()
            ->first(fn (DowntimeClassification $c) => $c->reason?->counts_against_availability === true);

        return $classification !== null ? ['cause' => (string) $classification->reason?->name, 'since' => $classification->started_at] : null;
    }

    private function refuseOutsideLife(Shift $shift, LineupFace $face, Carbon $from, ?Carbon $to): void
    {
        $endsAfterClose = $face->isClosed() && $face->closed_at !== null && ($to === null || $to->greaterThan($face->closed_at));
        if ($from->lessThan($face->active_from) || $endsAfterClose) {
            $this->refuse('LU-05', 'Outside the life of the face: '.$face->label().' ran '.$this->hm($shift, $face->active_from).'–'.($face->isClosed() ? $this->hm($shift, $face->closed_at) : 'now').'. Pick a time while the face was working.');
        }
    }

    /** Standby recorded while the machine was really working is ended, removed or started later (Central's own rows, audited). */
    private function trimStandby(Shift $shift, Equipment $unit, Carbon $from, Carbon $to, User $user): void
    {
        $rows = LineupStandby::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)
            ->where('from_at', '<', $to)->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>', $from))->get();

        foreach ($rows as $standby) {
            if ($standby->from_at->lessThan($from)) {
                $standby->fill(['to_at' => $from, 'ended_by' => $user->id])->save();
            } elseif ($standby->to_at !== null && $standby->to_at->lessThanOrEqualTo($to)) {
                $standby->delete();
            } else {
                $standby->fill(['from_at' => $to])->save();
            }
        }
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

        // Dot.Mines' verdict comes first: a refusal must leave the line-up untouched.
        $push = $this->pushAssignment($shift, $unit, $operator);

        $planning = $this->isPlanning($shift);
        foreach (array_filter([$current, $elsewhere]) as $row) {
            $planning ? $row->delete() : $row->fill(['to_at' => $at, 'ended_by' => $user->id])->save();
        }
        $this->lineup($shift)->save();

        $assignment = ShiftOperatorAssignment::create([
            'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'equipment_id' => $unit->id, 'operator_id' => $operator->id,
            'from_at' => $at, 'assigned_by' => $user->id, 'pushed_to_source' => $push['pushed'], 'push_note' => $push['note'],
        ]);

        if ($push['resource'] !== null) {
            $unit->forceFill(['mines_operator_id' => $operator->mines_operator_id, 'mines_operator_assignment_id' => isset($push['resource']['id']) ? (int) $push['resource']['id'] : null, 'mines_operator_assigned_at' => now(), 'mines_operator_shift' => $push['resource']['shift'] ?? null])->save();
        }

        return $assignment;
    }

    /**
     * Dot.Mines is the compliance authority for who may drive what: when the
     * operator and the machine are both known there, the assignment goes
     * through its eligibility gate before anything changes locally. A refusal
     * surfaces the blockers (LU-04); an outage lets the local assignment stand
     * with a note, and the next sync shows Dot.Mines' view.
     *
     * @return array{pushed: bool, note: string, resource: array<string, mixed>|null}
     */
    private function pushAssignment(Shift $shift, Equipment $unit, Operator $operator): array
    {
        $connection = $shift->controlRoom->minesConnection;

        if ($operator->mines_operator_id === null) {
            return ['pushed' => false, 'note' => 'not in Dot.Mines: added locally', 'resource' => null];
        }
        if ($connection === null || ! $connection->isUsable()) {
            return ['pushed' => false, 'note' => 'Dot.Mines connection not usable', 'resource' => null];
        }
        if ($unit->mines_operator_id === $operator->mines_operator_id) {
            return ['pushed' => true, 'note' => 'already current in Dot.Mines', 'resource' => null];
        }

        try {
            $resource = $this->clients->for($connection)->assignOperator($unit->mines_machine_id, $operator->mines_operator_id, strtolower((string) $shift->shiftType?->code) ?: null);

            return ['pushed' => true, 'note' => 'pushed to Dot.Mines', 'resource' => $resource];
        } catch (MinesRefused $e) {
            app(MemoryRecorder::class)->assignmentRefused($shift, $unit, $operator, $e->messages, Auth::user());
            $this->refuse('LU-04', 'Dot.Mines refused '.$operator->name().' on '.$unit->name.': '.implode(' ', $e->messages).' Fix the operator\'s credentials in Dot.Mines, or have a supervisor override it there.');
        } catch (MinesException $e) {
            return ['pushed' => false, 'note' => 'Dot.Mines not updated: '.$e->getMessage(), 'resource' => null];
        }
    }

    public function clearOperator(Shift $shift, Equipment $unit, User $user): void
    {
        $this->guardWritable($shift);
        $current = ShiftOperatorAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
        if ($current === null) {
            return;
        }
        $this->isPlanning($shift) ? $current->delete() : $current->fill(['to_at' => $this->changeAt($shift), 'ended_by' => $user->id])->save();

        $connection = $shift->controlRoom->minesConnection;
        if ($current->pushed_to_source && $connection !== null && $connection->isUsable() && $unit->mines_operator_id !== null) {
            try {
                $this->clients->for($connection)->unassignOperator($unit->mines_machine_id, 'Cleared on the control-room line-up');
                $unit->forceFill(['mines_operator_id' => null, 'mines_operator_assignment_id' => null, 'mines_operator_assigned_at' => null, 'mines_operator_shift' => null])->save();
            } catch (MinesException) {
                // best effort; the next sync shows Dot.Mines' view
            }
        }
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
            $face = $this->addFace($shift, ['location' => $this->carriedLocation($shift, $source), 'kind' => $source->kind, 'material' => $source->material, 'activity' => $source->activity, 'cut' => $source->cut, 'blocks' => $source->blocks ?? [], 'notes' => $source->notes], $user);
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

    /** Fill empty operator fields: the operator Dot.Mines has on the machine now, else the previous shift's; machines down or on standby, and people already assigned, are left alone. */
    public function fillOperators(Shift $shift, User $user): int
    {
        $this->guardWritable($shift);
        $proposal = $this->proposal($shift);
        $filled = 0;
        $taken = ShiftOperatorAssignment::withoutGlobalScopes()->where('shift_id', $shift->id)->whereNull('to_at')->pluck('operator_id')->all();

        foreach ($this->pool($shift) as $row) {
            $unit = $row['equipment'];
            $operator = ($row['mines_operator'] !== null && $row['mines_operator']->is_active ? $row['mines_operator'] : null) ?? $proposal['operators'][$unit->id] ?? null;
            if ($operator === null || $row['operator'] !== null || in_array($row['state'], ['DOWN', 'STANDBY'], true) || in_array($operator->id, $taken, true)) {
                continue;
            }
            $this->assignOperator($shift, $unit, $operator, $user);
            $taken[] = $operator->id;
            $filled++;
        }

        return $filled;
    }

    /** The previous face's location as a key in today's list; a location that no longer exists falls back to the first option. */
    private function carriedLocation(Shift $shift, LineupFace $source): ?string
    {
        $options = collect($this->locations->options($shift->controlRoom)['options']);
        $key = $source->location_source !== null ? $source->location_source.':'.($source->location_ref ?? $source->location_name) : null;

        return $options->firstWhere('key', $key)['key'] ?? $options->firstWhere('name', $source->location_name)['key'] ?? $options->first()['key'] ?? null;
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

    /**
     * A write to the machine's stints in this shift, made before anything is
     * read: it serialises concurrent changes to the same machine on both
     * databases (lockForUpdate compiles to nothing on SQLite).
     */
    private function lockMachine(Shift $shift, int $equipmentId): void
    {
        LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $equipmentId)->toBase()->update(['updated_at' => now()]);
    }

    /** HH:MM on the control room's clock ("now" for an open end). */
    private function hm(Shift $shift, ?Carbon $at): string
    {
        return $at === null ? 'now' : $at->copy()->setTimezone($shift->controlRoom->timezone())->format('H:i');
    }

    /**
     * A machine cannot start somewhere at $at while an earlier, ended stint
     * has it elsewhere after $at; a loading unit may overlap only on a face
     * at the same cut and block (a linked face).
     */
    private function refuseOverlap(Shift $shift, Equipment $unit, LineupFace $face, string $role, Carbon $at): void
    {
        $clash = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $unit->id)
            ->whereNotNull('to_at')->where('to_at', '>', $at)->orderBy('from_at')->get()
            ->first(fn (LineupAllocation $a) => ! ($role === 'LOADER' && $a->role === 'LOADER' && $a->face !== null && $a->face->sameWorkplaceAs($face)));

        if ($clash !== null) {
            $until = $this->hm($shift, $clash->to_at);
            $this->refuse('LU-05', 'Overlaps an earlier entry: '.$unit->name.' was on '.$clash->face?->label().' until '.$until.'. Record the change at '.$until.' or later, or first correct when it left that face (select its time under "Moved off this face").');
        }
    }

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
