<?php

namespace App\Services\Shifts;

use App\Models\DowntimeClassification;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
use App\Models\ProductionRecord;
use App\Models\Shift;
use App\Services\Mines\FreshnessEvaluator;
use App\Support\EquipmentState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Pure derivation of a shift from the Dot.Mines mirror: per machine, the
 * readings at the window edges, the differences, production records that
 * fall in the window, observed stoppage time and the current state.
 * Nothing here is typed by anyone.
 *
 * @phpstan-type UnitSummary mixed
 */
final class ShiftDerivation
{
    public function __construct(private readonly FreshnessEvaluator $freshness) {}

    /**
     * @return Collection<int, UnitSummary>
     */
    public function unitSummaries(Shift $shift): Collection
    {
        $room = $shift->controlRoom;
        $connection = $room->minesConnection;
        $start = $shift->starts_at;
        $end = $shift->effectiveEnd();
        $shiftCode = strtolower((string) $shift->shiftType?->code);

        $equipment = Equipment::withoutGlobalScopes()
            ->where('control_room_id', $room->id)
            ->where(fn ($q) => $q->whereNull('retired_at')->orWhere('retired_at', '>', $start))
            ->orderBy('name')
            ->get();

        return $equipment->toBase()->map(function (Equipment $unit) use ($connection, $start, $end, $shift, $shiftCode): array {
            $opening = EquipmentMetric::where('equipment_id', $unit->id)->whereNotNull('operating_hours')->where('recorded_at', '<=', $start)->orderByDesc('recorded_at')->first();
            $openingFromWindow = false;

            if ($opening === null) {
                $opening = EquipmentMetric::where('equipment_id', $unit->id)->whereNotNull('operating_hours')->whereBetween('recorded_at', [$start, $end])->orderBy('recorded_at')->first();
                $openingFromWindow = $opening !== null;
            }

            $closing = EquipmentMetric::where('equipment_id', $unit->id)->whereNotNull('operating_hours')->where('recorded_at', '<=', $end)->orderByDesc('recorded_at')->first();

            if ($closing !== null && $opening !== null && $closing->recorded_at->lessThanOrEqualTo($opening->recorded_at)) {
                $closing = null;
            }

            $run = $opening !== null && $closing !== null ? round($closing->operating_hours - $opening->operating_hours, 2) : null;
            $idle = $opening?->idle_hours !== null && $closing?->idle_hours !== null ? round($closing->idle_hours - $opening->idle_hours, 2) : null;
            $loads = $opening?->load_count !== null && $closing?->load_count !== null ? $closing->load_count - $opening->load_count : null;

            $production = ProductionRecord::withoutGlobalScopes()
                ->where('equipment_id', $unit->id)
                ->whereDate('record_date', $shift->shift_date)
                ->where(fn ($q) => $q->where('shift', $shiftCode)->orWhere('shift', 'continuous')->orWhereNull('shift'))
                ->get();

            $evaluation = $connection === null
                ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null]
                : $this->freshness->forEquipment($unit, $connection, $end);

            return [
                'equipment_id' => $unit->id,
                'equipment' => $unit,
                'opening_hours' => $opening?->operating_hours,
                'opening_at' => $opening?->recorded_at,
                'opening_from_window' => $openingFromWindow,
                'closing_hours' => $closing?->operating_hours,
                'closing_at' => $closing?->recorded_at,
                'run_hours' => $run,
                'idle_hours' => $idle,
                'loads' => $loads,
                'production_quantity' => $production->isEmpty() ? null : (float) $production->sum('quantity'),
                'production_unit' => $production->first()?->unit,
                'stoppage_minutes' => $this->stoppageMinutes($unit, $start, $end),
                'state' => $evaluation['state']->value,
                'freshness' => $evaluation['freshness'],
                'flags' => array_values(array_filter([
                    $run !== null && $run < 0 ? 'hours_decreased' : null,
                    $openingFromWindow ? 'opening_from_window' : null,
                ])),
            ];
        })->values();
    }

    /**
     * Minutes inside [start, end] during which Dot.Mines reported the machine
     * offline or in maintenance, from observed status transitions.
     */
    public function stoppageMinutes(Equipment $unit, CarbonInterface $start, CarbonInterface $end): int
    {
        $stopped = ['offline', 'maintenance'];

        $before = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '<=', $start)->orderByDesc('observed_at')->first();
        $events = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '>', $start)->where('observed_at', '<=', $end)->orderBy('observed_at')->get();

        $current = $before?->to_value;
        $cursor = $start->copy();
        $minutes = 0;

        foreach ($events as $event) {
            if (in_array($current, $stopped, true)) {
                $minutes += (int) $cursor->diffInMinutes($event->observed_at, true);
            }
            $current = $event->to_value;
            $cursor = $event->observed_at->copy();
        }

        if (in_array($current, $stopped, true)) {
            $minutes += (int) $cursor->diffInMinutes($end, true);
        }

        return $minutes;
    }

    /**
     * Observed stoppage intervals (offline / maintenance) for every machine
     * in the window, newest first, each with its classification if any.
     *
     * @return Collection<int, array{equipment: Equipment, cause: string, started_at: Carbon, ended_at: Carbon|null, minutes: int, open: bool, classification: DowntimeClassification|null}>
     */
    public function stoppages(Shift $shift): Collection
    {
        $start = $shift->starts_at;
        $end = $shift->effectiveEnd();
        $stopped = ['offline', 'maintenance'];
        $equipmentIds = Equipment::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->pluck('id');
        $classifications = DowntimeClassification::withoutGlobalScopes()->with('reason')->whereIn('equipment_id', $equipmentIds)->where('started_at', '<', $end)->get();
        $rows = collect();

        foreach (Equipment::withoutGlobalScopes()->whereIn('id', $equipmentIds)->orderBy('name')->get() as $unit) {
            $before = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '<=', $start)->orderByDesc('observed_at')->first();
            $events = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '>', $start)->where('observed_at', '<=', $end)->orderBy('observed_at')->get();
            $current = $before?->to_value;
            $cursor = $start->copy();

            $emit = function (string $cause, $from, $to, bool $open) use (&$rows, $unit, $classifications): void {
                $match = $classifications->first(fn ($c) => $c->equipment_id === $unit->id && $c->started_at->lessThanOrEqualTo($to ?? now()) && ($c->ended_at === null || $c->ended_at->greaterThanOrEqualTo($from)));
                $rows->push(['equipment' => $unit, 'cause' => $cause, 'started_at' => $from, 'ended_at' => $open ? null : $to, 'minutes' => (int) $from->diffInMinutes($to, true), 'open' => $open, 'classification' => $match]);
            };

            foreach ($events as $event) {
                if (in_array($current, $stopped, true)) {
                    $emit($current, $cursor, $event->observed_at, false);
                }
                $current = $event->to_value;
                $cursor = $event->observed_at->copy();
            }

            if (in_array($current, $stopped, true)) {
                $emit($current, $cursor, $end, ! $shift->isClosed());
            }
        }

        return $rows->sortByDesc('started_at')->values();
    }

    /**
     * Fleet-level totals for tiles and the handover.
     *
     * @param  Collection<int, UnitSummary>  $units
     * @return array<string, mixed>
     */
    public function totals(Collection $units): array
    {
        $byState = $units->groupBy('state')->map->count()->all();
        $withReading = $units->filter(fn ($u) => $u['run_hours'] !== null);

        return [
            'units' => $units->count(),
            'by_state' => $byState,
            'with_reading' => $withReading->count(),
            'run_hours' => round((float) $withReading->sum('run_hours'), 1),
            'idle_hours' => round((float) $units->filter(fn ($u) => $u['idle_hours'] !== null)->sum('idle_hours'), 1),
            'loads' => $units->filter(fn ($u) => $u['loads'] !== null)->sum('loads'),
            'production_quantity' => round((float) $units->filter(fn ($u) => $u['production_quantity'] !== null)->sum('production_quantity'), 1),
            'production_unit' => $units->firstWhere('production_unit', '!=', null)['production_unit'] ?? null,
            'stoppage_minutes' => (int) $units->sum('stoppage_minutes'),
            'stopped_now' => $units->filter(fn ($u) => in_array($u['state'], ['offline', 'unavailable'], true))->map(fn ($u) => ['name' => $u['equipment']->name, 'state' => $u['state'], 'since' => $u['equipment']->last_heard_at?->toIso8601String()])->values()->all(),
            'unknown_now' => $units->filter(fn ($u) => $u['state'] === 'unknown')->map(fn ($u) => $u['equipment']->name)->values()->all(),
        ];
    }
}
