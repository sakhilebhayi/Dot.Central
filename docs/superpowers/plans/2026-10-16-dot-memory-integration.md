# Dot.Memory Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dot.Central records the control room's decisions, actions and outcomes into Dot.Memory's intelligence loop, shows Memory's context on alerts and exceptions, and reports its own integration incidents, without ever blocking an operator.

**Architecture:** One `memory_connections` row per team (encrypted token, configured on the Health page). Every record is derived from the audit log (`Audits::record` calls `MemoryRecorder::fromAudit`) or from two mirror hooks, mapped by a pure `AuditLoopMapper`, written to a local `memory_outbox` and sent by the queued `FlushMemoryOutbox` job with backoff. Recall is a lazy Livewire component with a cached, time-boxed `GET /api/intelligence/context`. Incidents are evaluated by a scheduled command and sent through the same outbox.

**Tech Stack:** Laravel 13, Livewire 3 (`#[Lazy]`), Laravel HTTP client with `Http::fake()` in tests, SQLite in tests.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-10-16-dot-memory-integration-design.md`.
- Dot.Memory contract (verified from its code): record fields `loop_id, event_id, platform, source, subject_type, subject_id, subject_label, team_id, user_id, occurred_at, signature, detail`; decision `recommendation, confidence (0..1), risk (0..1), autonomy_level ∈ observe|recommend|approve|execute|autonomous, requires_approval`; action `action_kind, executor_platform, mechanic_ref, approval_status ∈ not_required|pending|approved|rejected, execution_status ∈ pending|succeeded|failed|refused|rolled_back`; outcome `verdict ∈ improved|unchanged|worsened|inconclusive, measure, value_before, value_after`; incidents `incident_uid, platform, environment, detection_source, signature, component, severity ∈ sev1..sev4, status ∈ open|remediating|resolved|escalated|rolled_back, detected_at, resolved_at, record`.
- `platform` is always `dot-central`. Subject vocabulary: `equipment` with `subject_id` = Dot.Mines machine id; `control_room`; `shift`.
- Memory never blocks an operator: writes go to the outbox; recall has a 3 s timeout and a 10 minute cache.
- Tokens stored with the `encrypted` cast, redacted from audits, never logged or printed.
- No customer, site or person names anywhere (`ProhibitedReferencesTest`).
- Every change tested; Pint, PHPStan (`--memory-limit=1G`) and the full suite green before each commit.

---

### Task 1: Tables and models

**Files:**
- Create: `database/migrations/2026_10_16_000020_create_memory_tables.php`
- Create: `app/Models/MemoryConnection.php`, `app/Models/MemoryOutboxRow.php`, `app/Models/MemoryIncident.php`
- Create: `database/factories/MemoryConnectionFactory.php`
- Test: `tests/Feature/Memory/MemoryConnectionModelTest.php`

**Interfaces:**
- Produces: `MemoryConnection` (`team_id, base_url, api_token, enabled, status, last_verified_at, last_write_at, last_context_at, last_error`; `STATUSES`; `isUsable(): bool`; `baseUrl(): string`), `MemoryOutboxRow` (table `memory_outbox`; `team_id, stage, event_id, payload (array), attempts, last_error, next_attempt_at, sent_at`; `MAX_ATTEMPTS = 20`; scope `due()`), `MemoryIncident` (`team_id, incident_uid, signature, component, severity, status, detected_at, resolved_at`).

- [ ] **Step 1: Failing test**

```php
<?php

namespace Tests\Feature\Memory;

use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MemoryConnectionModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_is_encrypted_redacted_from_audits_and_usability_follows_status(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $team = $user->currentTeam;

        $connection = MemoryConnection::factory()->create(['team_id' => $team->id, 'api_token' => 'memory-token-value']);

        $raw = (string) \DB::table('memory_connections')->where('id', $connection->id)->value('api_token');
        $this->assertStringNotContainsString('memory-token-value', $raw);
        $this->assertSame('memory-token-value', $connection->fresh()->api_token);

        $audit = \DB::table('audit_log')->where('entity_type', MemoryConnection::class)->latest('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('memory-token-value', (string) $audit->changes);

        $this->assertTrue($connection->isUsable());
        $connection->forceFill(['status' => 'credential_invalid'])->save();
        $this->assertFalse($connection->fresh()->isUsable());
        $connection->forceFill(['status' => 'verified', 'enabled' => false])->save();
        $this->assertFalse($connection->fresh()->isUsable());
    }

    public function test_outbox_due_scope_excludes_sent_future_and_exhausted_rows(): void
    {
        $team = Team::factory()->create();
        $base = ['team_id' => $team->id, 'stage' => 'event', 'payload' => ['x' => 1]];
        MemoryOutboxRow::create($base + ['event_id' => 'a']);
        MemoryOutboxRow::create($base + ['event_id' => 'b', 'sent_at' => now()]);
        MemoryOutboxRow::create($base + ['event_id' => 'c', 'next_attempt_at' => now()->addHour()]);
        MemoryOutboxRow::create($base + ['event_id' => 'd', 'attempts' => MemoryOutboxRow::MAX_ATTEMPTS]);

        $this->assertSame(['a'], MemoryOutboxRow::due()->pluck('event_id')->all());
    }
}
```

- [ ] **Step 2: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memory_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('base_url');
            $table->text('api_token')->nullable();
            $table->boolean('enabled')->default(true);
            $table->string('status', 32)->default('unverified');
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamp('last_write_at')->nullable();
            $table->timestamp('last_context_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });

        Schema::create('memory_outbox', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 32);
            $table->string('event_id')->nullable()->index();
            $table->json('payload');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'sent_at', 'next_attempt_at']);
        });

        Schema::create('memory_incidents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('incident_uid')->unique();
            $table->string('signature');
            $table->string('component');
            $table->string('severity', 8);
            $table->string('status', 32);
            $table->timestamp('detected_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'signature', 'component', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memory_incidents');
        Schema::dropIfExists('memory_outbox');
        Schema::dropIfExists('memory_connections');
    }
};
```

- [ ] **Step 3: Models and factory**

`app/Models/MemoryConnection.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\HasTeamScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One Dot.Memory connection per team: the organisation's memory, not a
 * room's. The token leaves the encrypted column only inside
 * MemoryClientFactory. See docs/dot-central/14-dot-memory-integration.md.
 *
 * @property int $id
 * @property int $team_id
 * @property string $base_url
 * @property string|null $api_token
 * @property bool $enabled
 * @property string $status
 * @property Carbon|null $last_verified_at
 * @property Carbon|null $last_write_at
 * @property Carbon|null $last_context_at
 * @property string|null $last_error
 */
class MemoryConnection extends Model
{
    use Auditable;
    use HasFactory;
    use HasTeamScope;

    public const STATUSES = ['unverified', 'verified', 'credential_invalid', 'unreachable'];

    protected $fillable = ['team_id', 'base_url', 'api_token', 'enabled', 'status', 'last_verified_at', 'last_write_at', 'last_context_at', 'last_error'];

    protected $hidden = ['api_token'];

    protected $casts = [
        'api_token' => 'encrypted',
        'enabled' => 'boolean',
        'last_verified_at' => 'datetime',
        'last_write_at' => 'datetime',
        'last_context_at' => 'datetime',
    ];

    /** @return list<string> */
    public function auditRedactedAttributes(): array
    {
        return ['api_token'];
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function baseUrl(): string
    {
        return rtrim($this->base_url, '/');
    }

    public function isUsable(): bool
    {
        return $this->enabled && $this->api_token !== null && $this->api_token !== '' && $this->status !== 'credential_invalid';
    }
}
```

