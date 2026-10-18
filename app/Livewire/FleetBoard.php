<?php

namespace App\Livewire;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\OperationalException;
use App\Services\Lineup\LineupService;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Shifts\ShiftDerivation;
use App\Services\Shifts\ShiftService;
use App\Support\EquipmentState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Where every machine is, what it is doing, whether it is available for
 * operating and what issues it carries: the Dot.Mines mirror plus the
 * current shift's line-up, open alerts, open exceptions and stoppages.
 */
#[Layout('layouts.app')]
class FleetBoard extends Component
{
    public ControlRoom $controlRoom;

    public string $filter = 'all';

    public function mount(ControlRoom $controlRoom): void
    {
        $this->authorize('view', $controlRoom);
        $this->controlRoom = $controlRoom;
    }

    public function render(FreshnessEvaluator $freshness, ShiftService $shifts, LineupService $lineup, ShiftDerivation $derivation): View
    {
        $connection = $this->controlRoom->minesConnection;
        $shift = $shifts->current($this->controlRoom);
        $pool = $shift !== null ? $lineup->pool($shift)->keyBy(fn (array $r) => $r['equipment']->id) : collect();
        $openStoppages = $shift !== null ? $derivation->stoppages($shift)->where('open', true)->keyBy(fn (array $s) => $s['equipment']->id) : collect();
        $alerts = Alert::withoutGlobalScopes()->where('control_room_id', $this->controlRoom->id)->whereNull('cleared_at')->whereNotNull('equipment_id')->get()->groupBy('equipment_id');
        $exceptions = OperationalException::withoutGlobalScopes()->where('control_room_id', $this->controlRoom->id)->needsAttention()->whereNotNull('equipment_id')->get()->groupBy('equipment_id');

        $rows = Equipment::withoutGlobalScopes()
            ->where('control_room_id', $this->controlRoom->id)
            ->whereNull('retired_at')
            ->orderBy('name')
            ->get()
            ->map(function (Equipment $unit) use ($connection, $freshness, $pool, $openStoppages, $alerts, $exceptions, $lineup, $shift): array {
                $evaluation = $connection === null
                    ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null]
                    : $freshness->forEquipment($unit, $connection);
                $p = $pool->get($unit->id);
                $down = $p !== null ? $p['down'] : ($shift === null ? $lineup->downAt($unit, now()) : null);
                $lineupState = $p['state'] ?? ($down !== null ? 'DOWN' : null);
                $stoppage = $openStoppages->get($unit->id);

                $issues = [];
                if ($down !== null) {
                    $issues[] = 'down: '.$down['cause'];
                }
                if (in_array($evaluation['state'], [EquipmentState::Stale, EquipmentState::Unknown], true)) {
                    $issues[] = $evaluation['state'] === EquipmentState::Stale ? 'telemetry stale' : 'no telemetry';
                }
                if ($stoppage !== null && $stoppage['classification'] === null) {
                    $issues[] = 'stoppage not classified';
                }
                foreach ($alerts->get($unit->id, collect()) as $alert) {
                    $issues[] = 'alert: '.$alert->title;
                }
                foreach ($exceptions->get($unit->id, collect()) as $exception) {
                    $issues[] = $exception->rule_code.' '.$exception->title;
                }
                if ($p !== null && $p['state'] === 'PLACED' && $p['operator'] === null) {
                    $issues[] = 'no operator';
                }

                return [
                    'equipment' => $unit,
                    'state' => $evaluation['state'],
                    'freshness' => $evaluation['freshness'],
                    'geofence' => $unit->raw['last_geofence'] ?? null,
                    'role_class' => $unit->role_class,
                    'lineup' => $lineupState,
                    'face' => $p !== null && $p['placement'] !== null ? $p['placement']->face?->label() : null,
                    'since' => $p !== null && $p['placement'] !== null ? $p['placement']->from_at : null,
                    'operator' => $p['operator'] ?? null,
                    'standby' => $p !== null && $p['standby'] !== null ? $p['standby']->reason?->name : null,
                    'available' => $down === null,
                    'issues' => $issues,
                ];
            });

        $rows = match ($this->filter) {
            'attention' => $rows->filter(fn (array $r) => $r['issues'] !== [] || in_array($r['state'], [EquipmentState::Offline, EquipmentState::Unavailable, EquipmentState::Stale, EquipmentState::Unknown], true)),
            'available' => $rows->filter(fn (array $r) => $r['available']),
            'free' => $rows->filter(fn (array $r) => $r['lineup'] === 'FREE'),
            default => $rows,
        };

        $all = Equipment::withoutGlobalScopes()->where('control_room_id', $this->controlRoom->id)->whereNull('retired_at')->count();
        $groups = $rows->groupBy(fn (array $r) => $r['equipment']->mine_area_name ?? 'No area')->sortKeys();
        $counts = $rows->groupBy(fn (array $r) => $r['state']->value)->map->count();

        return view('livewire.fleet-board', [
            'groups' => $groups,
            'counts' => $counts,
            'total' => $all,
            'shift' => $shift,
            'availability' => [
                'available' => $pool->isNotEmpty() ? $pool->filter(fn (array $r) => $r['state'] !== 'DOWN')->count() : null,
                'placed' => $pool->where('state', 'PLACED')->count(),
                'free' => $pool->where('state', 'FREE')->count(),
                'standby' => $pool->where('state', 'STANDBY')->count(),
                'down' => $pool->where('state', 'DOWN')->count(),
            ],
            'timezone' => $this->controlRoom->timezone(),
            'lastSync' => $connection?->last_success_at,
        ]);
    }
}
