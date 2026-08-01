<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\ControlRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Minimal CRUD for alerts, nested under a control room.
 */
class AlertController extends Controller
{
    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $validated = $request->validate([
            'severity'    => ['required', 'string', 'in:'.implode(',', Alert::SEVERITIES)],
            'title'       => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'triggered_at' => ['required', 'date'],
        ]);

        $controlRoom->alerts()->create($validated);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function update(Request $request, Alert $alert): RedirectResponse
    {
        $controlRoom = $alert->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        // MVP-only action: mark an alert cleared.
        $alert->update(['cleared_at' => now()]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, Alert $alert): RedirectResponse
    {
        $controlRoom = $alert->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $alert->delete();

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
