<?php

namespace App\Services\Mines;

use App\Models\Equipment;
use App\Models\IntegrationWebhookDelivery;
use App\Models\MinesConnection;
use App\Services\Mines\Exceptions\MinesException;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Applies a verified Dot.Mines delivery to the mirror. Returns applied|ignored.
 * Unknown events are ignored; a malformed payload throws, and the receiver
 * records the failure without making Dot.Mines retry.
 */
final class WebhookApplier
{
    public function __construct(private readonly MinesSynchroniser $synchroniser, private readonly MinesClientFactory $clients) {}

    public function apply(MinesConnection $connection, IntegrationWebhookDelivery $delivery): string
    {
        $payload = $delivery->payload;
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return match ($delivery->event) {
            'alert.triggered' => $this->alert($connection, $data),
            'machine.offline' => $this->offline($connection, $data),
            'geofence.entered', 'geofence.exited' => $this->geofence($connection, $data),
            'maintenance.predicted' => $this->maintenance($connection, $data),
            'operator.assigned', 'operator.unassigned' => isset($data['id'], $data['machine_id'], $data['operator_id']) && $this->synchroniser->applyOperatorAssignment($connection, $data) ? 'applied' : 'ignored',
            default => 'ignored',
        };
    }

    /** @param array<string, mixed> $data */
    private function alert(MinesConnection $connection, array $data): string
    {
        if (! isset($data['id'])) {
            throw new InvalidArgumentException('alert.triggered payload has no alert id.');
        }

        $this->mirrorMachineIfUnknown($connection, $data['machine_id'] ?? null);
        $this->synchroniser->applyAlert($connection, $data);

        return 'applied';
    }

    /**
     * A webhook can name a machine the mirror has not seen yet (issue I-22):
     * fetch it once so the alert links to a unit. A failed fetch is not an
     * error; the next sync links it.
     */
    private function mirrorMachineIfUnknown(MinesConnection $connection, mixed $machineId): void
    {
        if (! is_numeric($machineId) || ! $connection->isUsable()) {
            return;
        }

        $known = Equipment::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_machine_id', (int) $machineId)->exists();
        if ($known) {
            return;
        }

        try {
            $resource = $this->clients->for($connection)->machine((int) $machineId);
            $this->synchroniser->applyResource($connection, $resource);
        } catch (MinesException) {
            // left unlinked on purpose; see the docblock
        }
    }

    /** @param array<string, mixed> $data */
    private function offline(MinesConnection $connection, array $data): string
    {
        $machine = is_array($data['machine'] ?? null) ? $data['machine'] : null;

        if ($machine === null || ! isset($machine['id'])) {
            throw new InvalidArgumentException('machine.offline payload has no machine.');
        }

        $equipment = $this->synchroniser->applyResource($connection, $machine);
        $equipment->mines_status = 'offline';

        if (isset($data['last_seen_at'])) {
            $equipment->last_seen_at = Carbon::parse($data['last_seen_at']);
        }

        $equipment->save();

        return 'applied';
    }

    /** @param array<string, mixed> $data */
    private function geofence(MinesConnection $connection, array $data): string
    {
        $machine = is_array($data['machine'] ?? null) ? $data['machine'] : null;

        if ($machine === null || ! isset($machine['id'])) {
            return 'ignored';
        }

        $equipment = Equipment::withoutGlobalScopes()
            ->where('control_room_id', $connection->control_room_id)
            ->where('mines_machine_id', (int) $machine['id'])
            ->first();

        if ($equipment === null) {
            return 'ignored';
        }

        $raw = $equipment->raw ?? [];
        $raw['last_geofence'] = [
            'direction' => $data['direction'] ?? null,
            'geofence' => $data['geofence'] ?? null,
            'entry_time' => $data['entry_time'] ?? null,
            'exit_time' => $data['exit_time'] ?? null,
            'material_type' => $data['material_type'] ?? null,
            'tonnage_loaded' => $data['tonnage_loaded'] ?? null,
        ];
        $equipment->raw = $raw;
        $equipment->save();

        return 'applied';
    }

    /** @param array<string, mixed> $data */
    private function maintenance(MinesConnection $connection, array $data): string
    {
        $machine = is_array($data['machine'] ?? null) ? $data['machine'] : null;

        if ($machine === null || ! isset($machine['id'])) {
            throw new InvalidArgumentException('maintenance.predicted payload has no machine.');
        }

        $equipment = $this->synchroniser->applyResource($connection, $machine);

        $this->synchroniser->applyAlert($connection, [
            'id' => 'maintenance:'.$machine['id'].':'.($data['predicted_date'] ?? 'unknown'),
            'type' => 'maintenance',
            'title' => 'Maintenance predicted for '.$equipment->name,
            'description' => 'Probability '.($data['probability'] ?? '?').', predicted '.($data['predicted_date'] ?? 'unknown').'.',
            'priority' => $data['severity'] ?? 'medium',
            'status' => 'active',
            'machine_id' => $machine['id'],
            'triggered_at' => now()->toIso8601String(),
            'metadata' => $data,
        ]);

        return 'applied';
    }
}
