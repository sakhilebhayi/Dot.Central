<?php

namespace App\Http\Controllers;

use App\Models\StaleSessionProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resolves a StaleSessionProposal (opened by central:scan-stale-sessions
 * when an active operator session has logged no dispatch activity for a
 * while). Mirrors AlertController/OperatorSessionController's exact
 * authorization shape -- any team member, the same bar
 * OperatorSessionController::update() already uses to end a shift.
 */
class StaleSessionProposalController extends Controller
{
    public function end(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
    {
        $controlRoom = $staleSessionProposal->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $staleSessionProposal->operatorSession->update(['ended_at' => now()]);
        $staleSessionProposal->update([
            'status' => 'ended',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function dismiss(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
    {
        $controlRoom = $staleSessionProposal->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $staleSessionProposal->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