`app/Models/MemoryOutboxRow.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A record waiting to be sent to Dot.Memory. Written in the same
 * transaction as the operator's action; sent by FlushMemoryOutbox.
 *
 * @property int $id
 * @property int $team_id
 * @property string $stage
 * @property string|null $event_id
 * @property array<string, mixed> $payload
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 */
class MemoryOutboxRow extends Model
{
    public const MAX_ATTEMPTS = 20;

    /** @var list<int> minutes before the next attempt, by attempt number */
    public const BACKOFF_MINUTES = [1, 5, 15, 60];

    protected $table = 'memory_outbox';

    protected $fillable = ['team_id', 'stage', 'event_id', 'payload', 'attempts', 'last_error', 'next_attempt_at', 'sent_at'];

    protected $casts = ['payload' => 'array', 'attempts' => 'integer', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];

    /** @param Builder<MemoryOutboxRow> $query */
    public function scopeDue(Builder $query): void
    {
        $query->whereNull('sent_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('id');
    }

    public function backoff(): Carbon
    {
        $minutes = self::BACKOFF_MINUTES[min($this->attempts, count(self::BACKOFF_MINUTES)) - 1] ?? 60;

        return now()->addMinutes($minutes);
    }
}
```

`app/Models/MemoryIncident.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Our own copy of an ops incident reported to Dot.Memory, so the reporter
 * knows what is open and can resolve it when the condition clears.
 *
 * @property int $id
 * @property int $team_id
 * @property string $incident_uid
 * @property string $signature
 * @property string $component
 * @property string $severity
 * @property string $status
 * @property Carbon $detected_at
 * @property Carbon|null $resolved_at
 */
class MemoryIncident extends Model
{
    protected $fillable = ['team_id', 'incident_uid', 'signature', 'component', 'severity', 'status', 'detected_at', 'resolved_at'];

    protected $casts = ['detected_at' => 'datetime', 'resolved_at' => 'datetime'];
}
```

`database/factories/MemoryConnectionFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\MemoryConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemoryConnection> */
class MemoryConnectionFactory extends Factory
{
    protected $model = MemoryConnection::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'base_url' => 'http://memory.test',
            'api_token' => 'memory-test-token',
            'enabled' => true,
            'status' => 'verified',
        ];
    }
}
```

- [ ] **Step 4: Run** `php artisan test --compact tests/Feature/Memory/MemoryConnectionModelTest.php` → PASS. Commit: `git commit -m "Memory: connection, outbox and incident tables and models"`.

### Task 2: `MemoryClient`, exceptions and factory

**Files:**
- Create: `app/Services/Memory/MemoryClient.php`, `app/Services/Memory/MemoryClientFactory.php`, `app/Services/Memory/Exceptions/{MemoryException,MemoryUnavailable,MemoryCredentialRejected,MemoryRefused}.php`
- Test: `tests/Unit/Services/Memory/MemoryClientTest.php`

**Interfaces:**
- Produces: `MemoryClient::__construct(string $baseUrl, string $token, int $timeout = 10)`, `whoAmI(): array{id:int,name:string,email:string}`, `record(string $stage, array $payload): array` (stage ∈ event|decision|action|outcome), `context(string $subjectType, string $subjectId, ?string $signature): array`, `storeIncident(array $payload): array`, `updateIncident(string $uid, array $payload): array`; `MemoryClientFactory::connectionFor(Team $team): ?MemoryConnection`, `forTeam(Team $team): ?MemoryClient` (null when no usable connection), `for(MemoryConnection $c): MemoryClient`.

- [ ] **Step 1: Failing tests**

```php
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
```

- [ ] **Step 2: Implementation**

Exceptions (`app/Services/Memory/Exceptions/`): `MemoryException extends \RuntimeException`; `MemoryUnavailable extends MemoryException`; `MemoryCredentialRejected extends MemoryException`; `MemoryRefused extends MemoryException` with `public function __construct(public readonly array $messages) { parent::__construct('Dot.Memory refused the record: '.implode(' ', $messages)); }`.

`app/Services/Memory/MemoryClient.php`:

```php
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
        $query = array_filter(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'signature' => $signature, 'platform' => self::PLATFORM], fn ($v) => $v !== null);

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
```

`app/Services/Memory/MemoryClientFactory.php`:

```php
<?php

namespace App\Services\Memory;

use App\Models\MemoryConnection;
use App\Models\Team;

/**
 * The token leaves the encrypted column only here, straight into the client.
 */
final class MemoryClientFactory
{
    public function connectionFor(Team|int $team): ?MemoryConnection
    {
        $teamId = $team instanceof Team ? $team->id : $team;

        return MemoryConnection::withoutGlobalScopes()->where('team_id', $teamId)->first();
    }

    public function forTeam(Team|int $team): ?MemoryClient
    {
        $connection = $this->connectionFor($team);

        return $connection !== null && $connection->isUsable() ? $this->for($connection) : null;
    }

    public function for(MemoryConnection $connection): MemoryClient
    {
        return new MemoryClient($connection->baseUrl(), (string) $connection->api_token, (int) config('memory.request_timeout', 10));
    }
}
```

Add `config/memory.php`:

```php
<?php

return [
    'request_timeout' => (int) env('MEMORY_REQUEST_TIMEOUT', 10),
    'context_timeout' => (int) env('MEMORY_CONTEXT_TIMEOUT', 3),
    'context_cache_minutes' => (int) env('MEMORY_CONTEXT_CACHE_MINUTES', 10),
    'outbox_retention_days' => (int) env('MEMORY_OUTBOX_RETENTION_DAYS', 30),
];
```

- [ ] **Step 3: Run** the test file → PASS. Commit: `"Memory: typed client, factory and config"`.

### Task 3: Outbox: `MemoryRecorder`, `FlushMemoryOutbox`, scheduler commands

**Files:**
- Create: `app/Services/Memory/MemoryRecorder.php`, `app/Jobs/FlushMemoryOutbox.php`, `app/Console/Commands/FlushMemoryOutboxes.php` (`memory:flush`), `app/Console/Commands/PruneMemoryOutbox.php` (`memory:prune`)
- Modify: `routes/console.php`
- Test: `tests/Feature/Memory/MemoryOutboxTest.php`

