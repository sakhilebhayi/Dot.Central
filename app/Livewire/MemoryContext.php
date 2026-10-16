<?php

namespace App\Livewire;

use App\Models\Team;
use App\Services\Memory\MemoryContextService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * The recall strip under an alert, an exception or a machine: what
 * Dot.Memory knows about this subject. Loads after the page so a slow
 * Memory never slows the control room.
 */
#[Lazy]
class MemoryContext extends Component
{
    public string $subjectType = '';

    public string $subjectId = '';

    public ?string $signature = null;

    public function placeholder(): View
    {
        return view('livewire.memory-context-placeholder');
    }

    public function render(MemoryContextService $service): View
    {
        $team = auth()->user()?->currentTeam;
        $context = $team instanceof Team && $this->subjectType !== '' && $this->subjectId !== ''
            ? $service->for($team->id, $this->subjectType, $this->subjectId, $this->signature)
            : ['state' => 'unconfigured', 'seen' => 0, 'first_seen' => null, 'last_seen' => null, 'tried' => [], 'worked' => null, 'fetched_at' => null];

        return view('livewire.memory-context', ['context' => $context]);
    }
}
