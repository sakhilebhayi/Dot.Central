<?php

namespace Tests\Unit\Services\Memory;

use App\Services\Memory\Exceptions\MemoryCredentialRejected;
use App\Services\Memory\Exceptions\MemoryRefused;
use App\Services\Memory\Exceptions\MemoryUnavailable;
use App\Services\Memory\MemoryClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MemoryClientTest extends TestCase
{
    private function client(): MemoryClient
    {
        return new MemoryClient('http://memory.test/', 'memory-token-value', 3);
    }

    public function test_record_posts_to_the_stage_endpoint_with_bearer(): void
    {
        Http::fake(['memory.test/api/intelligence/decisions' => Http::response(['data' => ['id' => 5]], 201)]);

        $result = $this->client()->record('decision', ['loop_id' => 'l', 'recommendation' => 'x']);

        $this->assertSame(['id' => 5], $result);
        Http::assertSent(fn (Request $r) => $r->url() === 'http://memory.test/api/intelligence/decisions'
            && $r->hasHeader('Authorization', 'Bearer memory-token-value')
            && $r['recommendation'] === 'x');
    }

    public function test_context_queries_by_subject_and_signature(): void
    {
        Http::fake(['memory.test/api/intelligence/context*' => Http::response(['data' => ['known' => true, 'observations' => 2]])]);

        $context = $this->client()->context('equipment', '7', 'dot-central:alert:critical');

        $this->assertTrue($context['known']);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'subject_type=equipment') && str_contains($r->url(), 'subject_id=7') && str_contains($r->url(), 'platform=dot-central'));
    }

    public function test_incidents_store_and_update(): void
    {
        Http::fake([
            'memory.test/api/ops/incidents' => Http::response(['data' => ['incident_uid' => 'u1']], 201),
            'memory.test/api/ops/incidents/u1' => Http::response(['data' => ['incident_uid' => 'u1', 'status' => 'resolved']]),
        ]);

        $this->assertSame('u1', $this->client()->storeIncident(['incident_uid' => 'u1'])['incident_uid']);
        $this->assertSame('resolved', $this->client()->updateIncident('u1', ['status' => 'resolved'])['status']);
        Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->url() === 'http://memory.test/api/ops/incidents/u1');
    }

    public function test_errors_are_typed_and_never_carry_the_token(): void
    {
        Http::fake([
            'memory.test/api/intelligence/events' => Http::response(['message' => 'Unauthenticated.'], 401),
            'memory.test/api/intelligence/actions' => Http::response(['message' => 'bad', 'errors' => ['execution_status' => ['The selected execution status is invalid.']]], 422),
            'memory.test/api/intelligence/outcomes' => Http::response('', 503),
        ]);

        try {
            $this->client()->record('event', []);
            $this->fail('expected credential rejection');
        } catch (MemoryCredentialRejected $e) {
            $this->assertStringNotContainsString('memory-token-value', $e->getMessage());
        }

        try {
            $this->client()->record('action', []);
            $this->fail('expected refusal');
        } catch (MemoryRefused $e) {
            $this->assertSame(['The selected execution status is invalid.'], $e->messages);
        }

        $this->expectException(MemoryUnavailable::class);
        $this->client()->record('outcome', []);
    }

    public function test_unknown_stage_is_rejected_locally(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->client()->record('wish', []);
    }
}