**Interfaces:**
- Produces: `MemoryRecorder::queue(int $teamId, string $stage, array $payload): ?MemoryOutboxRow` (null when the team has no usable connection; otherwise inserts and dispatches `FlushMemoryOutbox` after commit; `event_id` taken from `$payload['event_id']`, duplicates by `event_id` for the same team are not inserted twice); `FlushMemoryOutbox::__construct(int $teamId)`.

- [ ] **Step 1: Failing tests**

```php
<?php

namespace Tests\Feature\Memory;

use App\Jobs\FlushMemoryOutbox;
use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\Team;
use App\Services\Memory\MemoryRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MemoryOutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_writes_a_row_once_per_event_id_and_dispatches_the_flush(): void
    {
        Queue::fake();
        $connection = MemoryConnection::factory()->create();
        $recorder = app(MemoryRecorder::class);

        $row = $recorder->queue($connection->team_id, 'decision', ['event_id' => 'e1', 'loop_id' => 'l']);
        $again = $recorder->queue($connection->team_id, 'decision', ['event_id' => 'e1', 'loop_id' => 'l']);

        $this->assertNotNull($row);
        $this->assertSame($row->id, $again?->id);
        $this->assertSame(1, MemoryOutboxRow::count());
        Queue::assertPushed(FlushMemoryOutbox::class);
    }

    public function test_queue_is_a_no_op_without_a_usable_connection(): void
    {
        Queue::fake();
        $team = Team::factory()->create();

        $this->assertNull(app(MemoryRecorder::class)->queue($team->id, 'event', ['event_id' => 'x']));
        $this->assertSame(0, MemoryOutboxRow::count());
        Queue::assertNothingPushed();
    }

    public function test_flush_sends_in_order_and_marks_sent(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake(['memory.test/api/intelligence/*' => Http::response(['data' => ['id' => 1]], 201)]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'a', 'payload' => ['event_id' => 'a']]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'outcome', 'event_id' => 'b', 'payload' => ['event_id' => 'b']]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(\App\Services\Memory\MemoryClientFactory::class));

        $this->assertSame(0, MemoryOutboxRow::whereNull('sent_at')->count());
        $this->assertNotNull($connection->fresh()->last_write_at);
        $this->assertSame('verified', $connection->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_flush_backs_off_when_memory_is_unavailable_and_stops_for_the_team(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake(['memory.test/*' => Http::response('', 503)]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'a', 'payload' => []]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'b', 'payload' => []]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(\App\Services\Memory\MemoryClientFactory::class));

        $first = MemoryOutboxRow::where('event_id', 'a')->first();
        $this->assertSame(1, $first->attempts);
        $this->assertTrue($first->next_attempt_at->gt(now()));
        $this->assertStringContainsString('503', (string) $first->last_error);
        $this->assertSame(0, MemoryOutboxRow::where('event_id', 'b')->value('attempts'));
        $this->assertSame('unreachable', $connection->fresh()->status);
        Http::assertSentCount(1);
    }

    public function test_flush_gives_up_on_a_refused_payload_and_marks_credentials_invalid_on_401(): void
    {
        $connection = MemoryConnection::factory()->create();
        Http::fake([
            'memory.test/api/intelligence/actions' => Http::response(['errors' => ['execution_status' => ['invalid']]], 422),
            'memory.test/api/intelligence/events' => Http::response(['message' => 'Unauthenticated.'], 401),
        ]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'action', 'event_id' => 'a', 'payload' => []]);
        MemoryOutboxRow::create(['team_id' => $connection->team_id, 'stage' => 'event', 'event_id' => 'b', 'payload' => []]);

        (new FlushMemoryOutbox($connection->team_id))->handle(app(\App\Services\Memory\MemoryClientFactory::class));

        $this->assertSame(MemoryOutboxRow::MAX_ATTEMPTS, MemoryOutboxRow::where('event_id', 'a')->value('attempts'));
        $this->assertSame('credential_invalid', $connection->fresh()->status);
        $this->assertNull(MemoryOutboxRow::where('event_id', 'b')->value('sent_at'));
    }

    public function test_prune_removes_old_sent_rows_only(): void
    {
        $team = Team::factory()->create();
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'old', 'payload' => [], 'sent_at' => now()->subDays(40)]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'new', 'payload' => [], 'sent_at' => now()->subDays(2)]);
        MemoryOutboxRow::create(['team_id' => $team->id, 'stage' => 'event', 'event_id' => 'stuck', 'payload' => [], 'attempts' => 20, 'created_at' => now()->subDays(40)]);

        $this->artisan('memory:prune')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['new', 'stuck'], MemoryOutboxRow::pluck('event_id')->all());
    }
}
```

- [ ] **Step 2: Implementation**

`app/Services/Memory/MemoryRecorder.php`:

```php
<?php

namespace App\Services\Memory;

use App\Jobs\FlushMemoryOutbox;
use App\Models\MemoryOutboxRow;

/**
 * The only way a record reaches Dot.Memory: into the local outbox in the
 * caller's transaction, sent later by FlushMemoryOutbox. Never throws
 * towards an operator.
 */
class MemoryRecorder
{
    public function __construct(private readonly MemoryClientFactory $clients) {}

    /** @param array<string, mixed> $payload */
    public function queue(int $teamId, string $stage, array $payload): ?MemoryOutboxRow
    {
        $connection = $this->clients->connectionFor($teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return null;
        }

        $eventId = isset($payload['event_id']) ? (string) $payload['event_id'] : null;

        if ($eventId !== null) {
            $existing = MemoryOutboxRow::where('team_id', $teamId)->where('event_id', $eventId)->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        $row = MemoryOutboxRow::create(['team_id' => $teamId, 'stage' => $stage, 'event_id' => $eventId, 'payload' => $payload]);

        FlushMemoryOutbox::dispatch($teamId)->afterCommit();

        return $row;
    }
}
```

`app/Jobs/FlushMemoryOutbox.php`:

