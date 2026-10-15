<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Team;
use App\Models\User;
use App\Notifications\AlertRaisedNotification;
use App\Services\AlertWorkflow;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * The alert queue and its workflow. Alerts arrive from Dot.Mines and the
 * rule engine; a supervisor may also raise one by hand.
 */
class AlertController extends Controller
{
    public function __construct(private readonly AlertWorkflow $workflow) {}

    public function index(Request $request): View
    {
        $roomIds = ControlRoom::accessibleBy($request->user())->pluck('id');
        $filters = $request->validate(['room' => ['nullable', 'integer'], 'sev' => ['nullable', 'string'], 'state' => ['nullable', 'string']]);
        $state = $filters['state'] ?? 'open';
        $team = $request->user()->currentTeam;

        $alerts = Alert::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->with(['equipment', 'assignee', 'controlRoom'])
            ->when($filters['room'] ?? null, fn ($q, $r) => $q->where('control_room_id', $r))
            ->when(($filters['sev'] ?? null) && $filters['sev'] !== 'ALL', fn ($q) => $q->where('severity', $filters['sev']))
            ->when($state === 'open', fn ($q) => $q->whereNull('cleared_at'))
            ->when($state === 'resolved', fn ($q) => $q->whereNotNull('cleared_at'))
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")
            ->orderByDesc('triggered_at')
            ->paginate(50)->withQueryString();

        return view('alerts.index', [
            'alerts' => $alerts,
            'filters' => $filters + ['state' => $state],
            'rooms' => ControlRoom::accessibleBy($request->user())->orderBy('name')->get(),
            'assignees' => $team instanceof Team ? $team->allUsers()->sortBy('name')->values() : collect(),
        ]);
    }

    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('create', [Alert::class, $controlRoom]);

        $validated = $request->validate([
            'severity' => ['required', 'string', Rule::in(Alert::SEVERITIES)],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'triggered_at' => ['required', 'date'],
        ]);

        $alert = $controlRoom->alerts()->create($validated + ['source' => 'manual']);

        $recipients = $controlRoom->team->allUsers()->reject(fn ($user) => $user->id === $request->user()->id);
        Notification::send($recipients, new AlertRaisedNotification($alert));

        return back()->with('status', 'Alert raised.');
    }

    public function acknowledge(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('acknowledge', $alert);
        $this->workflow->acknowledge($alert, $request->user(), $request->boolean('push'));

        return back()->with('status', 'Acknowledged.');
    }

    public function assign(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('assign', $alert);
        $teamUserIds = $alert->controlRoom->team->allUsers()->pluck('id')->all();
        $validated = $request->validate(['assigned_to' => ['required', 'integer', Rule::in($teamUserIds)], 'note' => ['nullable', 'string', 'max:1000']]);
        $this->workflow->assign($alert, $request->user(), User::findOrFail($validated['assigned_to']), $validated['note'] ?? null);

        return back()->with('status', 'Assigned.');
    }

    public function escalate(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('escalate', $alert);
        $validated = $request->validate(['note' => ['required', 'string', 'max:1000']]);
        $this->workflow->escalate($alert, $request->user(), $validated['note']);

        return back()->with('status', 'Escalated.');
    }

    public function resolve(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('resolve', $alert);
        $validated = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);
        $this->workflow->resolve($alert, $request->user(), $validated['note'] ?? null, $request->boolean('push'));

        return back()->with('status', 'Resolved.');
    }

    /** Legacy "clear" action kept for the room page. */
    public function update(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('resolve', $alert);
        $this->workflow->resolve($alert, $request->user(), 'Cleared from the control room page');

        return redirect()->route('control-rooms.show', $alert->controlRoom);
    }

    public function destroy(Request $request, Alert $alert): RedirectResponse
    {
        $this->authorize('delete', $alert);
        $controlRoom = $alert->controlRoom;
        $alert->delete();

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
