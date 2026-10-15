<?php

namespace App\Services\Shifts;

use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * What stops a shift from closing. The rule engine provides the real
 * implementation (unexplained CRITICAL exceptions); the default is none.
 */
interface ShiftBlockers
{
    /** @return Collection<int, array{code: string, title: string}> */
    public function blocking(Shift $shift): Collection;
}
