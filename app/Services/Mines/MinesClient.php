<?php

namespace App\Services\Mines;

use App\Services\Mines\Exceptions\CredentialRejected;
use App\Services\Mines\Exceptions\MalformedResponse;
use App\Services\Mines\Exceptions\MinesUnavailable;
use App\Services\Mines\Exceptions\RateLimited;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Typed client over the Dot.Mines REST API (/api/v1), verified against the
 * Dot.Mines code on 2026-10-15 (docs/dot-central/04-dot-mines-integration.md).
 * Only endpoints that exist are wrapped; every failure is a typed exception
 * whose message never contains the token.
 */
final class MinesClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $token,
        private readonly int $timeout = 10,
    ) {}

    /**
     * GET /sync — delta cursor over the given scopes.
     *
     * @param  list<string>  $scopes  fleet|production|notifications|reference
     * @return array{version:int, server_time:string, changes:array<string, list<array<string, mixed>>>, deleted:list<array<string, mixed>>, has_more:bool}
     */
    public function sync(int $since, array $scopes): array
    {
        $data = $this->get('sync', ['since' => $since, 'scopes' => implode(',', $scopes)]);
        $this->require($data, ['version', 'changes', 'deleted', 'has_more'], 'sync');

        return [
            'version' => (int) $data['version'],
            'server_time' => (string) ($data['server_time'] ?? ''),
            'changes' => is_array($data['changes']) ? $data['changes'] : [],
            'deleted' => is_array($data['deleted']) ? $data['deleted'] : [],
            'has_more' => (bool) $data['has_more'],
        ];
    }

    /**
     * GET /machines — paginated MachineResource list.
     *
     * @return array{data:list<array<string, mixed>>, meta:array{current_page:int, last_page:int, total:int}}
     */
    public function machines(int $page = 1, int $perPage = 100): array
    {
        return $this->paginated('machines', ['page' => $page, 'per_page' => $perPage]);
    }

    /**
     * GET /machines/{id} — one MachineResource.
     *
     * @return array<string, mixed>
     */
    public function machine(int $id): array
    {
        $data = $this->get('machines/'.$id);
        $this->require($data, ['data'], 'machine');

        return $data['data'];
    }

    /**
     * GET /machines/{id}/metrics?limit=1 — the newest raw machine_metrics row,
     * or null when the machine has never reported.
     *
     * @return array<string, mixed>|null
     */
    public function latestMetric(int $machineId): ?array
    {
        $rows = $this->metrics($machineId, null, 1);

        return $rows === [] ? null : $rows[0];
    }

    /**
     * GET /machines/{id}/metrics — raw machine_metrics rows, newest first,
     * optionally bounded to the last N hours (max 720) and a row limit (max 1000).
     *
     * @return list<array<string, mixed>>
     */
    public function metrics(int $machineId, ?int $hoursBack = null, int $limit = 200): array
    {
        $query = ['limit' => max(1, min(1000, $limit))];
        if ($hoursBack !== null) {
            $query['hours_back'] = max(1, min(720, $hoursBack));
        }

        $data = $this->get('machines/'.$machineId.'/metrics', $query);
        $this->require($data, ['data'], 'metrics');

        return is_array($data['data']) ? array_values(array_filter($data['data'], 'is_array')) : [];
    }

    /**
     * GET /alerts?status=active — paginated AlertResource list.
     *
     * @return array{data:list<array<string, mixed>>, meta:array{current_page:int, last_page:int, total:int}}
     */
    public function activeAlerts(int $page = 1, int $perPage = 100): array
    {
        return $this->paginated('alerts', ['status' => 'active', 'page' => $page, 'per_page' => $perPage]);
    }

    /** @return array<string, mixed> */
    public function acknowledgeAlert(int $id): array
    {
        $data = $this->post('alerts/'.$id.'/acknowledge');
        $this->require($data, ['data'], 'acknowledge');

        return $data['data'];
    }

    /** @return array<string, mixed> */
    public function resolveAlert(int $id): array
    {
        $data = $this->post('alerts/'.$id.'/resolve');
        $this->require($data, ['data'], 'resolve');

        return $data['data'];
    }

    /**
     * GET /user — the token's user; current_team_id is the tenant every other
     * call is scoped to.
     *
     * @return array{id:int, name:string, email:string, current_team_id:int|null}
     */
    public function whoAmI(): array
    {
        $data = $this->get('user');
        $this->require($data, ['id'], 'user');

        return [
            'id' => (int) $data['id'],
            'name' => (string) ($data['name'] ?? ''),
            'email' => (string) ($data['email'] ?? ''),
            'current_team_id' => isset($data['current_team_id']) ? (int) $data['current_team_id'] : null,
        ];
    }

    // ------------------------------------------------------------------

    /**
     * @param  array<string, scalar>  $query
     * @return array{data:list<array<string, mixed>>, meta:array{current_page:int, last_page:int, total:int}}
     */
    private function paginated(string $path, array $query): array
    {
        $data = $this->get($path, $query);
        $this->require($data, ['data', 'meta'], $path);
        $meta = is_array($data['meta']) ? $data['meta'] : [];

        return [
            'data' => is_array($data['data']) ? array_values($data['data']) : [],
            'meta' => [
                'current_page' => (int) ($meta['current_page'] ?? 1),
                'last_page' => (int) ($meta['last_page'] ?? 1),
                'total' => (int) ($meta['total'] ?? count($data['data'])),
            ],
        ];
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function get(string $path, array $query = []): array
    {
        return $this->decode($this->send(fn (PendingRequest $r) => $r->get($this->url($path), $query)), $path);
    }

    /** @return array<string, mixed> */
    private function post(string $path): array
    {
        return $this->decode($this->send(fn (PendingRequest $r) => $r->post($this->url($path))), $path);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1/'.ltrim($path, '/');
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call): Response
    {
        $request = Http::withToken($this->token)
            ->acceptJson()
            ->withHeaders(['X-Requested-With' => 'DotCentral'])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout($this->timeout);

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new MinesUnavailable('Dot.Mines could not be reached: '.$this->safe($e->getMessage()));
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new CredentialRejected('Dot.Mines rejected the credential (HTTP '.$status.').', $status);
        }

        if ($status === 429) {
            $retryAfter = (int) ($response->json('retry_after') ?? $response->header('Retry-After') ?: 60);

            throw new RateLimited(max(1, $retryAfter));
        }

        if ($status >= 500) {
            throw new MinesUnavailable('Dot.Mines returned HTTP '.$status.'.', $status);
        }

        if ($status === 404) {
            throw new MalformedResponse('Dot.Mines has no such resource (HTTP 404).', 404);
        }

        if (! $response->successful()) {
            throw new MinesUnavailable('Dot.Mines returned HTTP '.$status.'.', $status);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response, string $path): array
    {
        $json = $response->json();

        if (! is_array($json)) {
            throw new MalformedResponse('Dot.Mines returned a non-JSON body for '.$path.'.', $response->status());
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    private function require(array $data, array $keys, string $what): void
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $data)) {
                throw new MalformedResponse('Dot.Mines '.$what.' response is missing "'.$key.'".');
            }
        }
    }

    /** Strip anything that could carry the token out of a transport message. */
    private function safe(string $message): string
    {
        return str_replace($this->token, '[token]', $message);
    }
}
