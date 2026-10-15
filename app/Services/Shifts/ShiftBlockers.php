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
    /** @return Collection<int, mixed> rows shaped {code, title} */
    public function blocking(Shift $shift): Collection;
}
