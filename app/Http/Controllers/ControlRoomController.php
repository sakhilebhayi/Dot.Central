<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Basic CRUD for control rooms — the mining-dispatch domain's tenant root.
 * Scoped to the authenticated user's current team, mirroring how the rest
 * of this app treats Jetstream teams as the tenancy boundary.
 */
class ControlRoomController extends Controller
{
    public function index(Request $request): View
    {
        $this->authorize('viewAny', ControlRoom::class);

        $controlRooms = ControlRoom::accessibleBy($request->user())
            ->with('minesConnection')
            ->withCount(['dispatchDecisions', 'alerts', 'operatorSessions'])
            ->latest()
            ->get();

        // DispatchDecision/Alert/OperatorSession carry HasControlRoomTeamScope,
        // so these no longer need an explicit whereIn('control_room_id', ...)
        // filter — every query against them is already scoped to the current
        // team's control rooms at the model level.
        $summary = [
            'activeControlRooms' => $controlRooms->where('is_active', true)->count(),
            'totalDecisions' => DispatchDecision::count(),
            'openAlerts' => Alert::whereNull('cleared_at')->count(),
            'activeSessions' => OperatorSession::whereNull('ended_at')->count(),
        ];

        return view('control-rooms.index', compact('controlRooms', 'summary'));
    }

    public function create(): View
    {
        $this->authorize('create', ControlRoom::class);

        return view('control-rooms.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', ControlRoom::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $controlRoom = $request->user()->currentTeam->controlRooms()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
        ]);

        $controlRoom->users()->attach($request->user());

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function show(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('view', $controlRoom);

        $controlRoom->load([
            'minesConnection',
            'dispatchDecisions' => fn ($q) => $q->latest('decided_at')->limit(20),
            'alerts' => fn ($q) => $q->latest('triggered_at')->limit(20),
            'operatorSessions' => fn ($q) => $q->latest('started_at')->limit(20),
        ]);

        $pendingProposals = $controlRoom->staleSessionProposals()->where('status', 'pending')->with('operatorSession.operator')->get();

        $members = $controlRoom->users()->orderBy('name')->get();
        $assignableUsers = $request->user()->can('manage', $controlRoom)
            ? $controlRoom->team->allUsers()->whereNotIn('id', $members->pluck('id'))->sortBy('name')->values()
            : collect();

        return view('control-rooms.show', [
            'controlRoom' => $controlRoom,
            'members' => $members,
            'assignableUsers' => $assignableUsers,
            'workflowTypes' => DispatchDecision::WORKFLOW_TYPES,
            'severities' => Alert::SEVERITIES,
            'pendingProposals' => $pendingProposals,
        ]);
    }

    public function edit(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('update', $controlRoom);

        return view('control-rooms.edit', compact('controlRoom'));
    }

    public function update(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('update', $controlRoom);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $controlRoom->update([
            'name' => $validated['name'],
            'is_active' => $request->boolean('is_active'),
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('delete', $controlRoom);

        $controlRoom->delete();

        return redirect()->route('control-rooms.index');
    }
}
