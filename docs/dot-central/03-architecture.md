# Dot.Central — Architecture

**Status:** proposed 2026-10-15, pending owner approval of the decisions in §7. Built on `01-requirements-discovery.md` and `02-current-state-audit.md`; the Dot.Mines facts come from `04-dot-mines-integration.md` (verified from code).

## 1. System boundary

```
                 ┌──────────────────────────────────────────────┐
  OEM telemetry  │ Dot.Mines  (fleet intelligence, per-team)     │
  (Bell today) ─▶│ machines · metrics · alerts · geofences ·     │
                 │ production records · integrations · webhooks  │
                 └───────┬──────────────────────────┬────────────┘
                         │ REST /api/v1 (Sanctum)   │ signed webhooks
                         │ GET /sync cursor         │ alert.triggered, machine.offline,
                         ▼                          ▼ geofence.entered/exited
                 ┌──────────────────────────────────────────────┐
                 │ Dot.Central  (control-room OS, per-team)      │
                 │ Integration layer: MinesClient · SyncRun ·    │
                 │   EquipmentMirror · FreshnessEvaluator ·      │
                 │   WebhookReceiver · IntegrationHealth         │
                 │ Operational core: Shifts · Handover ·         │
                 │   Alerts (ack/assign/escalate) · Exceptions · │
                 │   Incidents/Downtime · Tasks · Audit          │
                 │ Presentation: Overview · Fleet · Map ·        │
                 │   Equipment · Reports · Data quality · Health │
                 └──────────────────────────────────────────────┘
```

Core principle (from the brief): Dot.Mines provides fleet intelligence; Dot.Central turns it into operational awareness, coordinated action and accountable decisions. Dot.Central never writes fleet facts; the only writes back to Dot.Mines are **acknowledge** and **resolve** on alerts Dot.Mines raised (its API supports both), and only with the user's explicit action.

## 2. Tenancy

| Concept | Dot.Central | Dot.Mines | Mapping |
|---|---|---|---|
| Organisation | Jetstream **team** | — | one Dot.Central team can run many control rooms |
| Site / operation | **control room** (`control_rooms`) | Jetstream **team** (one mine = one team; no site entity) | `control_rooms.mines_team_id` + a `mines_connections` row holding the credential. Replaces the dangling `mines_site_ref`. |
| Area within a site | — | `mine_areas` | mirrored read-only for filters |
| User scope | team membership + **control-room assignment** (`control_room_user`) | n/a | managers/admins see all rooms of their team; operators/supervisors only assigned rooms |

Dot.Central's tenant key for Dot.Mines data is the control room; every mirrored row carries `control_room_id`.

## 3. Components

### 3.1 Integration layer (new, `app/Integrations/Mines/`)
- **`MinesClient`** — typed HTTP client over `/api/v1`: `sync()`, `machines()`, `machine()`, `machineMetrics()`, `activeAlerts()`, `acknowledgeAlert()`, `resolveAlert()`, `integrations()`, `mineAreas()` (via sync `reference` scope). Bearer token from `mines_connections` (encrypted cast). Honours 429 `retry_after`, times out at 10 s, never logs the token.
- **`SyncRun`** (job, queued, per control room) — delta pull through `GET /sync?since=<cursor>&scopes=fleet,production,reference`, then a bounded full refresh of `GET /machines` for fields `/sync` omits. Writes `integration_sync_runs` (started, finished, status, counts, error, cursor before/after). Idempotent: upserts keyed by `(control_room_id, mines_machine_id)` and `sync_version`; tombstones mark equipment `retired_in_source`. Lock per control room (`withoutOverlapping`).
- **`EngineHoursRefresh`** (job, low frequency) — for each machine, newest `operating_hours` from `GET /machines/{m}/metrics?limit=1`; stored with `recorded_at` and `source=dot_mines.metrics`. Budgeted against the 60 req/min limit (chunked, spread across the interval).
- **`WebhookReceiver`** — `POST /webhooks/mines/{connection}`; verifies `X-Mines-Signature` HMAC (tolerance 5 min), dedupes on `X-Mines-Delivery`, stores the raw envelope in `integration_webhook_deliveries`, then applies: `alert.triggered` → upsert operational alert (`source=dot_mines`, `external_id=data.id`); `machine.offline` → equipment liveness update + alert; `geofence.*` → equipment area context.
- **`FreshnessEvaluator`** — pure service: given a mirrored machine and the connection's configured provider cadence, returns `fresh | stale | missing` and the **presentation state** (§5).
- **`IntegrationHealth`** — aggregates the last sync run, webhook delivery lag, auth failures, failed jobs (from `failed_jobs`), freshness distribution per source; drives the Health page and the shell's health indicator.

### 3.2 Operational core (new tables, owned by Dot.Central)
`shifts`, `shift_handovers`, `alerts` (reworked), `alert_events` (ack/assign/escalate/resolve trail), `exceptions`, `exception_approvals`, `incidents` (downtime/operational events), `incident_reasons` (catalogue, per team), `tasks`, `audit_log`, `metric_definitions` (catalogue of displayed metrics with definition/unit/source). Existing `dispatch_decisions`, `operator_sessions`, `stale_session_proposals` are kept and re-homed under the control room's "Coordination" area.

