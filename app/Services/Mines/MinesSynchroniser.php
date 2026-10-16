<?php

namespace App\Services\Mines;

use App\Models\Alert;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\Geofence;
use App\Models\IntegrationSyncRun;
use App\Models\MineArea;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\ProductionRecord;
use App\Services\Audits;
use App\Services\Memory\MemoryRecorder;
use App\Services\Mines\Exceptions\CredentialRejected;
use App\Services\Mines\Exceptions\MinesException;
use App\Services\Mines\Exceptions\RateLimited;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * One synchronisation of a control room's Dot.Mines connection
 * (docs/dot-central/04-dot-mines-integration.md §5). Every run is recorded;
 * the cursor only advances when the fleet delta was fully applied; failures
 * are typed and sanitised; nothing is reported as a success before it is.
 */
final class MinesSynchroniser
{
    public const SCOPES = ['fleet', 'production', 'reference', 'operators', 'operator_assignments'];

    /** @var array<string, string> Dot.Mines alert priority → Dot.Central severity */
    private const SEVERITY = ['critical' => 'critical', 'high' => 'warning', 'medium' => 'warning', 'low' => 'info'];

    /** @var array<int, string> mine area id → name, learned from the reference scope during a run */
    private array $areaNames = [];

    public function __construct(
        private readonly MinesClientFactory $clients,
        private readonly Audits $audits,
    ) {}

    public function run(MinesConnection $connection, string $trigger = 'schedule'): IntegrationSyncRun
    {
        $run = $connection->syncRuns()->create([
            'trigger' => $trigger,
            'status' => 'running',
            'started_at' => now(),
            'cursor_before' => (int) ($connection->sync_cursor ?? 0),
        ]);

        if (! $connection->isUsable()) {
            return $this->finish($run, 'skipped');
        }

        $client = $this->clients->for($connection);

        try {
            if ($connection->status === 'unverified') {
                $who = $this->withRateLimitRetry(fn () => $client->whoAmI());
                $connection->forceFill(['status' => 'verified', 'mines_team_id' => $who['current_team_id'] ?? $connection->mines_team_id])->save();
            }

            $seen = $this->pullFleet($client, $connection, $run);
            $this->refreshMachines($client, $connection, $run, $seen);

            // The fleet delta is applied: the cursor may advance even if alerts fail below.
            $connection->forceFill(['sync_cursor' => $run->cursor_after])->save();
        } catch (Throwable $e) {
            return $this->fail($connection, $run, $e);
        }

        try {
            $this->pullAlerts($client, $connection, $run);
            $this->pullGeofences($client, $connection);
        } catch (Throwable $e) {
            $this->recordFailure($connection, $run, $e);
            $connection->forceFill(['last_success_at' => now()])->save();

            return $this->finish($run, 'partial');
        }

        $connection->forceFill(['last_success_at' => now(), 'consecutive_failures' => 0, 'last_error' => null])->save();
        $this->resolveSystemAlert($connection, 'credential');

        return $this->finish($run, 'succeeded');
    }

