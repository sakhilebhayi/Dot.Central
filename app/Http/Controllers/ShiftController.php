<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupStandby;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Services\Lineup\LineupService;
use App\Services\Shifts\ShiftService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * Start Shift, Shift Operations, End Shift & Handover, and the shift history.
 * Operators confirm, close and acknowledge; supervisors approve and reopen.
 */
class ShiftController extends Controller
{
    public function __construct(private readonly ShiftService $shifts, private readonly LineupService $lineup) {}

    public function index(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('view', $controlRoom);

        return view('shifts.index', [
            'controlRoom' => $controlRoom,
            'shifts' => Shift::where('control_room_id', $controlRoom->id)->with(['shiftType', 'handover'])->orderByDesc('starts_at')->paginate(30),
            'current' => $this->shifts->current($controlRoom),
            'timezone' => $controlRoom->timezone(),
        ]);
    }

    public function start(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('open', [Shift::class, $controlRoom]);

        $proposal = $this->shifts->propose($controlRoom);

        return view('shifts.start', [
            'controlRoom' => $controlRoom,
            'proposal' => $proposal,
            'current' => $this->shifts->current($controlRoom),
            'previous' => $proposal['previous'],
            'shiftTypes' => $controlRoom->shiftTypes()->where('is_active', true)->get(),
            'expectedUnits' => $controlRoom->equipment()->whereNull('retired_at')->count(),
            'outstanding' => $this->outstanding($controlRoom, $proposal['previous']),
            'timezone' => $controlRoom->timezone(),
        ]);
    }

