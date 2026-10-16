<?php

namespace App\Services\Memory;

use App\Services\Memory\Exceptions\MemoryCredentialRejected;
use App\Services\Memory\Exceptions\MemoryRefused;
use App\Services\Memory\Exceptions\MemoryUnavailable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Typed client over Dot.Memory's API, verified against its code on
 * 2026-10-16: the intelligence loop (/api/intelligence/*) and the ops
 * incident store (/api/ops/*). Every failure is a typed exception whose
 * message never contains the token.
 */
final class MemoryClient
{
    public const PLATFORM = 'dot-central';

    private const STAGE_PATHS = ['event' => 'intelligence/events', 'decision' => 'intelligence/decisions', 'action' => 'intelligence/actions', 'outcome' => 'intelligence/outcomes'];

    public function __construct(private readonly string $baseUrl, private readonly string $token, private readonly int $timeout = 10) {}

    /** @return array{id:int,name:string,email:string} */
    public function whoAmI(): array
    {
        $data = $this->json($this->send(fn (PendingRequest $r) => $r->get($this->url('user'))));

        return ['id' => (int) ($data['id'] ?? 0), 'name' => (string) ($data['name'] ?? ''), 'email' => (string) ($data['email'] ?? '')];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function record(string $stage, array $payload): array
    {
        $path = self::STAGE_PATHS[$stage] ?? throw new InvalidArgumentException("Unknown loop stage '{$stage}'.");

        return $this->data($this->send(fn (PendingRequest $r) => $r->post($this->url($path), $payload + ['platform' => self::PLATFORM])));
    }

    /** @return array<string, mixed> */
    public function context(string $subjectType, string $subjectId, ?string $signature = null): array
    {
        // No platform filter on purpose: a machine's history is shared with
        // Dot.Mines and the guardian, and recall should show all of it.
        $query = array_filter(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'signature' => $signature], fn ($v) => $v !== null);

        return $this->data($this->send(fn (PendingRequest $r) => $r->get($this->url('intelligence/context'), $query)));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function storeIncident(array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $r) => $r->post($this->url('ops/incidents'), $payload + ['platform' => self::PLATFORM])));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateIncident(string $uid, array $payload): array
    {
        return $this->data($this->send(fn (PendingRequest $r) => $r->patch($this->url('ops/incidents/'.rawurlencode($uid)), $payload)));
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/api/'.ltrim($path, '/');
    }

    /** @param callable(PendingRequest): Response $call */
    private function send(callable $call): Response
    {
        $request = Http::withToken($this->token)->acceptJson()->withHeaders(['X-Requested-With' => 'DotCentral'])->withOptions(['allow_redirects' => false])->connectTimeout(min(5, $this->timeout))->timeout($this->timeout);

        try {
            $response = $call($request);
        } catch (ConnectionException $e) {
            throw new MemoryUnavailable('Dot.Memory could not be reached: '.$this->safe($e->getMessage()));
        }

        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new MemoryCredentialRejected('Dot.Memory rejected the credential (HTTP '.$status.').', $status);
        }

        if ($status === 422) {
            $errors = $response->json('errors');
            $messages = is_array($errors) ? array_values(array_map('strval', array_merge(...array_map(fn ($m) => is_array($m) ? $m : [$m], array_values($errors))))) : [(string) ($response->json('message') ?? 'record refused')];

            throw new MemoryRefused($messages !== [] ? $messages : ['record refused']);
        }

        if (! $response->successful()) {
            throw new MemoryUnavailable('Dot.Memory returned HTTP '.$status.'.', $status);
        }

        return $response;
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $json = $this->json($response);

        return is_array($json['data'] ?? null) ? $json['data'] : $json;
    }

    private function safe(string $message): string
    {
        return str_replace($this->token, '[redacted]', $message);
    }
}
