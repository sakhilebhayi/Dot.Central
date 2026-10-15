<?php

namespace App\Livewire;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Services\Mines\FreshnessEvaluator;
use App\Support\EquipmentState;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Where every machine is and what it is doing, grouped by mine area, from
 * the Dot.Mines mirror. Replaces the prototype's manual line-up: nothing
 * is placed by hand.
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

    public function render(FreshnessEvaluator $freshness): View
    {
        $connection = $this->controlRoom->minesConnection;

        $rows = Equipment::withoutGlobalScopes()
            ->where('control_room_id', $this->controlRoom->id)
            ->whereNull('retired_at')
            ->orderBy('name')
            ->get()
            ->map(function (Equipment $unit) use ($connection, $freshness): array {
                $evaluation = $connection === null
                    ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null]
                    : $freshness->forEquipment($unit, $connection);

                return ['equipment' => $unit, 'state' => $evaluation['state'], 'freshness' => $evaluation['freshness'], 'geofence' => $unit->raw['last_geofence'] ?? null];
            });

        if ($this->filter === 'attention') {
            $rows = $rows->filter(fn (array $r) => in_array($r['state'], [EquipmentState::Offline, EquipmentState::Unavailable, EquipmentState::Stale, EquipmentState::Unknown], true));
        }

        $groups = $rows->groupBy(fn (array $r) => $r['equipment']->mine_area_name ?? 'No area')->sortKeys();
        $counts = $rows->groupBy(fn (array $r) => $r['state']->value)->map->count();

        return view('livewire.fleet-board', [
            'groups' => $groups,
            'counts' => $counts,
            'total' => $rows->count(),
            'timezone' => $this->controlRoom->timezone(),
            'lastSync' => $connection?->last_success_at,
        ]);
    }
}
