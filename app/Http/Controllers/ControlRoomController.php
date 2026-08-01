<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
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
        $controlRooms = $request->user()->currentTeam
            ->controlRooms()
            ->withCount(['dispatchDecisions', 'alerts', 'operatorSessions'])
            ->latest()
            ->get();

        return view('control-rooms.index', compact('controlRooms'));
    }

    public function create(): View
    {
        return view('control-rooms.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'mines_site_ref' => ['nullable', 'string', 'max:255'],
        ]);

        $controlRoom = $request->user()->currentTeam->controlRooms()->create([
            'name'           => $validated['name'],
            'slug'           => Str::slug($validated['name']).'-'.Str::random(6),
            'mines_site_ref' => $validated['mines_site_ref'] ?? null,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function show(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorizeAccess($request, $controlRoom);

        $controlRoom->load([
            'dispatchDecisions' => fn ($q) => $q->latest('decided_at')->limit(20),
            'alerts'            => fn ($q) => $q->latest('triggered_at')->limit(20),
            'operatorSessions'  => fn ($q) => $q->latest('started_at')->limit(20),
        ]);

        return view('control-rooms.show', [
            'controlRoom'    => $controlRoom,
            'workflowTypes'  => DispatchDecision::WORKFLOW_TYPES,
            'severities'     => Alert::SEVERITIES,
        ]);
    }

    public function edit(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorizeAccess($request, $controlRoom);

        return view('control-rooms.edit', compact('controlRoom'));
    }

    public function update(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorizeAccess($request, $controlRoom);

        $validated = $request->validate([
            'name'           => ['required', 'string', 'max:255'],
            'mines_site_ref' => ['nullable', 'string', 'max:255'],
            'is_active'      => ['sometimes', 'boolean'],
        ]);

        $controlRoom->update([
            'name'           => $validated['name'],
            'mines_site_ref' => $validated['mines_site_ref'] ?? null,
            'is_active'      => $request->boolean('is_active'),
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorizeAccess($request, $controlRoom);

        $controlRoom->delete();

        return redirect()->route('control-rooms.index');
    }

    /**
     * MVP-level tenancy guard: a control room may only be viewed/modified by
     * a member of the team it belongs to.
     */
    protected function authorizeAccess(Request $request, ControlRoom $controlRoom): void
    {
        abort_unless(
            $request->user()->belongsToTeam($controlRoom->team),
            403
        );
    }
}
