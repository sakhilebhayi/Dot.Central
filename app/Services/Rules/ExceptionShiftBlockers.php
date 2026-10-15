<?php

namespace App\Services\Rules;

use App\Models\OperationalException;
use App\Models\Shift;
use App\Services\Shifts\ShiftBlockers;
use Illuminate\Support\Collection;

/** Unexplained CRITICAL exceptions block a shift from closing. */
final class ExceptionShiftBlockers implements ShiftBlockers
{
    public function __construct(private readonly RuleEngine $engine) {}

    public function blocking(Shift $shift): Collection
    {
        $this->engine->evaluate($shift->controlRoom, $shift);

        return OperationalException::withoutGlobalScopes()
            ->where('shift_id', $shift->id)
            ->where('status', 'OPEN')
            ->where('severity', 'CRITICAL')
            ->orderBy('raised_at')
            ->get()
            ->map(fn (OperationalException $e): array => ['code' => $e->rule_code, 'title' => $e->title])
            ->values();
    }
}
