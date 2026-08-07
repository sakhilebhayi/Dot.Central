<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Minimal CRUD (create + delete) for dispatch decisions, nested under a
 * control room. No update endpoint — decisions are treated as an
 * append-only log in this MVP, matching their "unit of record" role.
 */
class DispatchDecisionController extends Controller
{
    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $validated = $request->validate([
            'workflow_type' => ['required', 'string', 'in:'.implode(',', DispatchDecision::WORKFLOW_TYPES)],
            'decided_at' => ['required', 'date'],
            'summary' => ['nullable', 'string', 'max:2000'],
        ]);

        $nextSequence = $controlRoom->dispatchDecisions()->max('sequence') + 1;

        $controlRoom->dispatchDecisions()->create([
            'workflow_type' => $validated['workflow_type'],
            'sequence' => $nextSequence,
            'decided_at' => $validated['decided_at'],
            'decided_by_user_id' => $request->user()->id,
            'summary' => $validated['summary'] ?? null,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, DispatchDecision $dispatchDecision): RedirectResponse
    {
        $controlRoom = $dispatchDecision->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $dispatchDecision->delete();

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