```php
<?php

namespace App\Jobs;

use App\Models\MemoryOutboxRow;
use App\Services\Memory\Exceptions\MemoryCredentialRejected;
use App\Services\Memory\Exceptions\MemoryRefused;
use App\Services\Memory\Exceptions\MemoryUnavailable;
use App\Services\Memory\MemoryClientFactory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Sends a team's pending Dot.Memory records in order. One failure stops
 * the run for that team: an unreachable Memory backs the row off, a
 * refused payload is given up on (kept, visible on Health), a rejected
 * credential disables the connection until an administrator fixes it.
 */
class FlushMemoryOutbox implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $teamId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('memory-outbox:'.$this->teamId))->dontRelease()];
    }

    public function handle(MemoryClientFactory $clients): void
    {
        $connection = $clients->connectionFor($this->teamId);

        if ($connection === null || ! $connection->isUsable()) {
            return;
        }

        $client = $clients->for($connection);

        foreach (MemoryOutboxRow::where('team_id', $this->teamId)->due()->limit(200)->get() as $row) {
            try {
                match ($row->stage) {
                    'incident' => $client->storeIncident($row->payload),
                    'incident_update' => $client->updateIncident((string) ($row->payload['incident_uid'] ?? ''), $row->payload),
                    default => $client->record($row->stage, $row->payload),
                };
                $row->forceFill(['sent_at' => now(), 'last_error' => null])->save();
                $connection->forceFill(['status' => 'verified', 'last_write_at' => now(), 'last_error' => null])->save();
            } catch (MemoryCredentialRejected $e) {
                $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => $e->getMessage(), 'next_attempt_at' => $row->backoff()])->save();
                $connection->forceFill(['status' => 'credential_invalid', 'last_error' => $e->getMessage()])->save();

                return;
            } catch (MemoryRefused $e) {
                $row->forceFill(['attempts' => MemoryOutboxRow::MAX_ATTEMPTS, 'last_error' => $e->getMessage()])->save();
            } catch (MemoryUnavailable $e) {
                $row->forceFill(['attempts' => $row->attempts + 1, 'last_error' => $e->getMessage()])->save();
                $row->forceFill(['next_attempt_at' => $row->backoff()])->save();
                $connection->forceFill(['status' => 'unreachable', 'last_error' => $e->getMessage()])->save();

                return;
            }
        }
    }
}
```

`memory:flush` command: for each `team_id` with due rows (`MemoryOutboxRow::due()->distinct()->pluck('team_id')`), `FlushMemoryOutbox::dispatch($teamId)`; print the count. `memory:prune`: delete rows where `sent_at < now()->subDays(config('memory.outbox_retention_days'))`; print the count.

`routes/console.php` additions:

```php
// Dot.Memory: send what the outbox holds (backstop for the after-commit
// dispatch), prune sent rows, report platform incidents.
Schedule::command(FlushMemoryOutboxes::class)->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command(PruneMemoryOutbox::class)->dailyAt('03:10')->onOneServer();
```

- [ ] **Step 3: Run** the test file → PASS; commit `"Memory: outbox recorder, flush job with backoff, prune"`.

### Task 4: Mapping the audit log and the mirror hooks to loop records

**Files:**
- Create: `app/Services/Memory/LoopSubject.php`, `app/Services/Memory/AuditLoopMapper.php`
- Modify: `app/Services/Audits.php` (call the recorder after the row exists), `app/Services/AlertWorkflow.php` (add a `push` change key), `app/Services/Mines/MinesSynchroniser.php` (observation on a newly mirrored alert; outcome when Dot.Mines clears alerts), `app/Services/Lineup/LineupService.php` (refused assignment)
- Test: `tests/Feature/Memory/AuditLoopMapperTest.php`, `tests/Feature/Memory/MirrorHooksTest.php`

**Interfaces:**
- Produces: `LoopSubject::for(Model $model): array{type:string,id:string,label:?string}`; `AuditLoopMapper::map(AuditEntry $entry): list<array{stage:string,payload:array<string,mixed>}>`; `MemoryRecorder::fromAudit(AuditEntry $entry): void`, `MemoryRecorder::observeAlert(Alert $alert): void`, `MemoryRecorder::alertCleared(Alert $alert, string $by): void`, `MemoryRecorder::assignmentRefused(Shift $shift, Equipment $unit, Operator $operator, array $blockers, ?User $user): void`.

- [ ] **Step 1: Failing tests** (`AuditLoopMapperTest`: one method per mapping row; build the audit entry by performing the real service call with `Queue::fake()` and `MemoryConnection::factory()->create(['team_id' => $team->id])`, then assert on `MemoryOutboxRow` rows: stage, `payload['signature']`, `payload['subject_type']`, decision/action/outcome fields). Rows to cover: exception raised (observation via `OperationalException::create` with `raised_by` SYSTEM), explained (decision with confidence 1.0, risk 0.9 for CRITICAL, autonomy approve), approved (action approval approved), rejected, resolved (outcome improved), dismissed (outcome inconclusive); alert acknowledge pushed (action succeeded), acknowledge refused (action refused), resolve (action + outcome improved with minutes), escalate, assign; downtime classified (decision + action); line-up confirmed (decision + action with counts); operator assignment pushed / not pushed; shift started (observation), closed with and without a previous shift (outcome verdict), approved, reopened. `MirrorHooksTest`: `applyAlert` with a new resource queues an observation with `subject_type` equipment and `subject_id` the Dot.Mines machine id; applying the same alert again queues nothing; the sync clearing an alert queues an outcome with `measure` `alert.time_to_clear_minutes`; `LineupService::assignOperator` refused by Dot.Mines (`Http::fake` 422) queues an action with `execution_status` refused and the blockers in `detail`.

- [ ] **Step 2: `LoopSubject`**

```php
<?php

namespace App\Services\Memory;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\Equipment;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * One subject vocabulary shared with Dot.Mines and the guardian: a machine
 * is `equipment` with the Dot.Mines machine id, so Memory's context for it
 * is cross-platform. Anything without a machine belongs to its room; shifts
 * and line-ups are `shift` subjects.
 */
final class LoopSubject
{
    /** @return array{type:string,id:string,label:?string} */
    public static function for(Model $model): array
    {
        $equipment = match (true) {
            $model instanceof Alert, $model instanceof OperationalException, $model instanceof DowntimeClassification, $model instanceof ShiftOperatorAssignment => $model->equipment()->withoutGlobalScopes()->first(),
            default => null,
        };

        if ($equipment instanceof Equipment) {
            return ['type' => 'equipment', 'id' => (string) $equipment->mines_machine_id, 'label' => $equipment->name];
        }

        if ($model instanceof Shift) {
            return ['type' => 'shift', 'id' => (string) $model->id, 'label' => $model->label()];
        }

        if ($model instanceof ShiftLineup) {
            $shift = $model->shift()->withoutGlobalScopes()->first();

            return ['type' => 'shift', 'id' => (string) $model->shift_id, 'label' => $shift?->label()];
        }

        $roomId = $model instanceof ControlRoom ? $model->id : ($model->getAttribute('control_room_id') ?? null);
        $room = $model instanceof ControlRoom ? $model : ($roomId !== null ? ControlRoom::withoutGlobalScopes()->find($roomId) : null);

        return ['type' => 'control_room', 'id' => (string) ($roomId ?? 0), 'label' => $room?->name];
    }
}
```

- [ ] **Step 3: `AuditLoopMapper`** (the table in the spec, as code; `base()` builds the shared fields; each branch returns zero or more `[stage, payload]` pairs; `event_id` = `dot-central:audit:<id>` with a `:decision` / `:action` / `:outcome` suffix when one audit row yields two records; `loop_id` = `dot-central:<entity>:<id>`; risk by severity; shift outcome compares `stoppage_minutes` sums).

