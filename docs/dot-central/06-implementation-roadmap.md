# Dot.Central: Implementation Roadmap

Each increment is built on a feature branch, test-first, and is complete only when its acceptance criteria below are met and the matrix row cites evidence. Phases map to the brief §10.

## Increment 1: Foundation (brief Phase 3): done 2026-10-15, plus the control-room shell restyle (`11-operator-experience.md` §6)
Scope: F-01, F-02, F-04, F-05, F-24 (part), F-03 (model only).
- Replace demo mine name with neutral fixtures; test asserting no prohibited names in seeders/views/tests.
- Jetstream roles operator/supervisor/manager/administrator; policies for ControlRoom, Alert, Shift, Incident, Task, Exception; `control_room_user` assignment; `EnsureControlRoomAccess` middleware; tests for each role × action and cross-team denial.
- `audit_log` table + `Audits` service + `Auditable` trait; tests that every state change writes a row.
- `mines_connections` table (encrypted token, base URL, cadence, timezone, webhook secret, status) + admin UI; `control_rooms.mines_team_id`; drop `mines_site_ref` with data migration.
- Front end: move the authenticated shell to `@vite`, delete CDN script tags and the duplicate Jetstream menu, consolidate Tailwind v3 config to the shell's tokens.
Acceptance: full suite green; pint clean; phpstan not worse than baseline (baseline file added); browser check of shell and admin pages.

## Increment 2: Dot.Mines integration (Phase 3/5): done 2026-10-15 (F-09 awaiting a public callback for live proof; see `04-dot-mines-integration.md` §8)
Scope: F-06, F-07, F-08, F-09, F-10.
- `MinesClient` with `Http::fake` contract tests from OpenAPI shapes; `SyncRun` job; `equipment`, `equipment_telemetry` (latest + history of what we fetched), `integration_sync_runs`, `integration_webhook_deliveries`.
- Freshness evaluator unit tests for every row of the state table.
- Webhook receiver with signature tests (valid, tampered, replayed, skewed).
- Health page; shell indicator; system alerts for credential failure.
Acceptance: sync against the local Dot.Mines instance with a human-minted test token produces mirrored equipment with correct provenance; failure modes tested with fakes.

## Increment 3: Live operations (Phase 4): done 2026-10-15 except the map (F-13, awaiting D6)
Scope: F-11, F-12, F-13. Result: Overview is the landing page (poll 30 s), Fleet board (poll 15 s); see `12-derived-operations-design.md`.
- Overview Livewire page (poll 15 s): fleet by state, attention list, active alerts by severity, freshness summary, shift tile.
- Fleet board with filters, equipment drill-down (state, last heard, engine hours as-of, active alerts, open exceptions, incidents, history).
- Map (Leaflet, pending D6) with per-marker state and age; stale markers rendered distinctly; "position only" vs "moving".
Acceptance: pages render from mirrored data; empty, stale and failed states shown; browser screenshots recorded.

## Increment 4: Alerts and exceptions (Phase 4): done 2026-10-15
Scope: F-14, F-15, F-25 (first rules). Result: alert workflow with `alert_events` and optional push to Dot.Mines (live-verified), rule engine with 10 rules, exception ledger, approvals inbox.
- Alert model rework: source, external_id, severity, priority, status (active/acknowledged/assigned/escalated/resolved), assignee, escalation level, evidence json; `alert_events`; optional ack/resolve back to Dot.Mines.
- Rule engine with declarative rules; first rules: telemetry missing > N, offline > N during shift, idle > N while on shift, engine hours decreasing, duplicate identifiers; findings upsert; lifecycle; approvals inbox.
Acceptance: rule tests; dedup tests; permission tests; UI for queue, card actions, approvals.

## Increment 5: Shifts, handover, incidents, tasks (Phase 4): done 2026-10-15 except general tasks (F-19 partial)
Scope: F-16..F-19. Result: shift lifecycle over derived data, handover snapshot + acknowledge, observed stoppages classified against a reason catalogue.
- Shift types per control room; shift lifecycle; review; close with blocking check; handover snapshot + acknowledge; reopen with reason.
- Incidents (downtime) with reason catalogue and validation; tasks with escalation.
Acceptance: lifecycle tests incl. "cannot close with open CRITICAL"; handover visible to next shift; audit rows.

## Increment 6: Production, reports, data quality (Phase 4): done 2026-10-15 (derived scope; no manual capture, D3)
Scope: F-20..F-22. Result: six reports with CSV + GENERATE audit, data-quality page, room administration (F-31).
- Metric catalogue; production view from Dot.Mines daily records (source tagged); shift comparison; CSV export with GENERATE audit; data-quality page.
Acceptance: calculations unit-tested; no fabricated figures; "no data" states.

## Increment 6b: Shift set-up and line-up (owner instruction 2026-10-15): done 2026-10-15
Scope: F-29, F-32, F-33 (`13-shift-lineup-design.md`). Result: log in → Start Shift (outstanding items from the previous shift) → Line-up (faces, click-to-place, operator per machine, standby reasons, checks, confirm) → everything else follows; fleet board shows availability and issues per machine. Not built: arrival/departure corrections and material splits (I-29); operators from Dot.Mines (I-28).

## Increment 7: Real-time and resilience (Phase 5)
Scope: F-23; retries/backoff review; stale indicators everywhere; recovery runbook.
Acceptance: Reverb broadcasting configured, Echo on Vite, event-driven refresh of overview/alerts; failure injection tests.

## Increment 8: Verification and production readiness (Phases 6–7)
Scope: F-28; full regression; permission matrix tests; security review; deployment/backup/recovery docs; wiki update.

Ordering rationale: nothing operational can be trusted without roles, audit and a verified integration; the live surfaces then have real data; workflows build on alerts; reports last because they need persisted records.
