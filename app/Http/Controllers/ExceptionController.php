<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Services\Rules\ExceptionService;
use App\Services\Rules\RuleEngine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Exceptions, Approvals and Data Quality, plus the human actions on an
 * exception. Lists are scoped to the rooms the user can see.
 */
class ExceptionController extends Controller
{
    public function __construct(private readonly ExceptionService $exceptions) {}

    public function index(Request $request): View
    {
        $roomIds = ControlRoom::accessibleBy($request->user())->pluck('id');
        $filters = $request->validate([
            'shift' => ['nullable', 'string'], 'sev' => ['nullable', 'string'], 'status' => ['nullable', 'string'], 'group' => ['nullable', 'string'], 'room' => ['nullable', 'integer'],
        ]);
        $status = $filters['status'] ?? 'OPEN';

        $query = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->with(['equipment', 'shift.shiftType', 'controlRoom'])
            ->when($filters['room'] ?? null, fn ($q, $r) => $q->where('control_room_id', $r))
            ->when(($filters['shift'] ?? null) && $filters['shift'] !== 'ALL', fn ($q) => $q->where('shift_id', (int) $filters['shift']))
            ->when(($filters['sev'] ?? null) && $filters['sev'] !== 'ALL', fn ($q) => $q->where('severity', $filters['sev']))
            ->when(($filters['group'] ?? null) && $filters['group'] !== 'ALL', fn ($q) => $q->where('group', $filters['group']))
            ->when($status !== 'ALL', fn ($q) => $status === 'OPEN' ? $q->whereIn('status', ['OPEN', 'EXPLAINED']) : $q->where('status', $status))
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 when 'REVIEW' then 2 else 3 end")
            ->orderByDesc('raised_at');

        $all = $query->get();

        return view('exceptions.index', [
            'exceptions' => $all->take(150),
            'total' => $all->count(),
            'counts' => $all->groupBy('severity')->map->count(),
            'filters' => $filters + ['status' => $status],
            'shifts' => Shift::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->with('shiftType')->orderByDesc('starts_at')->limit(40)->get(),
            'groups' => OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->distinct()->pluck('group')->sort()->values(),
            'rooms' => ControlRoom::accessibleBy($request->user())->orderBy('name')->get(),
        ]);
    }

    public function approvals(Request $request): View
    {
        $roomIds = ControlRoom::accessibleBy($request->user())->pluck('id');

        $pending = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->awaitingDecision()->with(['equipment', 'shift.shiftType', 'controlRoom'])
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 when 'REVIEW' then 2 else 3 end")->orderBy('raised_at')->get();

        return view('approvals.index', [
            'pending' => $pending,
            'conflicts' => $pending->where('outcome', 'CONFLICT')->count(),
            'explained' => $pending->where('status', 'EXPLAINED')->count(),
            'shiftsToApprove' => Shift::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->where('status', 'CLOSED')->with(['shiftType', 'controlRoom'])->orderByDesc('ends_at')->get(),
            'decisions' => OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->whereIn('status', ['APPROVED', 'REJECTED'])->with('equipment')->orderByDesc('decided_at')->limit(25)->get(),
        ]);
    }

