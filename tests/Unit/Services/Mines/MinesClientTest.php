<?php

namespace Tests\Unit\Services\Mines;

use App\Services\Mines\Exceptions\CredentialRejected;
use App\Services\Mines\Exceptions\MalformedResponse;
use App\Services\Mines\Exceptions\MinesRefused;
use App\Services\Mines\Exceptions\MinesUnavailable;
use App\Services\Mines\Exceptions\RateLimited;
use App\Services\Mines\MinesClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contract tests against the Dot.Mines API shapes verified from its code
 * (docs/dot-central/04-dot-mines-integration.md). The fixtures mirror the
 * real resources field for field; nothing here is invented.
 */
class MinesClientTest extends TestCase
{
    private function client(): MinesClient
    {
        return new MinesClient('http://mines.test/', 'secret-token-value', 10);
    }

    public function test_sync_sends_bearer_and_parses_the_envelope(): void
    {
        Http::fake(['mines.test/api/v1/sync*' => Http::response([
            'version' => 42,
            'server_time' => '2026-10-15T10:00:00+00:00',
            'changes' => ['fleet' => [['id' => 7, 'name' => 'HT-07', 'machine_type' => 'haul_truck', 'status' => 'active', 'allocation_state' => null, 'latitude' => -26.1, 'longitude' => 28.2, 'fuel_level' => 61.5, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => '2026-10-15T09:58:00+00:00', 'sync_version' => 41]]],
            'deleted' => [['entity_type' => 'machines', 'entity_id' => 3, 'sync_version' => 41]],
            'has_more' => false,
        ])]);

        $result = $this->client()->sync(10, ['fleet', 'production']);

        $this->assertSame(42, $result['version']);
        $this->assertFalse($result['has_more']);
        $this->assertSame('HT-07', $result['changes']['fleet'][0]['name']);
        $this->assertSame([['entity_type' => 'machines', 'entity_id' => 3, 'sync_version' => 41]], $result['deleted']);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'http://mines.test/api/v1/sync?since=10&scopes=fleet%2Cproduction'
                && $request->hasHeader('Authorization', 'Bearer secret-token-value')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_machines_and_alerts_parse_the_paginated_envelope(): void
    {
        Http::fake([
            'mines.test/api/v1/machines*' => Http::response(['data' => [['id' => 7, 'name' => 'HT-07', 'status' => 'idle', 'location' => ['latitude' => null, 'longitude' => null, 'updated_at' => null]]], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 100, 'from' => 1, 'to' => 1]]),
            'mines.test/api/v1/alerts*' => Http::response(['data' => [['id' => 99, 'type' => 'fuel', 'title' => 'Low fuel', 'priority' => 'high', 'status' => 'active', 'machine_id' => 7, 'triggered_at' => '2026-10-15T09:00:00+00:00', 'metadata' => null]], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 100, 'from' => 1, 'to' => 1]]),
        ]);

        $machines = $this->client()->machines(1, 100);
        $alerts = $this->client()->activeAlerts(1, 100);

