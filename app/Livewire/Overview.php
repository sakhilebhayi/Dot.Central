<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\Team;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Shifts\ShiftDerivation;
use App\Services\Shifts\ShiftService;
use App\Support\EquipmentState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The landing page: the operational picture of one control room at a
 * glance, exceptions first, everything derived (prototype "Control Room
 * Dashboard" on Dot.Mines data). Polls every 30 s.
 */
#[Layout('layouts.app')]
class Overview extends Component
{
    #[Url(as: 'room')]
    public ?int $roomId = null;

    public function render(ShiftService $shifts, ShiftDerivation $derivation, FreshnessEvaluator $freshness): View
    {
        $rooms = ControlRoom::accessibleBy(auth()->user())->orderBy('name')->get();
        $team = auth()->user()?->currentTeam;
        $room = $rooms->firstWhere('id', $this->roomId) ?? $rooms->first();

        if ($room === null) {
            return view('livewire.overview', ['rooms' => $rooms, 'room' => null]);
        }

        $shift = $shifts->current($room) ?? Shift::withoutGlobalScopes()->where('control_room_id', $room->id)->whereIn('status', ['CLOSED', 'APPROVED'])->orderByDesc('ends_at')->first();
        $summaries = $shift !== null ? $derivation->unitSummaries($shift) : collect();
        $totals = $shift !== null ? $derivation->totals($summaries) : [];
        $connection = $room->minesConnection;

        $units = $room->equipment()->whereNull('retired_at')->get()->map(function ($unit) use ($connection, $freshness) {
            $e = $connection === null ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null] : $freshness->forEquipment($unit, $connection);

            return ['equipment' => $unit, 'state' => $e['state'], 'freshness' => $e['freshness']];
        });

        $byType = $units->groupBy(fn ($u) => $u['equipment']->machine_type ?? 'unknown')->map(function ($group, $type) use ($summaries) {
            $ids = $group->pluck('equipment.id')->all();
            $summaries = $summaries->whereIn('equipment_id', $ids);

            return [
                'type' => str_replace('_', ' ', $type),
                'units' => $group->count(),
                'operating' => $group->filter(fn ($u) => $u['state'] === EquipmentState::Operating)->count(),
                'idle' => $group->filter(fn ($u) => $u['state'] === EquipmentState::Idle)->count(),
                'unknown' => $group->filter(fn ($u) => in_array($u['state'], [EquipmentState::Unknown, EquipmentState::Stale], true))->count(),
                'run_hours' => $summaries->isEmpty() ? null : round((float) $summaries->sum('run_hours'), 1),
                'loads' => $summaries->isEmpty() ? null : $summaries->sum('loads'),
            ];
        })->sortBy('type')->values();

        $exceptions = OperationalException::withoutGlobalScopes()->where('control_room_id', $room->id)->needsAttention()->with(['equipment', 'shift.shiftType', 'controlRoom'])
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 when 'REVIEW' then 2 else 3 end")->orderByDesc('raised_at')->get();
        $alerts = Alert::withoutGlobalScopes()->where('control_room_id', $room->id)->whereNull('cleared_at')->with(['equipment', 'assignee', 'controlRoom'])
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")->orderByDesc('triggered_at')->get();

        return view('livewire.overview', [
            'rooms' => $rooms,
            'room' => $room,
            'shift' => $shift,
            'isCurrent' => $shift?->isCurrent() ?? false,
            'totals' => $totals,
            'units' => $units,
            'byState' => $units->groupBy(fn ($u) => $u['state']->value)->map->count(),
            'byType' => $byType,
            'exceptions' => $exceptions,
            'exceptionCounts' => $exceptions->groupBy('severity')->map->count(),
            'alerts' => $alerts,
            'assignees' => $team instanceof Team ? $team->allUsers()->sortBy('name')->values() : collect(),
            'connection' => $connection,
            'timezone' => $room->timezone(),
        ]);
    }
}
