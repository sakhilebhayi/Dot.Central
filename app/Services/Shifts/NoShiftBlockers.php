<?php

namespace App\Services\Shifts;

use App\Models\Shift;
use Illuminate\Support\Collection;

final class NoShiftBlockers implements ShiftBlockers
{
    public function blocking(Shift $shift): Collection
    {
        return collect();
    }
}