    /**
     * What the incoming shift inherits: machines still down and on standby at the previous end, exceptions still open, previous faces.
     *
     * @return array{down: list<array{name: string, cause: string|null, since: Carbon|null}>, standby: list<array{name: string, reason: string|null}>, exceptions: Collection<int, OperationalException>, faces: list<array<string, mixed>>}
     */
    private function outstanding(ControlRoom $controlRoom, ?Shift $previous): array
    {
        $down = [];
        $standby = [];
        $faces = [];
        if ($previous !== null) {
            $end = $previous->ends_at->copy()->subMinute();
            foreach ($controlRoom->equipment()->whereNull('retired_at')->orderBy('name')->get() as $unit) {
                if (($d = $this->lineup->downAt($unit, $end)) !== null) {
                    $down[] = ['name' => $unit->name, 'cause' => $d['cause'], 'since' => $d['since']];
                }
            }
            foreach (LineupStandby::withoutGlobalScopes()->with(['equipment', 'reason'])->where('shift_id', $previous->id)->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>=', $end))->get() as $s) {
                $standby[] = ['name' => (string) $s->equipment?->name, 'reason' => $s->reason?->name];
            }
            $pool = $this->lineup->pool($previous)->keyBy(fn (array $r) => $r['equipment']->id);
            foreach ($this->lineup->faces($previous) as $face) {
                $members = $face->allocations()->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>=', $end))->get();
                $names = fn (string $role) => $members->where('role', $role)->map(fn ($a) => $pool[$a->equipment_id]['equipment']->name ?? '?')->values()->all();
                $faces[] = ['label' => $face->label(), 'loaders' => $names('LOADER'), 'trucks' => $names('HAUL'), 'support' => $names('SUPPORT')];
            }
        }

        return [
            'down' => $down,
            'standby' => $standby,
            'exceptions' => OperationalException::withoutGlobalScopes()->where('control_room_id', $controlRoom->id)->needsAttention()->with('equipment')->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 when 'REVIEW' then 2 else 3 end")->orderByDesc('raised_at')->get(),
            'faces' => $faces,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function facesTable(Shift $shift): array
    {
        $pool = $this->lineup->pool($shift)->keyBy(fn (array $r) => $r['equipment']->id);
        $rows = [];
        foreach ($this->lineup->faces($shift) as $face) {
            $members = $face->allocations()->whereNull('to_at')->get();
            $names = fn (string $role) => $members->where('role', $role)->map(fn ($a) => $pool[$a->equipment_id]['equipment']->name ?? '?')->values()->all();
            $rows[] = ['face' => $face, 'loaders' => $names('LOADER'), 'trucks' => $names('HAUL'), 'support' => $names('SUPPORT')];
        }

        return $rows;
    }

    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('open', [Shift::class, $controlRoom]);

        $validated = $request->validate([
            'shift_type_id' => ['required', 'integer'],
            'shift_date' => ['required', 'date'],
        ]);

        $type = ShiftType::where('control_room_id', $controlRoom->id)->findOrFail($validated['shift_type_id']);
        $shift = $this->shifts->open($controlRoom, $type, $validated['shift_date'], $request->user());

        return redirect()->route('shifts.lineup', $shift)->with('status', 'Shift started: '.$shift->label().' — now build the line-up.');
    }

    public function show(Request $request, Shift $shift): View
    {
        $this->authorize('view', $shift);

        $review = $this->shifts->review($shift);
        $room = $shift->controlRoom;

        return view('shifts.show', [
            'shift' => $shift,
            'controlRoom' => $room,
            'units' => $review['units'],
            'totals' => $review['totals'],
            'blocking' => $review['blocking'],
            'alerts' => Alert::where('control_room_id', $room->id)->whereNull('cleared_at')->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")->latest('triggered_at')->limit(8)->get(),
            'recentStoppages' => $this->recentStoppages($shift),
            'lineupStatus' => $this->lineup->lineup($shift),
            'lineupFaces' => $this->facesTable($shift),
            'timezone' => $room->timezone(),
        ]);
    }

    public function review(Request $request, Shift $shift): View
    {
        $this->authorize('view', $shift);

        $review = $shift->isClosed() ? null : $this->shifts->review($shift);
        $units = $review['units'] ?? $shift->unitSummaries()->with('equipment')->get()->map(fn ($s) => ['equipment' => $s->equipment] + $s->toArray());

        return view('shifts.review', [
            'shift' => $shift->fresh(['handover', 'shiftType']),
            'controlRoom' => $shift->controlRoom,
            'units' => $units,
            'totals' => $review['totals'] ?? ($shift->summary ?? []),
            'blocking' => $review['blocking'] ?? collect(),
            'timezone' => $shift->controlRoom->timezone(),
        ]);
    }

    public function close(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('close', $shift);
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);

        $this->shifts->close($shift, $validated['notes'] ?? null, $request->user());

        return redirect()->route('shifts.review', $shift)->with('status', 'Shift closed and handover issued.');
    }

    public function acknowledge(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('acknowledge', $shift);
        abort_unless($shift->handover !== null, 404);

        $this->shifts->acknowledge($shift->handover, $request->user());

        return redirect()->route('shifts.review', $shift)->with('status', 'Handover acknowledged.');
    }

    public function approve(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('approve', $shift);
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);

        $this->shifts->approve($shift, $request->user(), $validated['comment'] ?? null);

        return redirect()->route('shifts.review', $shift)->with('status', 'Shift approved.');
    }

    public function reopen(Request $request, Shift $shift): RedirectResponse
    {
        $this->authorize('reopen', $shift);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:2000']]);

        $this->shifts->reopen($shift, $request->user(), $validated['reason']);

        return redirect()->route('shifts.show', $shift)->with('status', 'Shift reopened.');
    }

    /** @return Collection<int, EquipmentStatusEvent> */
    private function recentStoppages(Shift $shift)
    {
        return EquipmentStatusEvent::with('equipment')
            ->whereIn('equipment_id', $shift->controlRoom->equipment()->pluck('id'))
            ->where('field', 'mines_status')
            ->whereIn('to_value', ['offline', 'maintenance'])
            ->where('observed_at', '>=', $shift->starts_at)
            ->orderByDesc('observed_at')
            ->limit(10)
            ->get();
    }
}
