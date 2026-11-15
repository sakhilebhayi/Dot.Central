<?php

namespace App\Livewire;

use App\Support\Search\CentralSearch;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The header search (R-52): the shared dot-ui pill, fed by CentralSearch. Typing runs a debounced Livewire request; Enter (or the
 * arrow) submits the same query to the full results page (route `search`). The component only ever holds the query string; the
 * rows are recomputed for the signed-in person on every render, so nothing another team owns can be carried in component state.
 */
class HeaderSearch extends Component
{
    public string $q = '';

    public function updatedQ(): void
    {
        $this->q = mb_substr($this->q, 0, CentralSearch::MAX_LENGTH);
    }

    public function render(CentralSearch $search): View
    {
        $user = auth()->user();
        $typed = CentralSearch::isSearchable($this->q);

        return view('livewire.header-search', [
            'typed' => $typed,
            'words' => $typed ? CentralSearch::words($this->q) : [],
            'groups' => $typed && $user ? $search->search($user, $this->q, 4) : [],
            'pages' => ! $typed && $user ? $search->pages($user, null, 6) : [],
        ]);
    }
}