    public function quality(Request $request): View
    {
        $roomIds = ControlRoom::accessibleBy($request->user())->pluck('id');
        $filters = $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $from = isset($filters['from']) ? Carbon::parse($filters['from'])->startOfDay() : now()->subDays(14)->startOfDay();
        $to = isset($filters['to']) ? Carbon::parse($filters['to'])->endOfDay() : now()->endOfDay();

        $all = OperationalException::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->whereBetween('raised_at', [$from, $to])->with(['equipment', 'shift.shiftType'])->get();
        $open = $all->whereIn('status', ['OPEN', 'EXPLAINED']);
        $shifts = Shift::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->whereBetween('starts_at', [$from, $to])->with('shiftType')->orderByDesc('starts_at')->get();

        return view('quality.index', [
            'from' => $from, 'to' => $to,
            'summary' => [
                'missing' => $open->where('outcome', 'MISSING')->count(),
                'suspicious' => $open->where('outcome', 'SUSPICIOUS')->count(),
                'requires_explanation' => $open->where('outcome', 'REQUIRES_EXPLANATION')->count(),
                'conflicts' => $open->where('outcome', 'CONFLICT')->count(),
                'awaiting' => $all->where('status', 'EXPLAINED')->count(),
                'closed' => $all->whereIn('status', ['RESOLVED', 'APPROVED', 'DISMISSED'])->count(),
                'shifts_with_critical' => $open->where('severity', 'CRITICAL')->pluck('shift_id')->filter()->unique()->count(),
                'reopened' => $all->where('reopened_count', '>', 0)->count(),
            ],
            'byRule' => $open->groupBy('rule_code')->map(fn ($g) => ['code' => $g->first()->rule_code, 'severity' => $g->first()->severity, 'title' => $g->first()->group, 'n' => $g->count()])->sortByDesc('n')->values(),
            'worstUnits' => $open->filter(fn ($e) => $e->equipment !== null)->groupBy('equipment_id')->map(fn ($g) => ['name' => $g->first()->equipment->name, 'n' => $g->count()])->sortByDesc('n')->take(10)->values(),
            'shiftRows' => $shifts->map(fn (Shift $s) => ['shift' => $s, 'critical' => $open->where('shift_id', $s->id)->where('severity', 'CRITICAL')->count(), 'open' => $open->where('shift_id', $s->id)->count(), 'unknown' => count($s->summary['unknown_now'] ?? [])]),
        ]);
    }

    public function explain(Request $request, OperationalException $exception): RedirectResponse
    {
        $this->authorize('explain', $exception);
        $validated = $request->validate(['explanation' => ['required', 'string', 'max:2000']]);
        $this->exceptions->explain($exception, $request->user(), $validated['explanation']);

        return back()->with('status', 'Explanation saved.');
    }

    public function approve(Request $request, OperationalException $exception): RedirectResponse
    {
        $this->authorize('decide', $exception);
        $validated = $request->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $this->exceptions->decide($exception, $request->user(), 'APPROVED', $validated['comment'] ?? null);

        return back()->with('status', 'Approved.');
    }

    public function reject(Request $request, OperationalException $exception): RedirectResponse
    {
        $this->authorize('decide', $exception);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $this->exceptions->decide($exception, $request->user(), 'REJECTED', $validated['comment']);

        return back()->with('status', 'Rejected.');
    }

    public function dismiss(Request $request, OperationalException $exception): RedirectResponse
    {
        $this->authorize('dismiss', $exception);
        $validated = $request->validate(['comment' => ['required', 'string', 'max:2000']]);
        $this->exceptions->dismiss($exception, $request->user(), $validated['comment']);

        return back()->with('status', 'Dismissed.');
    }

    public function resolve(Request $request, OperationalException $exception): RedirectResponse
    {
        $this->authorize('resolveConflict', $exception);
        $validated = $request->validate(['choice' => ['required', 'in:A,B,NEITHER'], 'note' => ['nullable', 'string', 'max:2000']]);
        $this->exceptions->resolveConflict($exception, $request->user(), $validated['choice'], $validated['note'] ?? null);

        return back()->with('status', 'Conflict resolved.');
    }

    public function rerun(Request $request, Shift $shift, RuleEngine $engine): RedirectResponse
    {
        $this->authorize('close', $shift);
        $findings = $engine->evaluate($shift->controlRoom, $shift);
        $open = OperationalException::withoutGlobalScopes()->where('shift_id', $shift->id)->whereIn('status', ['OPEN', 'EXPLAINED'])->count();
        $blocking = OperationalException::withoutGlobalScopes()->where('shift_id', $shift->id)->where('status', 'OPEN')->where('severity', 'CRITICAL')->count();

        return back()->with('status', 'Review run: '.$findings->count().' findings, '.$open.' open, '.$blocking.' blocking.');
    }
}
