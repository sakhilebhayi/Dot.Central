<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Minimal CRUD for operator sessions, nested under a control room.
 * Deliberately exposes no per-operator performance fields — see
 * OperatorSession model docblock.
 */
class OperatorSessionController extends Controller
{
    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $validated = $request->validate([
            'user_id'     => ['required', 'exists:users,id'],
            'shift_label' => ['required', 'string', 'max:255'],
            'started_at'  => ['required', 'date'],
        ]);

        $controlRoom->operatorSessions()->create($validated);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function update(Request $request, OperatorSession $operatorSession): RedirectResponse
    {
        $controlRoom = $operatorSession->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        // MVP-only action: end the shift.
        $operatorSession->update(['ended_at' => now()]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, OperatorSession $operatorSession): RedirectResponse
    {
        $controlRoom = $operatorSession->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $operatorSession->delete();

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
