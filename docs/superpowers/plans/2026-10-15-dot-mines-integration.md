# Dot.Mines Integration (Increment 2) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Dot.Central pulls real fleet data from Dot.Mines into a per-room equipment mirror with provenance and freshness states, receives signed webhooks for alerts and offline events, and shows integration health, with every failure mode visible and recoverable.

**Architecture:** A typed `MinesClient` over `/api/v1` (per `docs/dot-central/04-dot-mines-integration.md`), a queued `SyncMinesConnection` job driven by the scheduler, an `equipment` mirror table keyed by `(control_room_id, mines_machine_id)` with the three provider clocks, a pure `FreshnessEvaluator`, a signed `WebhookReceiver`, `integration_sync_runs` / `integration_webhook_deliveries` as the observability record, and a Health page. Alerts raised by Dot.Mines land in the existing `alerts` table with `source=dot_mines` and `external_id` dedupe.

**Tech Stack:** Laravel 13 HTTP client (`Http::fake` in tests), database queue, scheduler, PHPUnit, Pint, Larastan.

## Global Constraints
- No new Composer or npm dependencies.
- New code lives under existing base folders: `app/Services/Mines/`, `app/Jobs/` (Laravel-standard, created by `make:job`), `app/Console/Commands/`, `app/Http/Controllers/`, `app/Support/`.
- Never log, render or audit the API token or webhook secret.
- No customer names anywhere (`ProhibitedReferencesTest` guards).
- Mirrored rows are not `Auditable` (thousands per sync); each sync run writes one `import` audit row with counts.
- Missing values stay `NULL`; nothing is coerced to zero.
- Every task: tests first, `php artisan test --compact --filter=<Name>`, `vendor/bin/pint --dirty --format agent`, commit.

---

### Task 1: `MinesClient` with contract tests

**Files:**
- Create: `config/mines.php` (`sync_interval_minutes` 5, `page_size` 100, `request_timeout` 10, `hours_batch` 20, `webhook_skew_seconds` 300)
- Create: `app/Services/Mines/MinesClient.php`, `app/Services/Mines/MinesClientFactory.php`, `app/Services/Mines/Exceptions/MinesException.php`, `CredentialRejected.php`, `RateLimited.php`, `MinesUnavailable.php`, `MalformedResponse.php`
- Create: `tests/Unit/Services/Mines/MinesClientTest.php`
- Modify: `.env.example` (document that tokens live in `mines_connections`; no env keys needed)

**Interfaces (Produces):**
```php
final class MinesClient {
    public function __construct(private string $baseUrl, private string $token, private int $timeout = 10) {}
    /** @return array{version:int, server_time:string, changes:array<string,list<array<string,mixed>>>, deleted:list<array{scope:string,id:int}>, has_more:bool} */
    public function sync(int $since, array $scopes): array;
    /** @return array{data:list<array<string,mixed>>, meta:array{current_page:int,last_page:int,total:int}} */
    public function machines(int $page = 1, int $perPage = 100): array;
    /** @return array<string,mixed> MachineResource fields */
    public function machine(int $id): array;
    /** @return array<string,mixed>|null newest machine_metrics row */
    public function latestMetric(int $machineId): ?array;
    /** @return array{data:list<array<string,mixed>>, meta:array{current_page:int,last_page:int,total:int}} */
    public function activeAlerts(int $page = 1, int $perPage = 100): array;
    public function acknowledgeAlert(int $id): array;
    public function resolveAlert(int $id): array;
    /** @return array{id:int, name:string, email:string, current_team_id:int|null} */
    public function whoAmI(): array;
}
final class MinesClientFactory { public function for(MinesConnection $c): MinesClient; }
```
Behaviour: `Authorization: Bearer`, `Accept: application/json`, `X-Requested-With`, no redirects, timeout from config; 401/403 → `CredentialRejected`; 429 → `RateLimited` with `retryAfter` from body or header (default 60); 5xx/connection error → `MinesUnavailable`; non-JSON or missing required keys → `MalformedResponse`. The token never appears in exception messages.

- [ ] Step 1: failing tests: each method hits the documented path with the bearer header (`Http::assertSent`), parses the documented shape, and each error class is thrown for its status; a `MalformedResponse` for an HTML body; `RateLimited::$retryAfter` read from `retry_after`.
- [ ] Step 2: run, FAIL. Step 3: implement. Step 4: PASS. Step 5: commit `feat: Dot.Mines API client with contract tests`.