    /**
     * @return list<int> machine ids seen in this delta
     */
    private function pullFleet(MinesClient $client, MinesConnection $connection, IntegrationSyncRun $run): array
    {
        $since = (int) ($connection->sync_cursor ?? 0);
        $seen = [];
        $guard = 0;

        do {
            $page = $this->withRateLimitRetry(fn () => $client->sync($since, self::SCOPES));
            foreach ($page['changes']['reference'] ?? [] as $area) {
                if (isset($area['id'])) {
                    $this->areaNames[(int) $area['id']] = (string) ($area['name'] ?? '');
                    MineArea::withoutGlobalScopes()->updateOrCreate(
                        ['control_room_id' => $connection->control_room_id, 'mines_area_id' => (int) $area['id']],
                        ['name' => (string) ($area['name'] ?? ('#'.$area['id'])), 'status' => $area['status'] ?? null, 'sync_version' => isset($area['sync_version']) ? (int) $area['sync_version'] : null, 'last_synced_at' => now()],
                    );
                }
            }
            $areas = $this->areaNames;

            foreach ($page['changes']['fleet'] ?? [] as $row) {
                if (! isset($row['id'])) {
                    continue;
                }
                $this->upsertFromSync($connection, $row, $areas);
                $seen[] = (int) $row['id'];
                $run->fetched_machines++;
            }

            $this->upsertProduction($connection, is_array($page['changes']['production'] ?? null) ? $page['changes']['production'] : []);

            foreach ($page['changes']['operators'] ?? [] as $row) {
                if (isset($row['id'])) {
                    $this->upsertOperator($connection, $row);
                }
            }
            foreach ($page['changes']['operator_assignments'] ?? [] as $row) {
                if (isset($row['id'], $row['machine_id'], $row['operator_id'])) {
                    $this->applyOperatorAssignment($connection, $row);
                }
            }

            // Tombstones are {entity_type: "machines", entity_id, sync_version}
            // (verified live 2026-10-15); the older {scope, id} form is also accepted.
            foreach ($page['deleted'] ?? [] as $tombstone) {
                if (($tombstone['entity_type'] ?? null) === 'operators' && isset($tombstone['entity_id'])) {
                    // Dot.Mines removed the person; keep the row for history, stop offering them.
                    Operator::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_operator_id', (int) $tombstone['entity_id'])->update(['is_active' => false, 'last_synced_at' => now()]);
                }

                $isMachine = ($tombstone['entity_type'] ?? null) === 'machines' || ($tombstone['scope'] ?? null) === 'fleet';
                $machineId = $tombstone['entity_id'] ?? $tombstone['id'] ?? null;

                if ($isMachine && $machineId !== null) {
                    $retired = Equipment::withoutGlobalScopes()
                        ->where('control_room_id', $connection->control_room_id)
                        ->where('mines_machine_id', (int) $machineId)
                        ->whereNull('retired_at')
                        ->update(['retired_at' => now(), 'last_synced_at' => now()]);
                    $run->retired += $retired;
                }
            }

            $since = max($since, (int) $page['version']);
            $run->cursor_after = $since;
            $run->save();
        } while ($page['has_more'] && ++$guard < 1000);

        return array_values(array_unique($seen));
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<int, string>  $areas
     */
    private function upsertFromSync(MinesConnection $connection, array $row, array $areas): void
    {
        $equipment = Equipment::withoutGlobalScopes()->firstOrNew([
            'control_room_id' => $connection->control_room_id,
            'mines_machine_id' => (int) $row['id'],
        ]);

        $previousStatus = $equipment->exists ? $equipment->mines_status : null;

        $equipment->fill([
            'name' => (string) ($row['name'] ?? $equipment->name ?? ('#'.$row['id'])),
            'machine_type' => $row['machine_type'] ?? $equipment->machine_type,
            'mines_status' => (string) ($row['status'] ?? 'unknown'),
            'allocation_state' => $row['allocation_state'] ?? null,
            'latitude' => $row['latitude'] ?? $equipment->latitude,
            'longitude' => $row['longitude'] ?? $equipment->longitude,
            'fuel_level' => $row['fuel_level'] ?? $equipment->fuel_level,
            'last_seen_at' => isset($row['last_seen_at']) ? Carbon::parse($row['last_seen_at']) : $equipment->last_seen_at,
            'sync_version' => isset($row['sync_version']) ? (int) $row['sync_version'] : $equipment->sync_version,
            'retired_at' => null,
            'last_synced_at' => now(),
        ]);

        // engine_hours in the /sync payload reads a column Dot.Mines never
        // writes (04-dot-mines-integration.md §1); real hours come from metrics.

        if ($equipment->mine_area_id !== null && isset($areas[$equipment->mine_area_id])) {
            $equipment->mine_area_name = $areas[$equipment->mine_area_id];
        }

        if (! $equipment->exists) {
            $equipment->first_synced_at = now();
        }

        $equipment->applyEquipmentType(isset($row['equipment_type']) ? (string) $row['equipment_type'] : null);
        $this->applyProviderFacts($connection, $equipment, $row);
        $equipment->recomputeLastHeard();
        $equipment->save();
        $equipment->recordTransition('mines_status', $previousStatus, $equipment->mines_status, $equipment->last_seen_at ?? now(), 'sync');
    }

    /**
     * Mirror the production scope: one Dot.Mines record per machine per
     * day/shift, upserted on its id.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsertProduction(MinesConnection $connection, array $rows): void
    {
        foreach ($rows as $row) {
            if (! isset($row['id'], $row['record_date'])) {
                continue;
            }

            $equipmentId = isset($row['machine_id'])
                ? Equipment::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_machine_id', (int) $row['machine_id'])->value('id')
                : null;

            ProductionRecord::withoutGlobalScopes()->updateOrCreate(
                ['control_room_id' => $connection->control_room_id, 'mines_record_id' => (int) $row['id']],
                [
                    'equipment_id' => $equipmentId,
                    'mines_machine_id' => $row['machine_id'] ?? null,
                    'mine_area_id' => $row['mine_area_id'] ?? null,
                    'record_date' => $row['record_date'],
                    'shift' => $row['shift'] ?? null,
                    'quantity' => isset($row['quantity_produced']) && is_numeric($row['quantity_produced']) ? (float) $row['quantity_produced'] : null,
                    'unit' => $row['unit'] ?? null,
                    'mines_status' => $row['status'] ?? null,
                    'sync_version' => isset($row['sync_version']) ? (int) $row['sync_version'] : null,
                    'last_synced_at' => now(),
                ],
            );
        }
    }

    /**
     * Full MachineResource fields for the machines this delta touched (or all
     * of them periodically), since /sync carries only the minimal payload.
     *
     * @param  list<int>  $seen
     */
    private function refreshMachines(MinesClient $client, MinesConnection $connection, IntegrationSyncRun $run, array $seen): void
    {
        $runs = $connection->syncRuns()->count();
        $full = $seen === [] && Equipment::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->doesntExist()
            || $runs % max(1, (int) config('mines.full_refresh_every', 12)) === 0;

        if ($seen === [] && ! $full) {
            return;
        }

        $page = 1;
        $perPage = (int) config('mines.page_size', 100);

        do {
            $result = $this->withRateLimitRetry(fn () => $client->machines($page, $perPage));

            foreach ($result['data'] as $resource) {
                if (! isset($resource['id'])) {
                    continue;
                }
                if (! $full && ! in_array((int) $resource['id'], $seen, true)) {
                    continue;
                }
                $this->applyResource($connection, $resource);
                $run->upserted++;
            }

            $page++;
        } while ($page <= $result['meta']['last_page']);

        $run->save();
    }

    /**
     * @param  array<string, mixed>  $resource  MachineResource fields
     */
    public function applyResource(MinesConnection $connection, array $resource): Equipment
    {
        $equipment = Equipment::withoutGlobalScopes()->firstOrNew([
            'control_room_id' => $connection->control_room_id,
            'mines_machine_id' => (int) $resource['id'],
        ]);

        $location = is_array($resource['location'] ?? null) ? $resource['location'] : [];
        $area = is_array($resource['mine_area'] ?? null) ? $resource['mine_area'] : [];
        $previousStatus = $equipment->exists ? $equipment->mines_status : null;

        $equipment->fill([
            'name' => (string) ($resource['name'] ?? $equipment->name ?? ('#'.$resource['id'])),
            'machine_type' => $resource['machine_type'] ?? $equipment->machine_type,
            'model' => $resource['model'] ?? $equipment->model,
            'manufacturer' => $resource['manufacturer'] ?? $equipment->manufacturer,
            'serial_number' => $resource['serial_number'] ?? $equipment->serial_number,
            'registration_number' => $resource['registration_number'] ?? $equipment->registration_number,
            'mines_status' => (string) ($resource['status'] ?? $equipment->mines_status ?? 'unknown'),
            'mine_area_id' => $resource['mine_area_id'] ?? $equipment->mine_area_id,
            'mine_area_name' => $area['name'] ?? $this->areaNames[(int) ($resource['mine_area_id'] ?? 0)] ?? $equipment->mine_area_name,
            'capacity' => $resource['capacity'] ?? $equipment->capacity,
            'fuel_capacity' => $resource['fuel_capacity'] ?? $equipment->fuel_capacity,
            'latitude' => $location['latitude'] ?? $equipment->latitude,
            'longitude' => $location['longitude'] ?? $equipment->longitude,
            'location_recorded_at' => isset($location['updated_at']) ? Carbon::parse($location['updated_at']) : $equipment->location_recorded_at,
            'raw' => $resource,
            'retired_at' => null,
            'last_synced_at' => now(),
        ]);

        if (! $equipment->exists) {
            $equipment->first_synced_at = now();
        }

        $equipment->applyEquipmentType(isset($resource['equipment_type']) ? (string) $resource['equipment_type'] : null);
        $this->applyProviderFacts($connection, $equipment, $resource);
        $equipment->recomputeLastHeard();
        $equipment->save();
        $equipment->recordTransition('mines_status', $previousStatus, $equipment->mines_status, $equipment->last_seen_at ?? now(), 'sync');

        return $equipment;
    }

    /**
     * What Dot.Mines now states about a machine (I-03, I-04, I-06): its own
     * last-heard clock, the provider's declared cadence, the newest hour-meter
     * reading, and the fuel unit. Each is applied only when present, so an
     * older Dot.Mines still works; the engine-hour reading lands as a metric
     * row so every derivation reads it the same way as the metrics endpoint.
     *
     * @param  array<string, mixed>  $facts  a fleet sync row or a MachineResource
     */
    private function applyProviderFacts(MinesConnection $connection, Equipment $equipment, array $facts): void
    {
        if (isset($facts['last_heard_at']) && is_string($facts['last_heard_at'])) {
            $equipment->last_heard_at = Carbon::parse($facts['last_heard_at']);
            $equipment->providerLastHeard = true;
        }

        if (isset($facts['fuel_level_unit']) && is_string($facts['fuel_level_unit'])) {
            $equipment->fuel_level_unit = $facts['fuel_level_unit'];
        }

        if (isset($facts['provider_cadence_seconds']) && is_numeric($facts['provider_cadence_seconds']) && (int) $facts['provider_cadence_seconds'] > 0 && (int) $facts['provider_cadence_seconds'] !== $connection->provider_cadence_seconds) {
            $connection->forceFill(['provider_cadence_seconds' => (int) $facts['provider_cadence_seconds']])->save();
        }

        if (isset($facts['operating_hours'], $facts['operating_hours_recorded_at']) && is_numeric($facts['operating_hours']) && is_string($facts['operating_hours_recorded_at'])) {
            if (! $equipment->exists) {
                $equipment->save();
            }
            $this->applyMetrics($equipment, [['recorded_at' => $facts['operating_hours_recorded_at'], 'operating_hours' => (float) $facts['operating_hours'], 'raw_data' => []]]);
            $equipment->refresh();
            if (isset($facts['last_heard_at']) && is_string($facts['last_heard_at'])) {
                $equipment->last_heard_at = Carbon::parse($facts['last_heard_at']);
            }
        }
    }

    /**
     * Mirror one Dot.Mines operator. A person an administrator already added
     * by employee number is linked, not duplicated.
     *
     * @param  array<string, mixed>  $row  operators sync row or OperatorResource
     */
    public function upsertOperator(MinesConnection $connection, array $row): Operator
    {
        $operator = Operator::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_operator_id', (int) $row['id'])->first()
            ?? Operator::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('employee_number', (string) ($row['employee_number'] ?? ''))->first()
            ?? new Operator(['control_room_id' => $connection->control_room_id]);

        $operator->fill([
            'mines_operator_id' => (int) $row['id'],
            'employee_number' => (string) ($row['employee_number'] ?? $operator->employee_number ?? ('M-'.$row['id'])),
            'first_name' => (string) ($row['first_name'] ?? $operator->first_name ?? ''),
            'last_name' => (string) ($row['last_name'] ?? $operator->last_name ?? ''),
            'preferred_name' => $row['preferred_name'] ?? $operator->preferred_name,
            'employment_status' => $row['employment_status'] ?? $operator->employment_status,
            'is_active' => ($row['employment_status'] ?? 'active') === 'active',
            'last_synced_at' => now(),
        ])->save();

        return $operator;
    }

    /**
     * What Dot.Mines says about who is on a machine now. An open row sets the
     * machine's current operator; a closed row clears it when it is the one
     * recorded. Used by the sync scope and the operator.* webhooks.
     *
     * @param  array<string, mixed>  $row  operator_assignments sync row or OperatorAssignmentResource
     */
    public function applyOperatorAssignment(MinesConnection $connection, array $row): bool
    {
        $equipment = Equipment::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_machine_id', (int) $row['machine_id'])->first();

        if ($equipment === null) {
            return false;
        }

        if (($row['unassigned_at'] ?? null) === null) {
            $equipment->forceFill([
                'mines_operator_id' => (int) $row['operator_id'],
                'mines_operator_assignment_id' => (int) $row['id'],
                'mines_operator_assigned_at' => isset($row['assigned_at']) ? Carbon::parse($row['assigned_at']) : now(),
                'mines_operator_shift' => $row['shift'] ?? null,
            ])->save();

            return true;
        }

        if ($equipment->mines_operator_assignment_id === (int) $row['id'] || ($equipment->mines_operator_assignment_id === null && $equipment->mines_operator_id === (int) $row['operator_id'])) {
            $equipment->forceFill(['mines_operator_id' => null, 'mines_operator_assignment_id' => null, 'mines_operator_assigned_at' => null, 'mines_operator_shift' => null])->save();
        }

        return true;
    }

    /** Mirror the team's geofences; ones no longer listed are retired, never deleted. */
    private function pullGeofences(MinesClient $client, MinesConnection $connection): void
    {
        $page = 1;
        $perPage = (int) config('mines.page_size', 100);
        $seen = [];

        do {
            $result = $this->withRateLimitRetry(fn () => $client->geofences($page, $perPage));
            foreach ($result['data'] as $resource) {
                if (! isset($resource['id'])) {
                    continue;
                }
                Geofence::withoutGlobalScopes()->updateOrCreate(
                    ['control_room_id' => $connection->control_room_id, 'mines_geofence_id' => (int) $resource['id']],
                    ['name' => (string) ($resource['name'] ?? ('#'.$resource['id'])), 'type' => $resource['type'] ?? null, 'status' => $resource['status'] ?? null, 'mine_area_id' => $resource['mine_area_id'] ?? null, 'last_synced_at' => now(), 'retired_at' => null],
                );
                $seen[] = (int) $resource['id'];
            }
            $page++;
        } while ($page <= $result['meta']['last_page']);

        Geofence::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->whereNotIn('mines_geofence_id', $seen)->whereNull('retired_at')->update(['retired_at' => now()]);
    }

    private function pullAlerts(MinesClient $client, MinesConnection $connection, IntegrationSyncRun $run): void
    {
        $page = 1;
        $perPage = (int) config('mines.page_size', 100);
        $activeIds = [];

        do {
            $result = $this->withRateLimitRetry(fn () => $client->activeAlerts($page, $perPage));

            foreach ($result['data'] as $resource) {
                if (! isset($resource['id'])) {
                    continue;
                }
                $this->applyAlert($connection, $resource);
                $activeIds[] = (string) $resource['id'];
                $run->alerts_upserted++;
            }

            $page++;
        } while ($page <= $result['meta']['last_page']);

        // Alerts that Dot.Mines no longer lists as active were resolved there.
        $cleared = Alert::withoutGlobalScopes()
            ->where('control_room_id', $connection->control_room_id)
            ->where('source', 'dot_mines')
            ->whereNull('cleared_at')
            ->whereNotIn('external_id', $activeIds)
            ->get();
        foreach ($cleared as $alert) {
            $alert->forceFill(['cleared_at' => now()])->save();
            app(MemoryRecorder::class)->alertCleared($alert, 'dot-mines');
        }

        $run->save();
    }

    /**
     * @param  array<string, mixed>  $resource  AlertResource fields
     */
    public function applyAlert(MinesConnection $connection, array $resource): Alert
    {
        $equipmentId = isset($resource['machine_id'])
            ? Equipment::withoutGlobalScopes()->where('control_room_id', $connection->control_room_id)->where('mines_machine_id', (int) $resource['machine_id'])->value('id')
            : null;

        $alert = Alert::withoutGlobalScopes()->firstOrNew([
            'control_room_id' => $connection->control_room_id,
            'source' => 'dot_mines',
            'external_id' => (string) $resource['id'],
        ]);

        $priority = is_string($resource['priority'] ?? null) ? $resource['priority'] : null;
        $resolvedAt = isset($resource['resolved_at']) ? Carbon::parse($resource['resolved_at']) : null;

        $alert->fill([
            'severity' => self::SEVERITY[$priority] ?? 'warning',
            'priority' => $priority,
            'equipment_id' => $equipmentId,
            'title' => (string) ($resource['title'] ?? ('Dot.Mines alert '.$resource['id'])),
            'description' => $resource['description'] ?? null,
            'metadata' => array_filter([
                'type' => $resource['type'] ?? null,
                'mines_status' => $resource['status'] ?? null,
                'mines_machine_id' => $resource['machine_id'] ?? null,
                'mines_metadata' => $resource['metadata'] ?? null,
            ], fn ($v) => $v !== null),
            'triggered_at' => $alert->exists ? $alert->triggered_at : (isset($resource['triggered_at']) ? Carbon::parse($resource['triggered_at']) : now()),
            // Dot.Mines publishes the provider's own time since I-07; before that, ingest time was the best available.
            'source_triggered_at' => isset($resource['source_triggered_at']) ? Carbon::parse($resource['source_triggered_at']) : (isset($resource['triggered_at']) ? Carbon::parse($resource['triggered_at']) : null),
            'cleared_at' => ($resource['status'] ?? null) === 'resolved' ? ($resolvedAt ?? now()) : null,
        ]);

        $alert->save();

        if ($alert->wasRecentlyCreated) {
            app(MemoryRecorder::class)->observeAlert($alert);
        } elseif ($alert->wasChanged('cleared_at') && $alert->cleared_at !== null) {
            app(MemoryRecorder::class)->alertCleared($alert, 'dot-mines');
        }

        return $alert;
    }

    /**
     * Engine hours live in Dot.Mines metrics (operating_hours), one call per
     * machine, so they are refreshed in small batches inside the rate budget:
     * machines never read first, then the stalest. Returns how many changed.
     */
    public function refreshEngineHours(MinesConnection $connection): int
    {
        if (! $connection->isUsable()) {
            return 0;
        }

        $client = $this->clients->for($connection);
        $batch = Equipment::withoutGlobalScopes()
            ->where('control_room_id', $connection->control_room_id)
            ->whereNull('retired_at')
            ->orderByRaw('engine_hours_recorded_at IS NOT NULL')
            ->orderBy('engine_hours_recorded_at')
            ->limit(max(1, (int) config('mines.hours_batch', 20)))
            ->get();

        $updated = 0;

        foreach ($batch as $equipment) {
            try {
                $metrics = $client->metrics($equipment->mines_machine_id, (int) config('mines.metrics_hours_back', 24), (int) config('mines.metrics_limit', 200));
            } catch (RateLimited) {
                break; // the budget is spent; the next run continues from the stalest
            } catch (CredentialRejected $e) {
                $connection->forceFill(['status' => 'credential_invalid', 'last_failure_at' => now(), 'last_error' => $e->getMessage()])->save();
                break;
            } catch (MinesException $e) {
                $connection->forceFill(['last_failure_at' => now(), 'last_error' => $e->getMessage()])->save();

                continue;
            }

            if ($this->applyMetrics($equipment, $metrics)) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Store a page of raw metric rows (newest first from Dot.Mines) and
     * bring the machine's latest figures forward. Returns true when the
     * machine's engine hours or fuel changed.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function applyMetrics(Equipment $equipment, array $rows): bool
    {
        $rows = array_values(array_filter($rows, fn (array $r) => isset($r['recorded_at'])));
        usort($rows, fn (array $a, array $b) => strcmp((string) $a['recorded_at'], (string) $b['recorded_at']));

        $lastEngine = $equipment->metrics()->whereNotNull('engine_running')->orderByDesc('recorded_at')->value('engine_running');
        $lastEngine = $lastEngine === null ? null : (bool) $lastEngine;
        $newest = null;

        foreach ($rows as $row) {
            $recordedAt = Carbon::parse($row['recorded_at']);
            $raw = is_array($row['raw_data'] ?? null) ? $row['raw_data'] : [];
            $engineRunning = array_key_exists('engine_running', $raw) && $raw['engine_running'] !== null ? (bool) $raw['engine_running'] : null;

            $metric = EquipmentMetric::firstOrCreate(
                ['equipment_id' => $equipment->id, 'recorded_at' => $recordedAt],
                [
                    'operating_hours' => isset($row['operating_hours']) && is_numeric($row['operating_hours']) ? (float) $row['operating_hours'] : null,
                    'idle_hours' => isset($row['idle_hours']) && is_numeric($row['idle_hours']) ? (float) $row['idle_hours'] : null,
                    'fuel_level' => isset($row['fuel_level']) && is_numeric($row['fuel_level']) ? (float) $row['fuel_level'] : null,
                    'speed' => isset($row['speed']) && is_numeric($row['speed']) ? (float) $row['speed'] : null,
                    'engine_running' => $engineRunning,
                    'load_count' => isset($raw['load_count']) && is_numeric($raw['load_count']) ? (int) $raw['load_count'] : null,
                    'payload_cumulative' => isset($raw['cumulative_payload']) && is_numeric($raw['cumulative_payload']) ? (float) $raw['cumulative_payload'] : null,
                    'odometer' => isset($raw['odometer']) && is_numeric($raw['odometer']) ? (float) $raw['odometer'] : null,
                    'ingested_at' => isset($row['created_at']) ? Carbon::parse($row['created_at']) : now(),
                ],
            );

            if ($metric->wasRecentlyCreated && $engineRunning !== null) {
                $equipment->recordTransition('engine_running', $lastEngine === null ? null : ($lastEngine ? 'on' : 'off'), $engineRunning ? 'on' : 'off', $recordedAt, 'metric');
                $lastEngine = $engineRunning;
            }

            $newest = $row;
        }

        if ($newest === null) {
            return false;
        }

        $recordedAt = Carbon::parse($newest['recorded_at']);
        $changed = false;

        if (isset($newest['fuel_level']) && is_numeric($newest['fuel_level'])) {
            $equipment->fuel_level = (float) $newest['fuel_level'];
            $equipment->fuel_level_unit = 'percent';
            $changed = true;
        }

        if (isset($newest['operating_hours']) && is_numeric($newest['operating_hours'])) {
            $equipment->engine_hours = (float) $newest['operating_hours'];
            $equipment->engine_hours_recorded_at = $recordedAt;
            $changed = true;
        }

        if (! $changed) {
            return false;
        }

        $equipment->recomputeLastHeard();
        $equipment->last_synced_at = now();
        $equipment->save();

        return true;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    private function withRateLimitRetry(callable $call)
    {
        try {
            return $call();
        } catch (RateLimited $e) {
            $max = (int) config('mines.max_retry_after_seconds', 60);
            if ($e->retryAfter > $max) {
                throw $e;
            }
            sleep($e->retryAfter);

            return $call();
        }
    }

    private function fail(MinesConnection $connection, IntegrationSyncRun $run, Throwable $e): IntegrationSyncRun
    {
        $this->recordFailure($connection, $run, $e);

        if ($e instanceof CredentialRejected) {
            $connection->forceFill(['status' => 'credential_invalid'])->save();
            $this->raiseSystemAlert($connection, 'credential', 'critical', 'Dot.Mines credential rejected',
                'Dot.Mines refused this control room\'s API token; synchronisation is paused until a new token is saved on the connection.');
        }

        return $this->finish($run, 'failed');
    }

    private function recordFailure(MinesConnection $connection, IntegrationSyncRun $run, Throwable $e): void
    {
        $message = $e instanceof MinesException ? $e->getMessage() : class_basename($e).': '.$this->sanitise($e->getMessage(), $connection);

        $run->error_class = $e::class;
        $run->error_message = mb_substr($message, 0, 500);
        $run->save();

        $connection->forceFill([
            'last_failure_at' => now(),
            'consecutive_failures' => $connection->consecutive_failures + 1,
            'last_error' => mb_substr($message, 0, 500),
        ])->save();
    }

    private function sanitise(string $message, MinesConnection $connection): string
    {
        return $connection->api_token ? str_replace($connection->api_token, '[token]', $message) : $message;
    }

    private function finish(IntegrationSyncRun $run, string $status): IntegrationSyncRun
    {
        $run->status = $status;
        $run->finished_at = now();
        $run->save();
        $run->refresh(); // database defaults (counters) become real values for the audit row

        if ($status !== 'skipped') {
            $this->audits->record(
                action: 'import',
                subject: $run,
                changes: ['status' => ['old' => null, 'new' => $status], 'machines' => ['old' => null, 'new' => $run->fetched_machines], 'upserted' => ['old' => null, 'new' => $run->upserted], 'retired' => ['old' => null, 'new' => $run->retired], 'alerts' => ['old' => null, 'new' => $run->alerts_upserted], 'cursor' => ['old' => $run->cursor_before, 'new' => $run->cursor_after]],
                reason: $run->error_message,
                controlRoom: $run->connection->controlRoom,
                automatic: true,
            );
        }

        return $run;
    }

    private function raiseSystemAlert(MinesConnection $connection, string $key, string $severity, string $title, string $description): void
    {
        $alert = Alert::withoutGlobalScopes()->firstOrNew([
            'control_room_id' => $connection->control_room_id,
            'source' => 'system',
            'external_id' => 'connection:'.$connection->id.':'.$key,
        ]);

        if ($alert->exists && $alert->cleared_at === null) {
            return;
        }

        $alert->fill(['severity' => $severity, 'title' => $title, 'description' => $description, 'triggered_at' => now(), 'cleared_at' => null])->save();
    }

    private function resolveSystemAlert(MinesConnection $connection, string $key): void
    {
        Alert::withoutGlobalScopes()
            ->where('control_room_id', $connection->control_room_id)
            ->where('source', 'system')
            ->where('external_id', 'connection:'.$connection->id.':'.$key)
            ->whereNull('cleared_at')
            ->update(['cleared_at' => now()]);
    }
}
