<?php

namespace App\Livewire;

use App\Models\Team;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The shared "Dot Apps" platform switcher (R-51, dot-ui) in the state bar.
 *
 * Lazy on purpose: the control room is read during a shift and nothing may slow or block the overview. The page ships a
 * plain link to InfoDot's platform list in the switcher's place, and the switcher itself is drawn by a second, small
 * request. A slow or dead InfoDot therefore costs the page nothing (the switcher reads InfoDot with a 3 s ceiling, caches the
 * answer for ten minutes, and shows its degraded state on any failure).
 */
#[Lazy]
class DotApps extends Component
{
    public function placeholder(): View
    {
        return view('livewire.dot-apps-placeholder', ['platformsUrl' => rtrim((string) config('ecosystem.platforms.infodot.url'), '/').'/platforms']);
    }

    public function render(): View
    {
        $team = auth()->user()?->currentTeam;
        $company = $team instanceof Team && ! $team->personal_team ? $team : null;

        return view('livewire.dot-apps', [
            'company' => $company?->infodot_team_uuid,
            'companyName' => $company?->name,
        ]);
    }
}
