<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\Team;
use App\Services\Lineup\LineupService;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Shifts\ShiftDerivation;
use App\Services\Shifts\ShiftService;
use App\Support\EquipmentState;
use App\Support\OverviewArrival;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
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

    /**
     * The queue row whose detail is open (`exception-12` / `alert-5`). Only a hint for the first render: it is
     * validated against the rows actually loaded, and the browser keeps the live selection itself so the 30 s
     * refresh never moves it.
     */
    #[Url(as: 'sel', history: false)]
    public ?string $sel = null;

    public function render(ShiftService $shifts, ShiftDerivation $derivation, FreshnessEvaluator $freshness, LineupService $lineup): View
    {
        $rooms = ControlRoom::accessibleBy(auth()->user())->orderBy('name')->get();
        $team = auth()->user()?->currentTeam;
        $room = $rooms->firstWhere('id', $this->roomId) ?? $rooms->first();

        if ($room === null) {
            return view('livewire.overview', [
                'rooms' => $rooms,
                'room' => null,
                'arrival' => OverviewArrival::for(auth()->user(), null, null, false, false, ['exceptions' => 0, 'critical' => 0, 'alerts' => 0]),
                'infodotUrl' => $this->infodotUrl(),
            ]);
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
            // An absent reading is a dash, never a zero that means "unknown": only units that HAVE a reading count.
            $runs = $summaries->whereIn('equipment_id', $ids)->filter(fn ($u) => $u['run_hours'] !== null);
            $loadsRead = $summaries->whereIn('equipment_id', $ids)->filter(fn ($u) => $u['loads'] !== null);

            return [
                'type' => str_replace('_', ' ', $type),
                'units' => $group->count(),
                'operating' => $group->filter(fn ($u) => $u['state'] === EquipmentState::Operating)->count(),
                'idle' => $group->filter(fn ($u) => $u['state'] === EquipmentState::Idle)->count(),
                'unknown' => $group->filter(fn ($u) => in_array($u['state'], [EquipmentState::Unknown, EquipmentState::Stale], true))->count(),
                'run_hours' => $runs->isEmpty() ? null : round((float) $runs->sum('run_hours'), 1),
                'loads' => $loadsRead->isEmpty() ? null : $loadsRead->sum('loads'),
            ];
        })->sortBy('type')->values();

        $exceptions = OperationalException::withoutGlobalScopes()->where('control_room_id', $room->id)->needsAttention()->with(['equipment', 'shift.shiftType', 'controlRoom'])
            ->orderByRaw("case severity when 'CRITICAL' then 0 when 'WARNING' then 1 when 'REVIEW' then 2 else 3 end")->orderByDesc('raised_at')->get();
        $alerts = Alert::withoutGlobalScopes()->where('control_room_id', $room->id)->whereNull('cleared_at')->with(['equipment', 'assignee', 'controlRoom'])
            ->orderByRaw("case severity when 'critical' then 0 when 'warning' then 1 else 2 end")->orderByDesc('triggered_at')->get();

        $shiftLineup = $shift !== null ? $lineup->lineup($shift) : null;

        $queue = $this->queue($exceptions, $alerts);
        $selected = $queue->firstWhere('id', $this->sel)['id'] ?? $queue->first()['id'] ?? null;
        $hasReadings = ($totals['with_reading'] ?? 0) > 0;

        return view('livewire.overview', [
            'arrival' => OverviewArrival::for(auth()->user(), $room, $shift, $shiftLineup?->isConfirmed() ?? false, $connection !== null, [
                'exceptions' => $exceptions->count(),
                'critical' => $exceptions->where('severity', 'CRITICAL')->count(),
                'alerts' => $alerts->count(),
            ], $room->timezone()),
            'infodotUrl' => $this->infodotUrl(),
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
            'queue' => $queue,
            'selected' => $selected,
            'hasReadings' => $hasReadings,
            'hasProduction' => $summaries->contains(fn ($u) => $u['production_quantity'] !== null),
            'stoppedNow' => $units->filter(fn ($u) => in_array($u['state'], [EquipmentState::Offline, EquipmentState::Unavailable], true))->values(),
            'lineup' => $shiftLineup,
            'lineupSummary' => $shift !== null ? $lineup->summary($shift) : null,
            'timezone' => $room->timezone(),
        ]);
    }

    /**
     * Exceptions and alerts as ONE queue, most severe first (the page shows eight exceptions and six alerts at most,
     * as it always has; the rest are one link away). Every row is built from rows already loaded: no new query.
     *
     * @param  Collection<int, OperationalException>  $exceptions
     * @param  Collection<int, Alert>  $alerts
     * @return Collection<int, array{id: string, kind: string, model: OperationalException|Alert, rank: int, at: ?CarbonInterface}>
     */
    private function queue($exceptions, $alerts)
    {
        $rows = collect();
        foreach ($exceptions->take(8) as $e) {
            $rows->push(['id' => 'exception-'.$e->id, 'kind' => 'exceptions', 'model' => $e, 'rank' => match ($e->severity) {
                'CRITICAL' => 0, 'WARNING' => 1, 'REVIEW' => 2, default => 3
            }, 'at' => $e->raised_at]);
        }
        foreach ($alerts->take(6) as $a) {
            $rows->push(['id' => 'alert-'.$a->id, 'kind' => 'alerts', 'model' => $a, 'rank' => match ($a->severity) {
                'critical' => 0, 'warning' => 1, default => 3
            }, 'at' => $a->source_triggered_at ?? $a->triggered_at]);
        }

        return $rows->sort(fn ($x, $y) => [$x['rank'], -($x['at']?->getTimestamp() ?? 0)] <=> [$y['rank'], -($y['at']?->getTimestamp() ?? 0)])->values();
    }

    /** The way back to InfoDot, from the same registry the rest of the app reads. */
    private function infodotUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/');
    }
}
