<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Minimal CRUD for operator sessions, nested under a control room.
 * Deliberately exposes no per-operator performance fields — see
 * OperatorSession model docblock.
 */
class OperatorSessionController extends Controller
{
    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('create', [OperatorSession::class, $controlRoom]);

        $teamUserIds = $controlRoom->team->allUsers()->pluck('id')->all();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($teamUserIds)],
            'shift_label' => ['required', 'string', 'max:255'],
            'started_at' => ['required', 'date'],
        ]);

        $controlRoom->operatorSessions()->create($validated);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function update(Request $request, OperatorSession $operatorSession): RedirectResponse
    {
        $this->authorize('update', $operatorSession);
        $controlRoom = $operatorSession->controlRoom;

        // MVP-only action: end the shift.
        $operatorSession->update(['ended_at' => now()]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, OperatorSession $operatorSession): RedirectResponse
    {
        $this->authorize('delete', $operatorSession);
        $controlRoom = $operatorSession->controlRoom;

        $operatorSession->delete();

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