```php
<?php

namespace App\Services\Memory;

use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\DowntimeClassification;
use App\Models\DowntimeReason;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftOperatorAssignment;
use Illuminate\Database\Eloquent\Model;

/**
 * Pure translation of one audit row into intelligence-loop records
 * (spec §5). Nothing here is typed by anyone: the decision text is the
 * operator's own reason or explanation, the verdicts are computed.
 */
final class AuditLoopMapper
{
    private const RISK = ['critical' => 0.9, 'warning' => 0.5, 'review' => 0.2, 'info' => 0.1];

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    public function map(AuditEntry $entry): array
    {
        $subject = $this->subject($entry);

        if ($subject === null || $entry->team_id === null) {
            return [];
        }

        return match (true) {
            $subject instanceof OperationalException => $this->exception($entry, $subject),
            $subject instanceof Alert => $this->alert($entry, $subject),
            $subject instanceof DowntimeClassification => $this->downtime($entry, $subject),
            $subject instanceof ShiftLineup => $this->lineup($entry, $subject),
            $subject instanceof ShiftOperatorAssignment => $this->assignment($entry, $subject),
            $subject instanceof Shift => $this->shift($entry, $subject),
            default => [],
        };
    }

    private function subject(AuditEntry $entry): ?Model
    {
        $class = $entry->entity_type;

        if ($entry->entity_id === null || ! class_exists($class) || ! is_subclass_of($class, Model::class)) {
            return null;
        }

        return $class::withoutGlobalScopes()->find($entry->entity_id);
    }

    /** @return array<string, mixed> */
    private function base(AuditEntry $entry, Model $model, string $loopKey, string $signature, string $suffix = ''): array
    {
        $subject = LoopSubject::for($model);

        return [
            'loop_id' => 'dot-central:'.$loopKey,
            'event_id' => 'dot-central:audit:'.$entry->id.$suffix,
            'source' => 'dot-central-audit',
            'subject_type' => $subject['type'],
            'subject_id' => $subject['id'],
            'subject_label' => $subject['label'],
            'team_id' => $entry->team_id,
            'user_id' => $entry->user_id,
            'occurred_at' => $entry->occurred_at->toIso8601String(),
            'signature' => $signature,
            'detail' => ['audit_id' => $entry->id, 'control_room_id' => $entry->control_room_id, 'shift_id' => $entry->shift_id, 'reason' => $entry->reason],
        ];
    }

    private function newValue(AuditEntry $entry, string $key): mixed
    {
        return $entry->changes[$key]['new'] ?? null;
    }

    private function has(AuditEntry $entry, string $key): bool
    {
        return is_array($entry->changes) && array_key_exists($key, $entry->changes);
    }

    private function risk(string $severity): float
    {
        return self::RISK[strtolower($severity)] ?? 0.5;
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function exception(AuditEntry $entry, OperationalException $e): array
    {
        $signature = 'dot-central:exception:'.$e->rule_code;
        $base = fn (string $suffix = '') => $this->base($entry, $e, 'exception:'.$e->id, $signature, $suffix);
        $status = $this->newValue($entry, 'status');

        if ($entry->action === 'created') {
            return [['stage' => 'event', 'payload' => $base() + ['detail' => $base()['detail'] + ['title' => $e->title, 'message' => $e->message, 'outcome' => $e->outcome, 'severity' => $e->severity, 'raised_by' => $e->raised_by]]]];
        }

        if ($status === 'EXPLAINED') {
            return [['stage' => 'decision', 'payload' => $base() + ['recommendation' => (string) ($entry->reason ?? $e->explanation ?? 'explained'), 'confidence' => 1.0, 'risk' => $this->risk($e->severity), 'autonomy_level' => 'approve', 'requires_approval' => true]]];
        }

        if (in_array($entry->action, ['approve', 'reject'], true) && in_array($status, ['APPROVED', 'REJECTED'], true)) {
            $approved = $status === 'APPROVED';

            return [['stage' => 'action', 'payload' => $base() + ['action_kind' => $approved ? 'exception.approve' : 'exception.reject', 'executor_platform' => 'dot-central', 'approval_status' => $approved ? 'approved' : 'rejected', 'execution_status' => 'succeeded']]];
        }

        if ($status === 'RESOLVED') {
            return [['stage' => 'outcome', 'payload' => $base() + ['verdict' => 'improved', 'measure' => 'exception.status', 'value_before' => 'OPEN', 'value_after' => 'RESOLVED', 'notes' => $entry->reason]]];
        }

        if ($status === 'DISMISSED') {
            return [['stage' => 'outcome', 'payload' => $base() + ['verdict' => 'inconclusive', 'measure' => 'exception.status', 'value_before' => 'OPEN', 'value_after' => 'DISMISSED', 'notes' => $entry->reason]]];
        }

        return [];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function alert(AuditEntry $entry, Alert $a): array
    {
        if ($a->source === 'system') {
            return [];
        }

        $signature = 'dot-central:alert:'.strtolower($a->severity);
        $base = fn (string $suffix = '') => $this->base($entry, $a, 'alert:'.$a->id, $signature, $suffix);
        $push = $this->newValue($entry, 'push');
        $execution = match ($push) { 'pushed' => 'succeeded', 'refused' => 'refused', default => 'pending' };
        $action = fn (string $kind) => ['stage' => 'action', 'payload' => $base(':action') + ['action_kind' => $kind, 'executor_platform' => $push === 'pushed' ? 'dot-mines' : 'dot-central', 'approval_status' => 'not_required', 'execution_status' => $execution]];

        if ($entry->action === 'ack') {
            return [$action('alert.acknowledge')];
        }

        if ($this->has($entry, 'cleared_at')) {
            $minutes = $a->cleared_at !== null ? max(0, (int) round(($a->source_triggered_at ?? $a->triggered_at)->diffInMinutes($a->cleared_at))) : null;

            return [$action('alert.resolve'), ['stage' => 'outcome', 'payload' => $base(':outcome') + ['verdict' => 'improved', 'measure' => 'alert.time_to_clear_minutes', 'value_before' => null, 'value_after' => $minutes, 'notes' => $entry->reason]]];
        }

        if ($this->has($entry, 'escalation_level')) {
            return [$action('alert.escalate')];
        }

        if ($this->has($entry, 'assigned_to')) {
            return [$action('alert.assign')];
        }

        return [];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function downtime(AuditEntry $entry, DowntimeClassification $d): array
    {
        if ($entry->action !== 'created') {
            return [];
        }

        $reason = DowntimeReason::withoutGlobalScopes()->find($d->downtime_reason_id);
        $signature = 'dot-central:downtime:'.($reason?->code ?? 'unknown');
        $detail = ['reason' => $reason?->name, 'category' => $reason?->category, 'counts_against_availability' => (bool) $reason?->counts_against_availability, 'note' => $d->note, 'started_at' => $d->started_at->toIso8601String(), 'ended_at' => $d->ended_at?->toIso8601String()];

        return [
            ['stage' => 'decision', 'payload' => $this->base($entry, $d, 'downtime:'.$d->id, $signature, ':decision') + ['recommendation' => (string) ($reason?->name ?? 'classified'), 'confidence' => 1.0, 'risk' => $reason?->counts_against_availability ? 0.5 : 0.2, 'autonomy_level' => 'execute', 'requires_approval' => false, 'detail' => $detail]],
            ['stage' => 'action', 'payload' => $this->base($entry, $d, 'downtime:'.$d->id, $signature, ':action') + ['action_kind' => 'downtime.classify', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded', 'detail' => $detail]],
        ];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function lineup(AuditEntry $entry, ShiftLineup $l): array
    {
        if ($entry->action !== 'approve') {
            return [];
        }

        $version = (int) ($this->newValue($entry, 'version') ?? $l->version);
        $faces = $l->shift()->withoutGlobalScopes()->first()?->faces()->withoutGlobalScopes()->whereNull('closed_at')->count() ?? 0;
        $placed = \App\Models\LineupAllocation::withoutGlobalScopes()->where('shift_lineup_id', $l->id)->whereNull('released_at')->count();
        $standby = \App\Models\LineupStandby::withoutGlobalScopes()->where('shift_lineup_id', $l->id)->whereNull('ended_at')->count();
        $detail = ['version' => $version, 'faces' => $faces, 'placed' => $placed, 'standby' => $standby];
        $signature = 'dot-central:lineup:confirmed';

        return [
            ['stage' => 'decision', 'payload' => $this->base($entry, $l, 'lineup:'.$l->id, $signature, ':decision') + ['recommendation' => 'line-up v'.$version, 'confidence' => 1.0, 'risk' => 0.3, 'autonomy_level' => 'execute', 'requires_approval' => false, 'detail' => $detail]],
            ['stage' => 'action', 'payload' => $this->base($entry, $l, 'lineup:'.$l->id, $signature, ':action') + ['action_kind' => 'lineup.confirm', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded', 'detail' => $detail]],
        ];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function assignment(AuditEntry $entry, ShiftOperatorAssignment $s): array
    {
        if ($entry->action !== 'created') {
            return [];
        }

        return [['stage' => 'action', 'payload' => $this->base($entry, $s, 'shift:'.$s->shift_id, 'dot-central:operator:assign') + ['action_kind' => 'operator.assign', 'executor_platform' => $s->pushed_to_source ? 'dot-mines' : 'dot-central', 'approval_status' => 'not_required', 'execution_status' => $s->pushed_to_source ? 'succeeded' : 'pending', 'detail' => ['push_note' => $s->push_note, 'operator_id' => $s->operator_id, 'shift_id' => $s->shift_id]]]];
    }

    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function shift(AuditEntry $entry, Shift $shift): array
    {
        $status = $this->newValue($entry, 'status');
        $base = fn (string $sig) => $this->base($entry, $shift, 'shift:'.$shift->id, $sig);

        if ($status === 'OPEN') {
            return [['stage' => 'event', 'payload' => $base('dot-central:shift:started')]];
        }

        if ($status === 'CLOSED') {
            $after = $this->stoppageHours($shift);
            $previous = Shift::withoutGlobalScopes()->where('control_room_id', $shift->control_room_id)->whereIn('status', ['CLOSED', 'APPROVED'])->where('id', '!=', $shift->id)->where('starts_at', '<', $shift->starts_at)->orderByDesc('starts_at')->first();
            $before = $previous === null ? null : $this->stoppageHours($previous);
            $verdict = match (true) { $before === null => 'inconclusive', $after < $before => 'improved', $after > $before => 'worsened', default => 'unchanged' };

            return [['stage' => 'outcome', 'payload' => $base('dot-central:shift:closed') + ['verdict' => $verdict, 'measure' => 'shift.stoppage_hours', 'value_before' => $before, 'value_after' => $after, 'notes' => $entry->reason]]];
        }

        if ($status === 'APPROVED') {
            return [['stage' => 'action', 'payload' => $base('dot-central:shift:approved') + ['action_kind' => 'shift.approve', 'executor_platform' => 'dot-central', 'approval_status' => 'approved', 'execution_status' => 'succeeded']]];
        }

        if ($status === 'REOPENED') {
            return [['stage' => 'action', 'payload' => $base('dot-central:shift:reopened') + ['action_kind' => 'shift.reopen', 'executor_platform' => 'dot-central', 'approval_status' => 'approved', 'execution_status' => 'succeeded']]];
        }

        return [];
    }

    private function stoppageHours(Shift $shift): float
    {
        return round(((int) $shift->unitSummaries()->sum('stoppage_minutes')) / 60, 2);
    }
}
```

