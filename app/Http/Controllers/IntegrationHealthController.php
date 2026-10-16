<?php

namespace App\Http\Controllers;

use App\Jobs\QueueHeartbeat;
use App\Jobs\SyncMinesConnection;
use App\Models\ControlRoom;
use App\Models\IntegrationWebhookDelivery;
use App\Models\MemoryConnection;
use App\Models\MemoryIncident;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use App\Models\Team;
use App\Services\Audits;
use App\Services\Mines\Exceptions\CredentialRejected;
use App\Services\Mines\Exceptions\MinesException;
use App\Services\Mines\FreshnessEvaluator;
use App\Services\Mines\MinesClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * The reliability of the platform itself (brief §I): per connection, the
 * last runs, freshness of the mirror, webhook deliveries and failed jobs,
 * so an operator can tell an operational incident from a data failure.
 */
class IntegrationHealthController extends Controller
{
    public function index(Request $request, FreshnessEvaluator $freshness): View
    {
        $this->authorize('view-health');

        $rooms = ControlRoom::accessibleBy($request->user())
            ->with(['minesConnection.syncRuns' => fn ($q) => $q->latest('started_at')->limit(10), 'equipment'])
            ->orderBy('name')
            ->get();

        $cards = $rooms->map(function (ControlRoom $room) use ($freshness): array {
            $connection = $room->minesConnection;
            $counts = ['fresh' => 0, 'stale' => 0, 'missing' => 0];

            if ($connection !== null) {
                foreach ($room->equipment as $equipment) {
                    if ($equipment->retired_at !== null) {
                        continue;
                    }
                    $counts[$freshness->forEquipment($equipment, $connection)['freshness']]++;
                }
            }

            $deliveries = $connection === null ? null : IntegrationWebhookDelivery::where('mines_connection_id', $connection->id)->latest()->first();
            $failedDeliveries = $connection === null ? 0 : IntegrationWebhookDelivery::where('mines_connection_id', $connection->id)->where('status', 'failed')->count();

            return [
                'room' => $room,
                'connection' => $connection,
                'runs' => $connection === null ? collect() : $connection->syncRuns,
                'freshness' => $counts,
                'equipment_total' => $room->equipment->whereNull('retired_at')->count(),
                'last_delivery' => $deliveries,
                'failed_deliveries' => $failedDeliveries,
            ];
        });

        return view('health.index', [
            'cards' => $cards,
            'failedJobs' => DB::table('failed_jobs')->count(),
            'syncInterval' => (int) config('mines.sync_interval_minutes', 5),
            'schedulerHeartbeat' => $this->heartbeat('dot.scheduler_heartbeat'),
            'queueHeartbeat' => $this->heartbeat(QueueHeartbeat::KEY),
            'memory' => $this->memory($request->user()->currentTeam instanceof Team ? $request->user()->currentTeam : null),
        ]);
    }

    /**
     * The team's Dot.Memory connection and what waits in its outbox.
     *
     * @return array{connection: MemoryConnection|null, pending: int, failed: int, stuck: int, open_incidents: int}
     */
    private function memory(?Team $team): array
    {
        if (! $team instanceof Team) {
            return ['connection' => null, 'pending' => 0, 'failed' => 0, 'stuck' => 0, 'open_incidents' => 0];
        }

        $outbox = MemoryOutboxRow::where('team_id', $team->id)->whereNull('sent_at');

        return [
            'connection' => MemoryConnection::withoutGlobalScopes()->where('team_id', $team->id)->first(),
            'pending' => (clone $outbox)->where('attempts', '<', MemoryOutboxRow::MAX_ATTEMPTS)->count(),
            'failed' => (clone $outbox)->where('attempts', '>', 0)->where('attempts', '<', MemoryOutboxRow::MAX_ATTEMPTS)->count(),
            'stuck' => (clone $outbox)->where('attempts', '>=', MemoryOutboxRow::MAX_ATTEMPTS)->count(),
            'open_incidents' => MemoryIncident::where('team_id', $team->id)->where('status', 'open')->count(),
        ];
    }

    private function heartbeat(string $key): ?Carbon
    {
        $value = Cache::get($key);

        return is_string($value) ? Carbon::parse($value) : null;
    }

    public function sync(Request $request, MinesConnection $connection, Audits $audits): RedirectResponse
    {
        $this->authorize('act-on-health');
        $this->authorize('manage', $connection->controlRoom);

        SyncMinesConnection::dispatch($connection->id, 'manual');
        $audits->record('generate', $connection, ['action' => ['old' => null, 'new' => 'sync requested']], controlRoom: $connection->controlRoom);

        return redirect()->route('health.index')->with('status', 'Synchronisation queued for '.$connection->controlRoom->name.'.');
    }

    public function test(Request $request, MinesConnection $connection, MinesClientFactory $clients, Audits $audits): RedirectResponse
    {
        $this->authorize('act-on-health');
        $this->authorize('manage', $connection->controlRoom);

        try {
            $who = $clients->for($connection)->whoAmI();
            $connection->forceFill(['status' => 'verified', 'mines_team_id' => $who['current_team_id'] ?? $connection->mines_team_id, 'last_error' => null])->save();
            $audits->record('generate', $connection, ['action' => ['old' => null, 'new' => 'credential test: verified']], controlRoom: $connection->controlRoom);

            return redirect()->route('health.index')->with('status', 'Credential verified: Dot.Mines user "'.$who['name'].'", team #'.($who['current_team_id'] ?? '—').'.');
        } catch (CredentialRejected $e) {
            $connection->forceFill(['status' => 'credential_invalid', 'last_failure_at' => now(), 'last_error' => $e->getMessage()])->save();
            $audits->record('generate', $connection, ['action' => ['old' => null, 'new' => 'credential test: rejected']], controlRoom: $connection->controlRoom);

            return redirect()->route('health.index')->with('error', $e->getMessage());
        } catch (MinesException $e) {
            $audits->record('generate', $connection, ['action' => ['old' => null, 'new' => 'credential test: '.class_basename($e)]], controlRoom: $connection->controlRoom);

            return redirect()->route('health.index')->with('error', $e->getMessage());
        }
    }
}
