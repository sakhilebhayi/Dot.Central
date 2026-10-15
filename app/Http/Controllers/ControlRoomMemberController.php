<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Administrators assign team members to a control room (site scope).
 */
class ControlRoomMemberController extends Controller
{
    public function store(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);

        $teamUserIds = $controlRoom->team->allUsers()->pluck('id')->all();

        $validated = $request->validate([
            'user_id' => ['required', 'integer', Rule::in($teamUserIds)],
        ]);

        $controlRoom->users()->syncWithoutDetaching([$validated['user_id']]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function destroy(Request $request, ControlRoom $controlRoom, User $user): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);

        $controlRoom->users()->detach($user->id);

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
