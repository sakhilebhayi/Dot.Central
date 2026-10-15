<?php

namespace App\Http\Controllers;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Services\Mines\FreshnessEvaluator;
use App\Support\EquipmentState;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The mirrored fleet of a control room, with the state the evaluator
 * derives and the provenance of every figure.
 */
class EquipmentController extends Controller
{
    public function index(Request $request, ControlRoom $controlRoom, FreshnessEvaluator $freshness): View
    {
        $this->authorize('view', $controlRoom);

        $connection = $controlRoom->minesConnection;
        $rows = $controlRoom->equipment()->orderBy('name')->get()->map(function (Equipment $equipment) use ($connection, $freshness): array {
            $evaluation = $connection === null
                ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null]
                : $freshness->forEquipment($equipment, $connection);

            return ['equipment' => $equipment] + $evaluation;
        });

        $byState = $rows->groupBy(fn (array $row) => $row['state']->value)->map->count();

        return view('equipment.index', [
            'controlRoom' => $controlRoom,
            'connection' => $connection,
            'rows' => $rows,
            'byState' => $byState,
            'timezone' => $connection === null ? 'UTC' : $connection->timezone,
        ]);
    }

    public function show(Request $request, ControlRoom $controlRoom, Equipment $equipment, FreshnessEvaluator $freshness): View
    {
        $this->authorize('view', $controlRoom);
        abort_unless($equipment->control_room_id === $controlRoom->id, 404);

        $connection = $controlRoom->minesConnection;
        $evaluation = $connection === null
            ? ['state' => EquipmentState::Unknown, 'freshness' => 'missing', 'age_seconds' => null]
            : $freshness->forEquipment($equipment, $connection);

        return view('equipment.show', [
            'controlRoom' => $controlRoom,
            'connection' => $connection,
            'equipment' => $equipment,
            'evaluation' => $evaluation,
            'readings' => $equipment->metrics()->latest('recorded_at')->limit(50)->get(),
            'alerts' => $equipment->alerts()->latest('triggered_at')->limit(20)->get(),
            'timezone' => $connection === null ? 'UTC' : $connection->timezone,
        ]);
    }
}