- [ ] **Step 4: Recorder methods and hooks**

Add to `MemoryRecorder`:

```php
    public function fromAudit(AuditEntry $entry): void
    {
        if ($entry->team_id === null) {
            return;
        }

        try {
            foreach ($this->mapper->map($entry) as $record) {
                $this->queue($entry->team_id, $record['stage'], $record['payload']);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public function observeAlert(Alert $alert): void
    {
        $teamId = $this->teamOf($alert);
        if ($teamId === null || $alert->source === 'system') {
            return;
        }
        $subject = LoopSubject::for($alert);
        $this->queue($teamId, 'event', [
            'loop_id' => 'dot-central:alert:'.$alert->id, 'event_id' => 'dot-central:alert:'.$alert->id.':triggered', 'source' => 'dot-mines-mirror',
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'subject_label' => $subject['label'], 'team_id' => $teamId, 'user_id' => null,
            'occurred_at' => ($alert->source_triggered_at ?? $alert->triggered_at)->toIso8601String(), 'signature' => 'dot-central:alert:'.strtolower($alert->severity),
            'detail' => ['title' => $alert->title, 'description' => $alert->description, 'severity' => $alert->severity, 'priority' => $alert->priority, 'source' => $alert->source, 'external_id' => $alert->external_id, 'control_room_id' => $alert->control_room_id],
        ]);
    }

    public function alertCleared(Alert $alert, string $by): void
    {
        $teamId = $this->teamOf($alert);
        if ($teamId === null || $alert->source === 'system' || $alert->cleared_at === null) {
            return;
        }
        $subject = LoopSubject::for($alert);
        $minutes = max(0, (int) round(($alert->source_triggered_at ?? $alert->triggered_at)->diffInMinutes($alert->cleared_at)));
        $this->queue($teamId, 'outcome', [
            'loop_id' => 'dot-central:alert:'.$alert->id, 'event_id' => 'dot-central:alert:'.$alert->id.':cleared', 'source' => 'dot-mines-mirror',
            'subject_type' => $subject['type'], 'subject_id' => $subject['id'], 'subject_label' => $subject['label'], 'team_id' => $teamId, 'user_id' => null,
            'occurred_at' => $alert->cleared_at->toIso8601String(), 'signature' => 'dot-central:alert:'.strtolower($alert->severity),
            'verdict' => 'improved', 'measure' => 'alert.time_to_clear_minutes', 'value_before' => null, 'value_after' => $minutes, 'notes' => 'cleared by '.$by,
            'detail' => ['control_room_id' => $alert->control_room_id],
        ]);
    }

    /** @param list<string> $blockers */
    public function assignmentRefused(Shift $shift, Equipment $unit, Operator $operator, array $blockers, ?User $user): void
    {
        $teamId = $this->teamOf($shift);
        if ($teamId === null) {
            return;
        }
        $this->queue($teamId, 'action', [
            'loop_id' => 'dot-central:shift:'.$shift->id, 'event_id' => 'dot-central:assignment-refused:'.$shift->id.':'.$unit->id.':'.$operator->id.':'.now()->timestamp, 'source' => 'dot-central-lineup',
            'subject_type' => 'equipment', 'subject_id' => (string) $unit->mines_machine_id, 'subject_label' => $unit->name, 'team_id' => $teamId, 'user_id' => $user?->id,
            'occurred_at' => now()->toIso8601String(), 'signature' => 'dot-central:operator:assign',
            'action_kind' => 'operator.assign', 'executor_platform' => 'dot-mines', 'approval_status' => 'not_required', 'execution_status' => 'refused',
            'detail' => ['blockers' => $blockers, 'operator_id' => $operator->id, 'shift_id' => $shift->id],
        ]);
    }

    private function teamOf(Model $model): ?int
    {
        $roomId = $model->getAttribute('control_room_id');
        $teamId = $roomId === null ? null : ControlRoom::withoutGlobalScopes()->where('id', $roomId)->value('team_id');

        return $teamId === null ? null : (int) $teamId;
    }
```

