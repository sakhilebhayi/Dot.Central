<?php

namespace App\Http\Controllers;

use App\Support\Search\CentralSearch;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The full results page behind the header search: the same scoped search, a longer list per group. A plain GET, so it works with no
 * JavaScript (the header pill's form submits here) and a result page can be linked or reloaded.
 */
class SearchController extends Controller
{
    public function index(Request $request, CentralSearch $search): View
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:'.(CentralSearch::MAX_LENGTH * 2)]]);
        $q = trim((string) ($validated['q'] ?? ''));
        $typed = CentralSearch::isSearchable($q);

        return view('search.index', [
            'q' => $q,
            'typed' => $typed,
            'words' => $typed ? CentralSearch::words($q) : [],
            'groups' => $typed ? $search->search($request->user(), $q, 25) : [],
            'pages' => $typed ? [] : $search->pages($request->user()),
        ]);
    }
}
