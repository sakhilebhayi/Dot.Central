# Dot.Mines Integration Specification

**Verified against** `~/Dot/mines` at commit `dad7ee7` on 2026-10-15 by reading `routes/api.php`, `routes/api_v1.php`, controllers, resources, middleware, jobs and running the API test suite (154 tests pass). Nothing here is assumed from the Dot.Mines README, which documents routes that do not exist.

## 1. Interfaces Dot.Central uses

| Purpose | Endpoint | Notes |
|---|---|---|
| Delta sync | `GET /api/v1/sync?since=<cursor>&scopes=fleet,production,reference` | Cursor = monotonic `sync_versions` id; `has_more` paging (500); tombstones for deletes shaped `{entity_type: "machines", entity_id, sync_version}` (verified live 2026-10-15). Fleet fields: `id, name, machine_type, status, allocation_state, latitude, longitude, fuel_level, engine_hours (reads a null column — ignore), payload (null), last_seen_at (= ingest time), sync_version`. Production fields: daily `production_records` (`record_date, shift=continuous, quantity_produced, unit=tonnes, metadata{source:'telemetry', loads, cycles, …}`). |
| Full machine detail | `GET /api/v1/machines?per_page=100`, `GET /api/v1/machines/{id}` | `MachineResource`: `id, name, machine_type, model, manufacturer, registration_number, serial_number, status(active|idle|maintenance|offline), capacity, fuel_capacity, hours_meter*, operating_hours*, odometer*, mine_area{id,name,status}, location{latitude,longitude,updated_at}, alerts[]`. *Columns marked \* have no writer in Dot.Mines; treat as absent.* |
| Engine hours | `GET /api/v1/machines/{id}/metrics?limit=1` | Raw `machine_metrics` rows; use `operating_hours` (cumulative h, Bell), `idle_hours`, `fuel_level` (percent for Bell), `speed` (null for Bell), `recorded_at` (provider clock), `created_at` (ingest). Max 1000 rows, `hours_back ≤ 720`. |
| Alerts | `GET /api/v1/alerts?status=active`, `GET /api/v1/alerts/{id}`, `POST /api/v1/alerts/{id}/acknowledge`, `POST /api/v1/alerts/{id}/resolve` | `AlertResource`: `id, type, title, description, priority(critical|high|medium|low), status(active|acknowledged|resolved), machine_id, mine_area_id, triggered_at (ingest time), acknowledged_at/by, resolved_at/by, metadata{external_id?, raw code?}`. |
| Live positions | `GET /api/v1/live-locations` | `id, name, machine_type, status, last_location_*`. |
| Integration state | `GET /api/v1/integrations` | `last_sync_at, last_sync_status, last_error, sync_streams, capabilities` — the only exposed provider-health signal. |
| OpenAPI | `GET /api/openapi.json` (public) | Contract-test source. |
| Push | Outbound webhooks (`POST /api/v1/webhooks` to register) | Events: `alert.triggered`, `machine.offline`, `geofence.entered`, `geofence.exited`, `maintenance.predicted`. Payload `{event, occurred_at, data}`; headers `X-Mines-Event`, `X-Mines-Delivery`, `X-Mines-Signature: t=<unix>,v1=<hmac_sha256(t.body, secret)>`; retries 60/300/1800/7200 s, 5 attempts; endpoint auto-disabled after 15 consecutive failures; private/loopback URLs rejected (local dev needs a public tunnel or a test-mode bypass on the Dot.Mines side). |

Not available (do not build against): shifts, mine areas REST, fault-code lifecycle, telemetry push, provider cadence, per-machine stale flag, dispatch state, archived metrics, tenant discovery, machine-to-machine auth.

| Geofences | `GET /api/v1/geofences` (paginated; `type` pit/stockpile/dump/facility) | `GeofenceResource`: `id, name, description, type, status, mine_area_id, coordinates, center, area_sqm, perimeter_m`. Mirrored into `geofences` every sync as the first source of face locations (`13-shift-lineup-design.md` §5). |

**Not exposed (verified 2026-10-15, see `13-shift-lineup-design.md` §2):** operators and operator-to-machine assignments (models and `OperatorAssignmentService` exist in Dot.Mines; no API), machine class (only the brand in `machine_type`), mine-plan cuts/blocks, `excavator_id` ADT pairing (hidden by `MachineResource` on purpose). `/api/v1/assignments/available` and `/assignments/machines/{id}/history` are mine-*area* assignments.

## 2. Authentication and tenancy
- Sanctum personal access token, `Authorization: Bearer`, abilities checked per verb (`read` for GET, `update` for POST ack/resolve). Minted by a human in Dot.Mines (`/user/api-tokens`). Tenant = the token user's `current_team_id`; `POST /api/v1/user/team/{id}` switches it (mutates the user). **Dot.Central must mint one token per control room from a user whose current team is that mine and must never switch teams through the API.**
- **Pushing alert actions back** (`POST /alerts/{id}/acknowledge|resolve`) is additionally gated by Dot.Mines' `AlertPolicy`: the token's user must hold a Dot.Mines role on that team that grants `acknowledge_alerts` / `resolve_alerts` (the team `admin` role grants all). Token abilities alone are not enough; a user without such a role gets HTTP 403 (verified 2026-10-15, see §8 and I-23).
- Stateful same-origin cookies also authenticate, which is irrelevant cross-origin.
- Rate limit `api`: 60 req/min per token. Budget: one sync call per cycle, one machines page per 100 machines, engine-hours refresh spread over the hour.
- Errors: 401 `{message}`, 403, 404, 409 (no team), 422 `{message, errors}`, 429 `{message, retry_after}`.

