<?php

namespace App\Services\Memory;

use App\Models\MemoryConnection;
use App\Services\Memory\Exceptions\MemoryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * "What was tried before and what worked" for one subject, from
 * Dot.Memory's context endpoint. Time-boxed, cached, and never raises:
 * an unreachable Memory is a state the card shows, not an error.
 *
 * @phpstan-type Context array{state:'unconfigured'|'none'|'known'|'unreachable', seen:int, first_seen:?string, last_seen:?string, tried:list<array{kind:string,verdict:?string,at:?string}>, worked:?string, fetched_at:?string}
 */
class MemoryContextService
{
    public function __construct(private readonly MemoryClientFactory $clients) {}

    /** @return Context */
    public function for(int $teamId, string $subjectType, string $subjectId, ?string $signature): array
    {
        $connection = $this->clients->connectionFor($teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return $this->empty('unconfigured');
        }

        $key = 'memory-context:'.$teamId.':'.$subjectType.':'.$subjectId.':'.($signature ?? '-');
        $cached = Cache::get($key);

        if (is_array($cached)) {
            /** @var Context $cached */
            return $cached;
        }

        $context = $this->fetch($connection, $subjectType, $subjectId, $signature);
        $minutes = $context['state'] === 'unreachable' ? 2 : max(1, (int) config('memory.context_cache_minutes', 10));
        Cache::put($key, $context, now()->addMinutes($minutes));

        return $context;
    }

    public static function forget(int $teamId, string $subjectType, string $subjectId, ?string $signature): void
    {
        Cache::forget('memory-context:'.$teamId.':'.$subjectType.':'.$subjectId.':'.($signature ?? '-'));
    }

    /** @return Context */
    private function fetch(MemoryConnection $connection, string $subjectType, string $subjectId, ?string $signature): array
    {
        $client = new MemoryClient($connection->baseUrl(), (string) $connection->api_token, max(1, (int) config('memory.context_timeout', 3)));

        try {
            $data = $client->context($subjectType, $subjectId, $signature);
        } catch (MemoryException $e) {
            return $this->empty('unreachable', $connection->last_context_at?->toIso8601String());
        }

        $connection->forceFill(['last_context_at' => now()])->save();

        if (! (bool) ($data['known'] ?? false)) {
            return $this->empty('none', now()->toIso8601String());
        }

        $tried = [];
        foreach (is_array($data['what_was_tried'] ?? null) ? $data['what_was_tried'] : [] as $row) {
            if (is_array($row) && isset($row['action_kind'])) {
                $tried[] = ['kind' => (string) $row['action_kind'], 'verdict' => isset($row['verdict']) ? (string) $row['verdict'] : null, 'at' => isset($row['at']) ? (string) $row['at'] : null];
            }
        }

        $worked = null;
        $best = is_array($data['what_worked'] ?? null) ? $data['what_worked'] : [];
        $first = $best[0] ?? null;
        if (is_array($first) && isset($first['action_kind'])) {
            $worked = (string) $first['action_kind'];
        } elseif (is_string($first)) {
            $worked = $first;
        }

        return [
            'state' => 'known',
            'seen' => (int) ($data['observations'] ?? 0),
            'first_seen' => isset($data['first_seen']) ? (string) $data['first_seen'] : null,
            'last_seen' => isset($data['last_seen']) ? (string) $data['last_seen'] : null,
            'tried' => array_slice($tried, -3),
            'worked' => $worked,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  'unconfigured'|'none'|'unreachable'  $state
     * @return Context
     */
    private function empty(string $state, ?string $fetchedAt = null): array
    {
        return ['state' => $state, 'seen' => 0, 'first_seen' => null, 'last_seen' => null, 'tried' => [], 'worked' => null, 'fetched_at' => $fetchedAt];
    }

    public static function phrase(string $kind): string
    {
        return str_replace(['alert.', 'exception.', 'operator.', 'downtime.', 'lineup.', 'shift.', '.', '_'], ['', '', '', '', '', '', ' ', ' '], $kind);
    }

    public static function day(?string $iso): string
    {
        return $iso === null ? '' : Carbon::parse($iso)->format('j M');
    }
}