---

### Task 2: Equipment mirror and sync-run tables

**Files:**
- Create migrations: `create_equipment_table`, `create_equipment_hour_readings_table`, `create_integration_sync_runs_table`, `add_source_columns_to_alerts_table`
- Create models: `App\Models\Equipment`, `EquipmentHourReading`, `IntegrationSyncRun`; factories for each
- Modify: `app/Models/Alert.php` (fillable: `source`, `external_id`, `priority`, `equipment_id`, `metadata`, `source_triggered_at`; casts), `AlertFactory`
- Modify: `app/Models/ControlRoom.php` (`equipment()`, `syncRuns()` via connection), `MinesConnection.php` (`syncRuns()`)
- Create: `tests/Feature/EquipmentMirrorSchemaTest.php`

`equipment` columns: id, control_room_id FK, mines_machine_id uint, name, machine_type, model, manufacturer, serial_number, registration_number, mines_status (string: active|idle|maintenance|offline|unknown), allocation_state nullable, mine_area_id nullable, mine_area_name nullable, capacity decimal nullable, fuel_capacity decimal nullable, latitude/longitude decimal(10,7) nullable, location_recorded_at ts nullable, fuel_level decimal nullable, fuel_level_unit string nullable, engine_hours decimal nullable, engine_hours_recorded_at ts nullable, last_seen_at ts nullable (Dot.Mines ingest), last_heard_at ts nullable (max provider clock), sync_version ubigint nullable, raw json nullable, first_synced_at, last_synced_at, retired_at nullable, timestamps; unique (control_room_id, mines_machine_id); index (control_room_id, mines_status).
`equipment_hour_readings`: equipment_id FK, hours decimal, recorded_at ts, source string (`dot_mines.metrics`), ingested_at ts; unique (equipment_id, recorded_at).
`integration_sync_runs`: mines_connection_id FK, trigger (schedule|manual|webhook_reconcile), status (running|succeeded|partial|failed), started_at, finished_at, cursor_before, cursor_after, fetched_machines uint, upserted uint, retired uint, alerts_upserted uint, hours_updated uint, error_class, error_message (sanitised), timestamps; index (mines_connection_id, started_at).
`alerts` additions: `source` string default `manual`; `external_id` string nullable; `priority` string nullable; `equipment_id` FK nullable nullOnDelete; `metadata` json nullable; `source_triggered_at` ts nullable; unique (control_room_id, source, external_id).

- [ ] Step 1: schema test asserts columns/uniques; model relations. Step 2: FAIL. Step 3: migrations + models. Step 4: PASS; full suite. Step 5: commit `feat: equipment mirror, sync-run and alert-source schema`.

---

### Task 3: `FreshnessEvaluator` and `EquipmentState`

**Files:**
- Create: `app/Support/EquipmentState.php` (enum: Operating, Idle, Offline, Unavailable, Stale, Unknown, Retired; `label()`, `tone()` ok|warn|bad|neutral, `description()`)
- Create: `app/Services/Mines/FreshnessEvaluator.php`
- Create: `tests/Unit/Services/Mines/FreshnessEvaluatorTest.php`

```php
final class FreshnessEvaluator {
    /** @return array{state:EquipmentState, freshness:'fresh'|'stale'|'missing', age_seconds:int|null} */
    public function evaluate(?string $minesStatus, ?CarbonInterface $lastHeardAt, int $cadenceSeconds, ?CarbonInterface $retiredAt, ?CarbonInterface $now = null): array;
    public function forEquipment(Equipment $e, MinesConnection $c): array;
}
```
Rules (03-architecture §5): retired → Retired; no last_heard → Unknown/missing; age > 4c → Unknown/missing; age > 2c → Stale/stale (keep status in `description`); status maintenance → Unavailable; offline → Offline; active → Operating; idle → Idle; anything else → Unknown.

- [ ] Step 1: a test per row, plus boundary at exactly 2c and 4c. Step 2: FAIL. Step 3: implement. Step 4: PASS. Step 5: commit `feat: equipment freshness evaluator`.

---