## 3. Identifiers and units
- Machine identity: Dot.Mines integer `id` per team; `name` is the fleet number as the OEM/user named it; `serial_number` exposed; OEM id hidden.
- Units: `fuel_level` percent (Bell) — treat as percent only when `fuel_capacity` is unknown or the connection flags it; `operating_hours` hours; `capacity` tonnes; `speed` km/h when present.
- Time: ISO-8601 UTC. Three clocks per machine: `recorded_at` (provider), `created_at` (ingest), `location.updated_at` (provider location section). Dot.Central stores all three where available.

## 4. Freshness contract (consumer side)
Dot.Mines marks `offline` after 2 × provider interval (Bell 900 s → 30 min) and dashboards go amber at 30 min. Dot.Central replicates this with a **configured cadence per connection** (default 900 s) and the state table in `03-architecture.md §5`. `last_heard = max(location.updated_at, latest metric recorded_at)`; if neither exists the machine is *Unknown*.

## 5. Synchronisation design
1. `mines:sync {connection?}` scheduled every 5 min (configurable), queued, one job per active connection, `withoutOverlapping`.
2. Each run: record `integration_sync_runs(status=running)` → `/sync` loop until `!has_more` → `machines` refresh for machines seen in this delta (and a full refresh every N runs) → upsert `equipment` → evaluate freshness → finish with counts and new cursor. Any exception → `status=failed`, cursor unchanged, error class and sanitised message stored, no partial cursor advance.
3. Alerts: webhook-first; the sync run also pulls `alerts?status=active` as a reconciliation to catch missed deliveries. Dedup on `(control_room_id, source, external_id)`.
4. Engine hours: separate hourly job, chunked by rate budget.
5. Auth failure (401/403) marks the connection `credential_invalid` and raises a Dot.Central **system** alert (distinct from operational alerts) until a new token is saved.

## 6. Failure modes handled
Timeout; 429 (sleep `retry_after`, retry once, then fail the run); 5xx; malformed JSON (fail run, keep sample); machine missing from a page (tombstone only via sync tombstones, never by absence); webhook signature mismatch (400, logged, not applied); replayed delivery (200, ignored); clock skew > 5 min on signature (rejected); connection disabled (jobs skip).

## 7. Observability
`integration_sync_runs`, `integration_webhook_deliveries`, `failed_jobs`, per-connection `last_success_at`, `last_failure_at`, `consecutive_failures`; Health page and shell indicator derive from these. Logs never include tokens or signatures.

## 8. Local verification plan and result (2026-10-15)
Dot.Mines runs locally on port 8010 (`.claude/launch.json`). The client and synchroniser are contract-tested with `Http::fake` fixtures shaped field-for-field like the real resources, then run against the live local instance.

**Live result:** a token minted for the local Bell end-to-end fixture team (the seeded demo team owns no machines locally, and its seeded credentials have been changed) verified through `GET /user` (team 32); `mines:sync 1 --now` mirrored 2 machines (model, manufacturer, serial, area name from the `reference` scope, position with provider clock, Dot.Mines ingest clock) and 2 active alerts; `mines:refresh-hours 1 --now` fetched engine hours from `operating_hours` with their `recorded_at`; the run, cursor (0 → 116) and an `import` audit row were recorded. The fixture machines last reported on 2026-08-20, so the evaluator shows them as **Unknown** with "last known active", which is the intended behaviour. Two defects were found only by the live call and fixed with tests: the tombstone shape (§1) and area names for machines whose resource omits `mine_area`.

**Not verifiable locally:** inbound webhooks, because Dot.Mines refuses private and loopback endpoint URLs; the receiver is proven with correctly signed fixtures (`MinesWebhookTest`) and needs a public callback for an end-to-end delivery.

### 8.1 Alert push, verified 2026-10-15

- First attempt: resolve from Dot.Central with "also resolve in Dot.Mines" → Dot.Mines answered 403 because the fixture owner held no team role. Dot.Central kept the local resolution and (after the fix in commit `a9ac08c`) reports "Dot.Mines was NOT updated: …" in the flash, the `alert_events.note` and the audit reason.
- After granting the fixture owner the team `admin` role in the local Dot.Mines: resolve pushed, Dot.Mines alert 5 shows `status=resolved, resolved_by=<token user>`; Dot.Central `alert_events.pushed_to_source=true`, audit reason suffixed "(pushed to Dot.Mines)". Acknowledge is never pushed unless asked; the test suite asserts both.
