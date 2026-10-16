<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\MinesConnection;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Administrators configure a control room's Dot.Mines connection. The API
 * token is write-only: it is never echoed back and a blank submission keeps
 * the stored token.
 */
class MinesConnectionController extends Controller
{
    public function edit(Request $request, ControlRoom $controlRoom): View
    {
        $this->authorize('manage', $controlRoom);

        return view('control-rooms.connection', [
            'controlRoom' => $controlRoom,
            'connection' => $controlRoom->minesConnection,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(Request $request, ControlRoom $controlRoom): RedirectResponse
    {
        $this->authorize('manage', $controlRoom);

        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'mines_team_id' => ['nullable', 'integer', 'min:1'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'webhook_secret' => ['nullable', 'string', 'max:2000'],
            'provider_cadence_seconds' => ['required', 'integer', 'min:60', 'max:86400'],
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
            'status' => ['nullable', Rule::in(['unverified', 'disabled'])],
        ]);

        $attributes = [
            'base_url' => rtrim($validated['base_url'], '/'),
            'mines_team_id' => $validated['mines_team_id'] ?? null,
            'provider_cadence_seconds' => $validated['provider_cadence_seconds'],
            'timezone' => $validated['timezone'],
        ];

        if (($validated['api_token'] ?? '') !== '') {
            $attributes['api_token'] = $validated['api_token'];
            // A new credential starts unverified until a sync confirms it.
            $attributes['status'] = 'unverified';
            $attributes['consecutive_failures'] = 0;
            $attributes['last_error'] = null;
        }

        if (($validated['webhook_secret'] ?? '') !== '') {
            $attributes['webhook_secret'] = $validated['webhook_secret'];
        }

        if (isset($validated['status'])) {
            $attributes['status'] = $validated['status'];
        }

        $connection = $controlRoom->minesConnection ?? new MinesConnection(['control_room_id' => $controlRoom->id]);
        $connection->fill($attributes)->save();

        return redirect()->route('control-rooms.show', $controlRoom)->with('status', 'Dot.Mines connection saved.');
    }
}