        $this->assertSame('HT-07', $machines['data'][0]['name']);
        $this->assertSame(1, $machines['meta']['last_page']);
        $this->assertSame('Low fuel', $alerts['data'][0]['title']);

        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'http://mines.test/api/v1/machines?') && str_contains($r->url(), 'per_page=100'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/api/v1/alerts?') && str_contains($r->url(), 'status=active'));
    }

    public function test_latest_metric_returns_the_newest_row_or_null(): void
    {
        Http::fake([
            'mines.test/api/v1/machines/7/metrics*' => Http::response(['data' => [['id' => 1, 'operating_hours' => 1234.5, 'idle_hours' => 12.0, 'fuel_level' => 61.5, 'speed' => null, 'recorded_at' => '2026-10-15T09:58:00+00:00', 'created_at' => '2026-10-15T09:59:10+00:00']]]),
            'mines.test/api/v1/machines/8/metrics*' => Http::response(['data' => []]),
        ]);

        $this->assertSame(1234.5, $this->client()->latestMetric(7)['operating_hours']);
        $this->assertNull($this->client()->latestMetric(8));

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/machines/7/metrics?limit=1'));
    }

    public function test_acknowledge_and_resolve_post_to_the_alert(): void
    {
        Http::fake(['mines.test/api/v1/alerts/99/*' => Http::response(['data' => ['id' => 99, 'status' => 'acknowledged'], 'message' => 'ok'])]);

        $this->client()->acknowledgeAlert(99);
        $this->client()->resolveAlert(99);

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/alerts/99/acknowledge'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/alerts/99/resolve'));
    }

    public function test_who_am_i_reads_the_user_and_current_team(): void
    {
        Http::fake(['mines.test/api/v1/user' => Http::response(['id' => 2, 'name' => 'Demo', 'email' => 'demo@example.test', 'current_team_id' => 2])]);

        $this->assertSame(2, $this->client()->whoAmI()['current_team_id']);
    }

    public function test_credential_rejection_is_typed_and_never_leaks_the_token(): void
    {
        Http::fake(['mines.test/*' => Http::response(['message' => 'Unauthenticated.'], 401)]);

        try {
            $this->client()->whoAmI();
            $this->fail('expected CredentialRejected');
        } catch (CredentialRejected $e) {
            $this->assertStringNotContainsString('secret-token-value', $e->getMessage());
            $this->assertSame(401, $e->status);
        }
    }

    public function test_rate_limit_carries_retry_after(): void
    {
        Http::fake(['mines.test/*' => Http::response(['message' => 'Too many requests', 'retry_after' => 17], 429)]);

        try {
            $this->client()->machines();
            $this->fail('expected RateLimited');
        } catch (RateLimited $e) {
            $this->assertSame(17, $e->retryAfter);
        }
    }

    public function test_server_errors_and_connection_failures_are_unavailable(): void
    {
        Http::fake(['mines.test/api/v1/sync*' => Http::response('oops', 503)]);
        $this->expectException(MinesUnavailable::class);
        $this->client()->sync(0, ['fleet']);
    }

    public function test_non_json_or_missing_keys_is_malformed(): void
    {
        Http::fake(['mines.test/api/v1/sync*' => Http::response('<html>login</html>', 200, ['Content-Type' => 'text/html'])]);
        $this->expectException(MalformedResponse::class);
        $this->client()->sync(0, ['fleet']);
    }

    public function test_operator_assignments_post_and_delete_and_a_refusal_carries_the_blockers(): void
    {
        Http::fake([
            'mines.test/api/v1/machines/7/operator-assignments' => Http::sequence()
                ->push(['data' => ['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'shift' => 'day', 'assigned_at' => '2026-10-15T18:00:00+00:00', 'unassigned_at' => null], 'message' => 'Operator assigned successfully'], 201)
                ->push(['message' => 'The given data was invalid.', 'errors' => ['operator_id' => ['Operator One holds no Articulated Dump Truck licence.', 'Operator One has no current medical.']]], 422)
                ->push(['data' => ['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'unassigned_at' => '2026-10-15T19:00:00+00:00', 'reason' => 'End of shift'], 'message' => 'Operator unassigned successfully']),
        ]);

        $assignment = $this->client()->assignOperator(7, 3, 'day');
        $this->assertSame(55, $assignment['id']);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/api/v1/machines/7/operator-assignments') && $r['operator_id'] === 3 && $r['shift'] === 'day');

        try {
            $this->client()->assignOperator(7, 3);
            $this->fail('a 422 must be a typed refusal');
        } catch (MinesRefused $e) {
            $this->assertSame(['Operator One holds no Articulated Dump Truck licence.', 'Operator One has no current medical.'], $e->messages);
        }

        $ended = $this->client()->unassignOperator(7, 'End of shift');
        $this->assertSame('End of shift', $ended['reason']);
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/api/v1/machines/7/operator-assignments'));
    }
}
