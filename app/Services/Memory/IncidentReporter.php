<?php

namespace App\Services\Memory;

use App\Jobs\QueueHeartbeat;
use App\Models\ControlRoom;
use App\Models\MemoryIncident;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Dot.Central's own integration failures, reported to Dot.Memory's ops
 * incident store the way the guardian reports Dot.Mines (spec §7). A
 * condition opens one incident while it holds and resolves it when it
 * clears; webhook silence is deliberately not a condition.
 *
 * @phpstan-type Condition array{signature:string,component:string,severity:string,detail:array<string,mixed>}
 */
class IncidentReporter
{
    public const QUEUE_STALL_MINUTES = 15;

    public function __construct(private readonly MemoryRecorder $recorder, private readonly MemoryClientFactory $clients) {}

    /** @return array{opened:int,resolved:int} */
    public function evaluate(int $teamId): array
    {
        $connection = $this->clients->connectionFor($teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return ['opened' => 0, 'resolved' => 0];
        }

        $active = $this->conditions($teamId);
        $activeKeys = array_map(fn (array $c) => $c['signature'].'|'.$c['component'], $active);
        $open = MemoryIncident::where('team_id', $teamId)->where('status', 'open')->get();
        $opened = 0;
        $resolved = 0;

        foreach ($active as $condition) {
            $exists = $open->first(fn (MemoryIncident $i) => $i->signature === $condition['signature'] && $i->component === $condition['component']);
            if ($exists !== null) {
                continue;
            }

            $uid = 'dot-central:'.$teamId.':'.$condition['signature'].':'.$condition['component'].':'.now()->format('Y-m-d');
            MemoryIncident::create(['team_id' => $teamId, 'incident_uid' => $uid, 'signature' => $condition['signature'], 'component' => $condition['component'], 'severity' => $condition['severity'], 'status' => 'open', 'detected_at' => now()]);
            $this->recorder->queue($teamId, 'incident', [
                'event_id' => 'incident:'.$uid.':open',
                'incident_uid' => $uid,
                'environment' => app()->environment(),
                'detection_source' => 'central-health',
                'signature' => 'dot-central:'.$condition['signature'],
                'component' => $condition['component'],
                'severity' => $condition['severity'],
                'status' => 'open',
                'detected_at' => now()->toIso8601String(),
                'record' => $condition['detail'],
            ]);
            $opened++;
        }

        foreach ($open as $incident) {
            if (in_array($incident->signature.'|'.$incident->component, $activeKeys, true)) {
                continue;
            }

            $incident->forceFill(['status' => 'resolved', 'resolved_at' => now()])->save();
            $this->recorder->queue($teamId, 'incident_update', [
                'event_id' => 'incident:'.$incident->incident_uid.':resolved',
                'incident_uid' => $incident->incident_uid,
                'status' => 'resolved',
                'resolved_at' => now()->toIso8601String(),
            ]);
            $resolved++;
        }

        return ['opened' => $opened, 'resolved' => $resolved];
    }

    /** @return list<Condition> */
    public function conditions(int $teamId): array
    {
        $conditions = [];
        $roomIds = ControlRoom::withoutGlobalScopes()->where('team_id', $teamId)->pluck('id');
        $connections = MinesConnection::withoutGlobalScopes()->whereIn('control_room_id', $roomIds)->with(['syncRuns' => fn ($q) => $q->latest('started_at')->limit(3)])->get();

        foreach ($connections as $connection) {
            $component = 'mines_connection:'.$connection->id;

            if ($connection->status === 'credential_invalid') {
                $conditions[] = ['signature' => 'mines_connection:credential_invalid', 'component' => $component, 'severity' => 'sev2', 'detail' => ['control_room_id' => $connection->control_room_id, 'last_error' => $connection->last_error]];
            }

            $runs = $connection->syncRuns;
            if ($runs->count() >= 3 && $runs->every(fn ($run) => $run->status === 'failed')) {
                $conditions[] = ['signature' => 'mines_sync:failing', 'component' => $component, 'severity' => 'sev2', 'detail' => ['control_room_id' => $connection->control_room_id, 'last_error' => $runs->first()?->error_message]];
            }
        }

        $scheduler = $this->heartbeat('dot.scheduler_heartbeat');
        $queue = $this->heartbeat(QueueHeartbeat::KEY);
        if ($scheduler !== null && $scheduler->gt(now()->subMinutes(3)) && ($queue === null || $queue->lt(now()->subMinutes(self::QUEUE_STALL_MINUTES)))) {
            $conditions[] = ['signature' => 'queue:stalled', 'component' => 'queue', 'severity' => 'sev1', 'detail' => ['queue_last_ran' => $queue?->toIso8601String(), 'scheduler_last_ran' => $scheduler->toIso8601String()]];
        }

        $stuck = MemoryOutboxRow::where('team_id', $teamId)->whereNull('sent_at')->where('attempts', '>=', MemoryOutboxRow::MAX_ATTEMPTS)->count();
        if ($stuck > 0) {
            $conditions[] = ['signature' => 'memory_outbox:stuck', 'component' => 'memory_outbox', 'severity' => 'sev3', 'detail' => ['stuck_rows' => $stuck]];
        }

        return $conditions;
    }

    private function heartbeat(string $key): ?Carbon
    {
        $value = Cache::get($key);

        return is_string($value) ? Carbon::parse($value) : null;
    }
}