(constructor becomes `__construct(private readonly MemoryClientFactory $clients, private readonly AuditLoopMapper $mapper)`.)

`Audits::record`: after `AuditEntry::query()->create([...])` assign to `$entry`, call `app(MemoryRecorder::class)->fromAudit($entry);` and return `$entry`. `AlertWorkflow`: in `acknowledge` and `resolve`, add `'push' => ['old' => null, 'new' => $outcome->requested ? ($outcome->pushed ? 'pushed' : 'refused') : 'not_requested']` to the changes array. `MinesSynchroniser::applyAlert`: after `$alert->save()`, `if ($alert->wasRecentlyCreated) { app(MemoryRecorder::class)->observeAlert($alert); }`; the "no longer listed as active" block becomes a loop: fetch the alerts, set `cleared_at`, save, `alertCleared($alert, 'dot-mines')`. `LineupService::pushAssignment`: in the `MinesRefused` catch, before refusing, `app(MemoryRecorder::class)->assignmentRefused($shift, $unit, $operator, $e->messages, Auth::user())`.

- [ ] **Step 5: Run** both test files and the full suite → PASS; commit `"Memory: map the audit log and the mirror into loop records"`.

### Task 5: Health card and administrator configuration

**Files:**
- Create: `app/Http/Controllers/MemoryConnectionController.php`
- Modify: `routes/web.php` (`POST /health/memory` → `update`, `POST /health/memory/test` → `test`, names `health.memory.update`, `health.memory.test`), `app/Http/Controllers/IntegrationHealthController.php` (pass `memory` data), `resources/views/health/index.blade.php` (card)
- Test: `tests/Feature/Memory/MemoryHealthCardTest.php`

- [ ] **Step 1: Failing tests**: an administrator sees the card with "not connected" and the form; saving base URL and token creates the connection (token never in the response body); Test credential with `Http::fake` 200 marks `verified` and shows the Memory user's name in the status flash; 401 marks `credential_invalid` and flashes the error; a supervisor sees the card's readouts but no form and gets 403 on POST; readouts show outbox pending/failed/stuck counts and open incidents.