### 3.3 Presentation
The operator experience (shell, interaction contract, visual language, screen-by-screen translation of the prototype) is specified in `11-operator-experience.md`; every screen from Increment 3 is judged against its §8 acceptance questions. Note that the current shell's type, accent and floating-card styling must change to the control-room language in that document before the live surfaces are built.

Plain controllers + Blade for CRUD (repo convention); **Livewire for live surfaces** (overview, fleet board, map, alert queue, health) with `wire:poll` as the baseline and Reverb broadcast as the upgrade once the front end is on Vite. One shell: control-room navigation grouped as *Operate* (Overview, Fleet, Map, Alerts, Shift), *Control* (Exceptions, Approvals, Incidents, Tasks), *Information* (Reports, Equipment, Data quality, Health), *Administration* (Rooms, Connections, Catalogues, Users, Audit), plus the existing *AI Agents* section.

## 4. Data flow

```
Dot.Mines ──/sync delta──▶ SyncRun ──▶ equipment (mirror) ──▶ FreshnessEvaluator ──▶ Fleet/Overview/Map
          ──webhook──────▶ WebhookReceiver ─▶ alerts(source=dot_mines) ─▶ Alert queue ─▶ ack/assign/escalate ─▶ alert_events + audit
                                                                          └─▶ (optional) acknowledge/resolve back to Dot.Mines
Central rules (RuleEngine) ─ evaluate equipment + shift + incidents ─▶ exceptions (upsert per rule×entity×shift)
Shift lifecycle ─▶ final review ─▶ handover snapshot ─▶ next shift acknowledges
Everything ─▶ audit_log (append only)
```

Every mirrored value carries `source`, `source_recorded_at` (provider clock), `ingested_at` (Dot.Mines ingest clock when known) and `synced_at` (Dot.Central clock). The UI shows "as of <source_recorded_at>" next to telemetry values.

## 5. Equipment presentation state (the rule the brief insists on)

| Inputs from Dot.Mines | Freshness (vs configured cadence `c`) | Dot.Central state shown |
|---|---|---|
| `status=active` | `last_heard ≤ 2c` | **Operating** |
| `status=idle` | `≤ 2c` | **Idle** (engine off or not running, as reported) |
| `status=offline` (Dot.Mines timed it out) | any | **Offline** (no contact since `last_heard`) |
| `status=maintenance` | any | **Unavailable (maintenance)** |
| any status | `> 2c` and `≤ 4c` | **Stale** — last known state shown greyed with age |
| any status | `> 4c` or no telemetry ever | **Unknown** — no data; never "stopped" |
| retired tombstone | — | **Retired in source** |

Movement is shown only when Dot.Mines supplies a non-null `speed` or two distinct fresh positions; otherwise "position only". `c` is configured per connection because Dot.Mines does not expose provider cadence.

## 6. Reliability rules
- No sync result is reported as success until the run completes and the cursor advances; partial runs are recorded `partial` with counts.
- Duplicate protection: `external_id` unique per control room for mirrored alerts; `X-Mines-Delivery` unique for webhooks; rule findings unique per (rule, entity, shift).
- Clocks: all storage UTC; display in the control room's configured timezone; provider timestamps stored verbatim.
- Missing values are `NULL` and rendered "—"; zero is only ever a reported zero.
- Permission checks in policies/actions; UI hides what the policy would refuse.
- Every operational action writes `audit_log` in the same transaction.

## 7. Decisions requiring owner confirmation

| # | Decision proposed | Alternative | Why proposed |
|---|---|---|---|
| D1 | A control room maps to **one Dot.Mines team**, credential = a Sanctum token minted by a human member of that team (abilities `read`, `update`), stored encrypted per connection. | Wait for an ecosystem service-account mechanism. | It is the only auth Dot.Mines supports today; the design isolates it behind `mines_connections` so a service-account later is a one-class change. |
| D2 | **Dot.Central owns the shift entity.** | Use Dot.Mines `shifts`. | Dot.Mines has no shift API or resource; its table is internal. |
| D3 | Manual production capture is **out of scope** for this build; production shown is Dot.Mines' telemetry-derived daily records, tagged `telemetry`. | Build the capture grid from the prototype. | Avoids duplicating Dot.Mines and importing site-specific factors. Revisit if a customer needs manual loads. |
| D4 | Shift line-up / allocation module **deferred**. | Build now. | Large; overlaps Dot.Mines dispatch state and the existing decision log; needs its own spec. |
| D5 | AI-agent command centre stays in the app under its own nav section, untouched. | Split it out. | Out of scope; splitting is a product decision. |
| D6 | Map uses **Leaflet** (new npm dependency) with OpenStreetMap tiles by default; tile URL configurable. | No map until a dependency is approved. | Brief requires a map; this is the smallest dependency. Needs approval per repo rules. |
| D7 | Roles become Jetstream team roles **operator / supervisor / manager / administrator** (replacing unused `admin`/`editor`) with policies; site scope via `control_room_user`. | Keep `admin`/`editor`. | Only the documented four roles are justified. |
| D8 | Real-time: `wire:poll` first (15 s on live boards), Reverb broadcasting in Phase 5 after the front end moves to Vite. | Reverb first. | The shell is on CDN assets today; Echo cannot be wired without consolidating the build. |
