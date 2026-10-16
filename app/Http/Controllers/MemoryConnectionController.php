<?php

namespace App\Http\Controllers;

use App\Models\MemoryConnection;
use App\Models\Team;
use App\Services\Audits;
use App\Services\Memory\Exceptions\MemoryCredentialRejected;
use App\Services\Memory\Exceptions\MemoryException;
use App\Services\Memory\MemoryClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemoryConnectionController extends Controller
{
    public function update(Request $request, Audits $audits): RedirectResponse
    {
        $this->authorize('act-on-health');
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403);

        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = MemoryConnection::withoutGlobalScopes()->firstOrNew(['team_id' => $team->id]);
        $attributes = ['base_url' => $validated['base_url'], 'enabled' => (bool) ($validated['enabled'] ?? false)];
        if (($validated['api_token'] ?? '') !== '') {
            $attributes['api_token'] = $validated['api_token'];
            $attributes['status'] = 'unverified';
        }
        $connection->fill($attributes)->save();

        return redirect(route('health.index').'#memory')->with('status', 'Dot.Memory connection saved.');
    }

    public function test(Request $request, MemoryClientFactory $clients): RedirectResponse
    {
        $this->authorize('act-on-health');
        $team = $request->user()->currentTeam;
        $connection = $team instanceof Team ? $clients->connectionFor($team) : null;

        if ($connection === null || $connection->api_token === null) {
            return redirect(route('health.index').'#memory')->with('memory_error', 'No Dot.Memory token to test.');
        }

        try {
            $me = $clients->for($connection)->whoAmI();
            $connection->forceFill(['status' => 'verified', 'last_verified_at' => now(), 'last_error' => null])->save();

            return redirect(route('health.index').'#memory')->with('status', 'Dot.Memory accepted the credential ('.($me['name'] !== '' ? $me['name'] : 'user '.$me['id']).').');
        } catch (MemoryException $e) {
            $connection->forceFill(['status' => $e instanceof MemoryCredentialRejected ? 'credential_invalid' : 'unreachable', 'last_error' => $e->getMessage()])->save();

            return redirect(route('health.index').'#memory')->with('memory_error', $e->getMessage());
        }
    }
}