- [ ] **Step 2: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\MemoryConnection;
use App\Models\Team;
use App\Services\Audits;
use App\Services\Memory\Exceptions\MemoryException;
use App\Services\Memory\MemoryClientFactory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class MemoryConnectionController extends Controller
{
    public function update(Request $request, Audits $audits): RedirectResponse
    {
        $this->authorize('act-on-health');
        $team = $request->user()->currentTeam;
        abort_unless($team instanceof Team, 403);

        $validated = $request->validate([
            'base_url' => ['required', 'url', 'max:255'],
            'api_token' => ['nullable', 'string', 'max:2000'],
            'enabled' => ['nullable', 'boolean'],
        ]);

        $connection = MemoryConnection::withoutGlobalScopes()->firstOrNew(['team_id' => $team->id]);
        $attributes = ['base_url' => $validated['base_url'], 'enabled' => (bool) ($validated['enabled'] ?? false)];
        if (($validated['api_token'] ?? '') !== '') {
            $attributes['api_token'] = $validated['api_token'];
            $attributes['status'] = 'unverified';
        }
        $connection->fill($attributes)->save();

        return redirect(route('health.index').'#memory')->with('status', 'Dot.Memory connection saved.');
    }

    public function test(Request $request, MemoryClientFactory $clients): RedirectResponse
    {
        $this->authorize('act-on-health');
        $team = $request->user()->currentTeam;
        $connection = $team instanceof Team ? $clients->connectionFor($team) : null;

        if ($connection === null || $connection->api_token === null) {
            return redirect(route('health.index').'#memory')->with('error', 'No Dot.Memory token to test.');
        }

        try {
            $me = $clients->for($connection)->whoAmI();
            $connection->forceFill(['status' => 'verified', 'last_verified_at' => now(), 'last_error' => null])->save();

            return redirect(route('health.index').'#memory')->with('status', 'Dot.Memory accepted the credential ('.($me['name'] !== '' ? $me['name'] : 'user '.$me['id']).').');
        } catch (MemoryException $e) {
            $connection->forceFill(['status' => $e instanceof \App\Services\Memory\Exceptions\MemoryCredentialRejected ? 'credential_invalid' : 'unreachable', 'last_error' => $e->getMessage()])->save();

            return redirect(route('health.index').'#memory')->with('error', $e->getMessage());
        }
    }
}
```

`IntegrationHealthController::index` adds to the view data: `'memory' => $this->memory($request->user()->currentTeam)` returning `['connection' => ?MemoryConnection, 'pending' => int, 'failed' => int (attempts > 0 and not sent), 'stuck' => int (attempts >= MAX and not sent), 'open_incidents' => int]`.

- [ ] **Step 3: Card** in `health/index.blade.php`, placed after the flash messages, `id="memory"`, a `dot-panel dot-panel--flush` titled "Dot.Memory" with a status lamp (verified → ok, unreachable → warn, credential_invalid → bad, none → neutral), a `dot-panels dot-panels--5` row of readouts (Status, Last write, Outbox pending, Outbox failed / stuck, Open incidents, Last recall), and, `@can('act-on-health')`, the form (`base_url` input, `api_token` password input labelled "API token (leave blank to keep)", `enabled` checkbox, Save button) and a Test credential form. Below the readouts a one-line explanation in the control-room language: "Decisions, actions and outcomes from this control room are remembered here; alerts and exceptions show what was tried before."

- [ ] **Step 4: Run** the test file and the health tests → PASS; commit `"Memory: connection card and configuration on the Health page"`.

### Task 6: Recall on alert and exception cards

**Files:**
- Create: `app/Services/Memory/MemoryContextService.php`, `app/Livewire/MemoryContext.php`, `resources/views/livewire/memory-context.blade.php`, `resources/views/livewire/memory-context-placeholder.blade.php`
- Modify: `resources/views/alerts/_card.blade.php`, `resources/views/exceptions/_card.blade.php`, `resources/views/control-rooms/equipment-show.blade.php` (or the equipment page view; verify the path with `grep -rl "equipment" resources/views/control-rooms`)
- Test: `tests/Feature/Memory/MemoryContextTest.php`

**Interfaces:**
- Produces: `MemoryContextService::for(int $teamId, string $subjectType, string $subjectId, ?string $signature): array{state:'unconfigured'|'none'|'known'|'unreachable', seen:int, first_seen:?string, last_seen:?string, tried:list<array{kind:string,verdict:?string,at:?string}>, worked:?string, fetched_at:?string}`.

- [ ] **Step 1: Failing tests**: without a connection the component renders nothing (state unconfigured); with `Http::fake` returning `known=false` it renders "No history in Dot.Memory"; with a known context it renders "Seen before: 4 times since <date>", the tried actions with verdicts and "Worked best: alert.resolve"; a 503 renders "Dot.Memory unreachable"; a second render within ten minutes does not call Memory again (`Http::assertSentCount(1)`); `last_context_at` is set on success.

- [ ] **Step 2: Service** (`Cache::remember` key `memory-context:{team}:{type}:{id}:{signature}` for `config('memory.context_cache_minutes')` minutes; unreachable results cached 2 minutes; client built with `config('memory.context_timeout')`): maps Memory's `known`, `observations`, `first_seen`, `last_seen`, `what_was_tried` (`action_kind`, `verdict`, `at`), `what_worked` (first entry's `action_kind` when present) into the array above; catches `MemoryException` → `unreachable`.

- [ ] **Step 3: Component** (`#[Lazy]`, props `subjectType`, `subjectId`, `signature`; `placeholder()` returns a one-line muted "Memory…"; `render()` calls the service with `auth()->user()->currentTeam->id`). View: a `div` with class `dot-msg__fix` style muted text, no icons, no spinner: "Memory: seen before 4 times since 12 Aug · tried acknowledge (improved), resolve (improved) · worked best: resolve" / "Memory: no history for this machine" / "Memory: Dot.Memory unreachable, last fetched 14:02". Include under the card footers: `<livewire:memory-context :subject-type="..." :subject-id="..." :signature="..." lazy />` with the subject from `LoopSubject::for($a)` and signature `dot-central:alert:<severity>` (alerts) / `dot-central:exception:<rule_code>` (exceptions) / no signature (equipment page). Only render when `MemoryConnection` exists for the team (`@if($memoryEnabled)` passed from the controllers via a view composer `View::composer(['alerts.*', 'exceptions.*', 'control-rooms.*'], ...)` that sets `$memoryEnabled`).

- [ ] **Step 4: Run** the test file, the alert and exception page tests → PASS; commit `"Memory: recall strip on alerts, exceptions and equipment"`.

### Task 7: Platform incident reporter

**Files:**
- Create: `app/Console/Commands/ReportMemoryIncidents.php` (`memory:report-incidents`), `app/Services/Memory/IncidentReporter.php`
- Modify: `routes/console.php` (every five minutes, `withoutOverlapping`, `onOneServer`)
- Test: `tests/Feature/Memory/IncidentReporterTest.php`

**Interfaces:**
- Produces: `IncidentReporter::evaluate(int $teamId): array{opened:int,resolved:int}`; conditions from the spec §7 as methods returning `list<array{signature:string,component:string,severity:string,detail:array}>`.

- [ ] **Step 1: Failing tests**: a credential-invalid Dot.Mines connection opens one `MemoryIncident` (sev2, uid `dot-central:<team>:mines_connection:credential_invalid:mines_connection:<id>:<date>`) and queues an `incident` outbox row with `status` open; running again opens nothing new; after the connection is verified again the incident is resolved and an `incident_update` row with `status` resolved is queued; three failed sync runs in a row open `mines_sync:failing`; two failed then one success does not; a queue heartbeat older than 15 minutes with a fresh scheduler heartbeat opens `queue:stalled` (sev1); outbox rows at MAX_ATTEMPTS open `memory_outbox:stuck` (sev3); nothing is evaluated for a team without a usable Memory connection.

- [ ] **Step 2: Implementation**: `IncidentReporter` loads the team's rooms' connections (`MinesConnection::withoutGlobalScopes()->whereIn('control_room_id', ...)`), their last three `syncRuns`, the two heartbeats from cache, and `MemoryOutboxRow` counts; computes the active condition set; for each active condition without an open `MemoryIncident` (same team, signature, component, status open) creates one and queues `incident` (`incident_uid`, `environment` = `app()->environment()`, `detection_source` = `central-health`, `signature`, `component`, `severity`, `status` open, `detected_at`, `record` detail); for each open incident whose condition is no longer active sets `status` resolved, `resolved_at`, and queues `incident_update` (`incident_uid`, `status` resolved, `resolved_at`). The command iterates `MemoryConnection::withoutGlobalScopes()->where('enabled', true)` teams and prints a line per team.

- [ ] **Step 3: Run** → PASS; commit `"Memory: report Dot.Central's own integration incidents"`.

### Task 8: Documents, gates, PR

**Files:**
- Create: `docs/dot-central/14-dot-memory-integration.md` (purpose, what Memory offers, the connection and how to mint the token on a Dot.Memory user, the mapping table from the spec §5, the recall strip, incidents, Health readouts, failure modes, what is not sent)
- Modify: `docs/dot-central/05-feature-matrix.md` (new row F-40 "Dot.Memory intelligence loop, recall and incidents", Done with test names), `wiki.md` (0.20.0 change-log row and a line in §1), `docs/dot-central/10-outstanding-issues.md` (I-33: Memory recall shows only Dot.Central's own records until Dot.Mines also writes to the loop; owner item)

- [ ] **Step 1**: write the documents; run `php artisan test --compact tests/Feature/ProhibitedReferencesTest.php`.
- [ ] **Step 2**: full gates: `php artisan test --compact && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G`; Impeccable detector on the Health page and an alert page rendered through a throwaway test (no new findings beyond the known baseline).
- [ ] **Step 3**: commit, push `feature/memory-integration`, open the PR with the owner steps: mint a Dot.Memory API token (Profile, API Tokens, on `memory.infodot.co.za`), paste it on Health, Test credential, act on an alert, see the record on Dot.Memory and the recall strip after the next view.
