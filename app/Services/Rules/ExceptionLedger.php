<?php

namespace App\Services\Rules;

use App\Models\ControlRoom;
use App\Models\OperationalException;
use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * Upsert findings into exceptions: a known key refreshes an OPEN/EXPLAINED
 * item, a system-resolved one reopens, a human-decided one is left alone;
 * open system items of the evaluated rules that are no longer found are
 * resolved by the system.
 */
final class ExceptionLedger
{
    /**
     * @param  Collection<int, array<string, mixed>>  $findings
     * @param  list<string>  $evaluatedCodes
     */
    public function apply(ControlRoom $room, Collection $findings, array $evaluatedCodes, ?Shift $shift): void
    {
        $seenKeys = [];

        foreach ($findings as $f) {
            $seenKeys[] = $f['key'];
            $existing = OperationalException::withoutGlobalScopes()->where('control_room_id', $room->id)->where('key', $f['key'])->first();
            $attributes = collect($f)->only(['rule_code', 'group', 'severity', 'outcome', 'title', 'message', 'fix', 'equipment_id', 'shift_id', 'detail'])->all();

            if ($existing === null) {
                $exception = OperationalException::create($attributes + ['control_room_id' => $room->id, 'key' => $f['key'], 'status' => 'OPEN', 'raised_by' => 'SYSTEM', 'raised_at' => now(), 'last_seen_at' => now()]);
                $exception->events()->create(['action' => 'raised', 'comment' => 'Validation rule '.$f['rule_code']]);

                continue;
            }

            if (in_array($existing->status, ['OPEN', 'EXPLAINED'], true)) {
                $existing->forceFill($attributes + ['last_seen_at' => now()])->save();

                continue;
            }

            if ($existing->status === 'RESOLVED' && $existing->resolved_by === null) {
                $existing->forceFill($attributes + ['status' => 'OPEN', 'resolution' => null, 'resolved_at' => null, 'last_seen_at' => now(), 'reopened_count' => $existing->reopened_count + 1])->save();
                $existing->events()->create(['action' => 'reopened', 'comment' => 'Condition re-detected; exception reopened']);
            }
            // APPROVED / REJECTED / DISMISSED / human-RESOLVED: left alone.
        }

        $stale = OperationalException::withoutGlobalScopes()
            ->where('control_room_id', $room->id)
            ->where('status', 'OPEN')
            ->where('raised_by', 'SYSTEM')
            ->whereIn('rule_code', $evaluatedCodes)
            ->whereNotIn('key', $seenKeys)
            ->when($shift !== null, fn ($q) => $q->where(fn ($s) => $s->where('shift_id', $shift->id)->orWhereNull('shift_id')), fn ($q) => $q->whereNull('shift_id'))
            ->get();

        foreach ($stale as $exception) {
            $exception->forceFill(['status' => 'RESOLVED', 'resolution' => 'Condition no longer present after data correction', 'resolved_at' => now(), 'resolved_by' => null])->save();
            $exception->events()->create(['action' => 'resolved', 'comment' => 'system']);
        }
    }
}
