<?php

namespace App\Services\Mines;

use App\Models\Equipment;
use App\Models\MinesConnection;
use App\Support\EquipmentState;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Pure rule: Dot.Mines status + age of our last contact + configured cadence
 * → the state the control room shows. See docs/dot-central/03-architecture.md §5.
 */
final class FreshnessEvaluator
{
    /**
     * @return array{state: EquipmentState, freshness: 'fresh'|'stale'|'missing', age_seconds: int|null}
     */
    public function evaluate(?string $minesStatus, ?CarbonInterface $lastHeardAt, int $cadenceSeconds, ?CarbonInterface $retiredAt, ?CarbonInterface $now = null): array
    {
        $now ??= Carbon::now();

        if ($retiredAt !== null) {
            return $this->result(EquipmentState::Retired, 'missing', $lastHeardAt === null ? null : $this->age($lastHeardAt, $now));
        }

        if ($lastHeardAt === null) {
            return $this->result(EquipmentState::Unknown, 'missing', null);
        }

        $age = $this->age($lastHeardAt, $now);
        $cadence = max(1, $cadenceSeconds);

        if ($age > 4 * $cadence) {
            return $this->result(EquipmentState::Unknown, 'missing', $age);
        }

        if ($age > 2 * $cadence) {
            return $this->result(EquipmentState::Stale, 'stale', $age);
        }

        $state = match ($minesStatus) {
            'active' => EquipmentState::Operating,
            'idle' => EquipmentState::Idle,
            'offline' => EquipmentState::Offline,
            'maintenance' => EquipmentState::Unavailable,
            default => EquipmentState::Unknown,
        };

        return $this->result($state, 'fresh', $age);
    }

    /**
     * @return array{state: EquipmentState, freshness: 'fresh'|'stale'|'missing', age_seconds: int|null}
     */
    public function forEquipment(Equipment $equipment, MinesConnection $connection, ?CarbonInterface $now = null): array
    {
        return $this->evaluate(
            $equipment->mines_status,
            $equipment->last_heard_at,
            $connection->provider_cadence_seconds,
            $equipment->retired_at,
            $now,
        );
    }

    private function age(CarbonInterface $then, CarbonInterface $now): int
    {
        return max(0, (int) $then->diffInSeconds($now, true));
    }

    /**
     * @param  'fresh'|'stale'|'missing'  $freshness
     * @return array{state: EquipmentState, freshness: 'fresh'|'stale'|'missing', age_seconds: int|null}
     */
    private function result(EquipmentState $state, string $freshness, ?int $age): array
    {
        return ['state' => $state, 'freshness' => $freshness, 'age_seconds' => $age];
    }
}
