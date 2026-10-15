# Dot.Central — Feature Implementation Matrix

Status values: **Done** (verified by tests and, where UI, in the browser) · **Partial** · **Present/non-functional** · **Missing** · **Blocked** (named dependency). Evidence column must point at tests, commits or screenshots before a row becomes Done. Updated as work lands.

| ID | Requirement (brief §) | Requirement source | Status 2026-10-15 | Dependency | Evidence |
|---|---|---|---|---|---|
| F-01 | Remove prohibited/customer names from seed, tests, placeholders | brief §2 | **Done** 2026-10-15 | — | `ProhibitedReferencesTest` (scans app/config/database/resources/routes/tests/docs) |
| F-02 | Roles operator/supervisor/manager/administrator enforced in policies | §7, DC-AC-1..3 | **Done** 2026-10-15 | — | `RolePermissionsTest` (22-case matrix, owner = administrator, legacy role migration), policies in `app/Policies` |
| F-03 | Control room ↔ Dot.Mines connection (token, cadence, timezone) | §6 | **Done** 2026-10-15 | D1 | `MinesConnectionTest`, `MinesClientTest`; credential verified live against local Dot.Mines (team 32) |
| F-04 | Site scoping via control-room assignment | §7 DC-AC-2 | **Done** 2026-10-15 | F-02 | `ControlRoomAssignmentTest` |
| F-05 | Audit log, append-only, on all operational actions | §G, DC-AU-* | **Done** for existing models 2026-10-15 (new models must use `Auditable`) | — | `AuditLogTest`, `AuditsTest`; browser-verified |
| F-06 | Dot.Mines client + `/sync` delta + machine mirror with provenance | §6, DC-EQ-1 | **Done** 2026-10-15 | F-03 | `MinesClientTest` (9), `MinesSynchroniserTest` (7), `EquipmentMirrorSchemaTest`; live: 2 machines mirrored from local Dot.Mines with model, serial, area, position and three clocks; tombstone shape corrected from the live payload |
| F-07 | Freshness evaluator + presentation state (Operating/Idle/Offline/Stale/Unknown) | §B,C, DC-EQ-2 | **Done** 2026-10-15 | F-06 | `FreshnessEvaluatorTest` (12 rows incl. 2c/4c boundaries); live: 56-day-old fixture machines shown Unknown, not a machine state |
| F-08 | Engine hours from metrics with recorded_at | §B | **Done** 2026-10-15 | F-06 | `EngineHoursRefreshTest` (5); live: hours 5 217 / 8 413 with provider clock and reading history |
| F-09 | Webhook receiver (signature, dedup) for alert/offline/geofence | §6 | **Done** (tests); **awaiting live verification** — Dot.Mines rejects private callback URLs, so no delivery can reach a local receiver | F-03 | `MinesWebhookTest` (5: valid, tampered, skewed, replay, unknown/disabled/no-secret, applier failure) |
| F-10 | Integration health page + shell indicator | §I, DC-DQ-2 | **Done** 2026-10-15 | F-06 | `IntegrationHealthTest` (4), `AppShellTest`; browser screenshots of Health with live runs |
| F-11 | Operational overview (fleet status, active alerts, attention, freshness, shift progress) | §A | Missing (current dashboard counts agents) | F-07, F-12, F-14 | — |
| F-12 | Live fleet board + equipment drill-down | §B, DC-EQ-4 | Partial — equipment ledger and unit page shipped (`EquipmentPagesTest`); live board with polling, filters and attention list in Increment 3 | F-07 | browser screenshots 2026-10-15 |
| F-13 | Operational map with stale distinction | §C | Missing | F-07, D6 | — |
| F-14 | Alert workflow: classify, acknowledge, assign, escalate, resolve, history, dedup | §D | Partial (manual create/clear only) | F-05 | — |
| F-15 | Exception engine (rules → findings, upsert, lifecycle, approvals) | §D,G, DC-AL-* | Missing | F-05 | — |
| F-16 | Shifts: create/configure, current shift, review, close, approve, reopen | §E, DC-SH-* | Missing (operator_sessions only) | F-05 | — |
| F-17 | Handover pack: snapshot, persist, acknowledge | §E, DC-SH-4 | Missing | F-16 | — |
| F-18 | Incidents / downtime with reason catalogue and validation | §G, DC-IN-* | Missing | F-05 | — |
| F-19 | Tasks with assignment, due, escalation | §G, DC-IN-3 | Missing | F-05 | — |
| F-20 | Production & performance with metric catalogue and source tagging | §F, DC-PR-* | Missing | F-06 | — |
| F-21 | Reports with filters, export, generation audit | §H, DC-RP-* | Missing | F-16..F-20 | — |
| F-22 | Data quality view | DC-DQ-1 | Missing | F-15 | — |
| F-23 | Real-time updates (poll → broadcast) | §5 | Present/non-functional (Reverb unconfigured) | Vite consolidation | — |
| F-24 | Front-end build on Vite; control-room shell (IBM Plex, amber, shared-edge panels, state bar) | §11 | **Done** 2026-10-15 (welcome page still loads Alpine from unpkg — guest page, out of scope) | — | `AppShellTest` (5); Impeccable detector clean on 5 rendered pages; night/day/phone screenshots |
| F-25 | Rule-based assistance (idle too long, missing telemetry, threshold deviations, prioritisation) | §8 | Missing | F-15 | — |
| F-26 | Stale-session scan (existing) | — | Done (tests) | cron | `ScanStaleSessionsCommandTest` |
| F-27 | AI-agent command centre (existing, out of scope) | — | Done (tests) | — | agent tests |
| F-28 | Deployment/recovery documentation | §13.9 | Missing | — | — |
| F-29 | Shift line-up / allocation module | DT docs | Deferred (D4) | spec | — |
| F-30 | Manual production capture | DT docs | Out of scope (D3) | — | — |