### Task 4: `SyncMinesConnection` job, `mines:sync` command, scheduler

**Files:**
- Create: `app/Jobs/SyncMinesConnection.php` (queued, `$tries=1`, unique per connection id via `ShouldBeUnique`), `app/Services/Mines/MinesSynchroniser.php` (the logic, testable without the queue), `app/Console/Commands/SyncMines.php` (`mines:sync {connection?} {--sync : run inline}`)
- Modify: `routes/console.php` (every N minutes from config, `withoutOverlapping`, `onOneServer`)
- Create: `tests/Feature/MinesSynchroniserTest.php`, `tests/Feature/SyncMinesCommandTest.php`

Synchroniser algorithm:
1. Skip unless `connection->isUsable()`; create run `running` (trigger).
2. `whoAmI()` once per run when `status=unverified`; on success set `verified`.
3. Loop `sync(since, ['fleet','production','reference'])` until `!has_more`: for each fleet row upsert `equipment` (name, machine_type, mines_status, allocation_state, latitude/longitude, fuel_level, last_seen_at, sync_version); collect ids. For `deleted` with scope fleet set `retired_at=now()`.
4. For machines seen this run (or all machines every 12th run / when `fetched_machines` = 0 and no equipment yet): page `machines()`; upsert model, manufacturer, serial_number, registration_number, capacity, fuel_capacity, mine_area_id/name, location.updated_at → `location_recorded_at`.
5. `last_heard_at = max(location_recorded_at, engine_hours_recorded_at)`; recompute per upsert.
6. Pull `activeAlerts()` pages; upsert `alerts` by `(room, 'dot_mines', external_id=id)`: severity map priority critical→critical, high→warning, medium→warning, low→info; `source_triggered_at = triggered_at`; `equipment_id` via mines_machine_id; mark Dot.Mines-resolved alerts (`status=resolved`) as `cleared_at = resolved_at`.
7. Finish: `succeeded`, cursor advanced, connection `last_success_at`, `consecutive_failures=0`; one audit row `import` with counts, `automatic=true`.
8. Failures: `CredentialRejected` → connection `credential_invalid`, run `failed`, system Alert "Dot.Mines credential rejected" (source `system`, external_id `connection:{id}:credential`, dedupe); `RateLimited` → wait `retryAfter` once (max 60 s) then retry the request once, else `failed`; `MinesUnavailable`/`MalformedResponse` → `failed`, `consecutive_failures++`, `last_error` sanitised; cursor never advances on failure; partial (sync ok, alerts failed) → `partial` with cursor advanced.

- [ ] Step 1: tests with `Http::fake` sequences: two-page sync with tombstone; machines refresh populates model; alert upsert + dedupe on second run; verified status after whoAmI; 401 marks credential_invalid and raises system alert once; 429 then 200 succeeds; 500 fails with cursor unchanged; malformed fails; disabled connection skipped; the audit row written. Command test: dispatches per usable connection; `--sync` runs inline.
- [ ] Step 2–5 as usual; commit `feat: Dot.Mines synchronisation with observable runs`.

---

### Task 5: Engine-hours refresh within the rate budget

**Files:**
- Create: `app/Jobs/RefreshEngineHours.php` + logic in `MinesSynchroniser::refreshEngineHours(MinesConnection $c, int $limit)`; schedule every 15 minutes.
- Create: `tests/Feature/EngineHoursRefreshTest.php`

Per connection: pick up to `config('mines.hours_batch')` equipment ordered by `engine_hours_recorded_at` nulls first then oldest; `latestMetric()` → if `operating_hours` not null: update `engine_hours`, `engine_hours_recorded_at = recorded_at`, `fuel_level`, insert `equipment_hour_readings` (ignore duplicate), recompute `last_heard_at`. Respect `RateLimited` by stopping the batch.

- [ ] Tests: only the batch size is requested; null `operating_hours` leaves fields untouched (no zero); duplicate recorded_at not re-inserted; 429 stops early without failing the others already saved. Commit `feat: engine hours from Dot.Mines metrics`.

---

### Task 6: Signed webhook receiver

**Files:**
- Create: migration `create_integration_webhook_deliveries_table` (mines_connection_id FK, delivery_id string, event, occurred_at, payload json, status received|applied|ignored|failed, error nullable, timestamps; unique (mines_connection_id, delivery_id))
- Create: `app/Models/IntegrationWebhookDelivery.php`
- Create: `app/Http/Middleware/VerifyMinesWebhookSignature.php`, `app/Http/Controllers/MinesWebhookController.php`, `app/Services/Mines/WebhookApplier.php`
- Modify: `routes/web.php` (`POST /webhooks/mines/{connection}` outside auth, `withoutMiddleware(VerifyCsrfToken)`), `bootstrap/app.php` if CSRF exclusion is needed via `validateCsrfTokens(except:)`
- Create: `tests/Feature/MinesWebhookTest.php`

Verification: header `X-Mines-Signature: t=<unix>,v1=<hex>`; recompute `hash_hmac('sha256', $t.'.'.$rawBody, $secret)`; `hash_equals`; reject if `abs(now - t) > config('mines.webhook_skew_seconds')` → 400; missing secret on connection → 503; unknown connection → 404; replay (same delivery id) → 200 `{status:'ignored'}`; disabled connection → 202 ignored. Applier: `alert.triggered` → alert upsert as in Task 4 step 6 (data = AlertResource); `machine.offline` → equipment `mines_status=offline`, `last_seen_at = data.last_seen_at` if present; `geofence.entered|exited` → store on equipment `raw.last_geofence`; `maintenance.predicted` → alert severity by data.severity, type maintenance. Unknown event → `ignored`. Every delivery is stored first, then applied; failures mark `failed` with error and still return 200 (Dot.Mines must not retry a poison payload forever).

- [ ] Tests: valid signature applied and alert created; tampered body 400; skewed timestamp 400; replay ignored; unknown connection 404; disabled 202; unknown event ignored; applier failure recorded without 5xx. Commit `feat: Dot.Mines webhook receiver with signature verification`.

---

### Task 7: Health page and equipment ledger

**Files:**
- Create: `app/Http/Controllers/IntegrationHealthController.php`, `resources/views/health/index.blade.php`, `app/Policies/IntegrationHealthPolicy`? — use `AuditEntryPolicy@viewAny` semantics via a Gate `view-health` (supervisor/manager/administrator) defined in `AppServiceProvider`.
- Create: `app/Http/Controllers/EquipmentController.php` (`index` per room, `show`), `resources/views/equipment/index.blade.php`, `show.blade.php` — built on `.dot-panels`/`.dot-ledger`/readouts, state badge from `EquipmentState`, "as of" on every figure, "—" for null.
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php` (nav: Information → Equipment? Equipment is per room: link from the room page; Administration → Health), `app/View/Components/ShellStateBar.php` (data link uses last_success age: verified but > 3× interval → warn "link stale")
- Modify: `app/Http/Controllers/ControlRoomController.php@show` (equipment summary counts by state, link)
- Create: `tests/Feature/IntegrationHealthTest.php`, `tests/Feature/EquipmentPagesTest.php`

Health page content per connection: status, last success/failure with age, consecutive failures, last 10 runs (ledger), freshness distribution across its equipment (fresh/stale/missing), last webhook delivery and failed deliveries count, queue `failed_jobs` count, "Run sync now" (administrator; dispatches job; audited) and "Test credential" (whoAmI; audited; never shows token).

- [ ] Tests: operator 403; supervisor sees runs; admin "Run sync now" dispatches job (Queue::fake) and audits; equipment index lists states and "—" for null hours; show page shows readings history. Commit `feat: integration health page and equipment ledger`.

---

### Task 8: Live check, docs, matrix

- [ ] Start local Dot.Mines (`launch.json` entry `mines`, port 8010); sign in as the seeded demo user (seeded credentials from `database/seeders/DemoSeeder.php`), create an API token (Profile → API Tokens, abilities read+update) and save it on the Demo Control Room connection in Dot.Central (never pasted into chat or files).
- [ ] `php artisan mines:sync --sync` against it; verify equipment rows, a sync run `succeeded`, audit `import` row; open Health and Equipment pages; screenshot.
- [ ] Update `docs/dot-central/05-feature-matrix.md` (F-06..F-10), `wiki.md` 0.14.0, `10-outstanding-issues.md` (what was verified live vs faked), `04-dot-mines-integration.md` §8 result. Commit `docs: Increment 2 evidence`.
