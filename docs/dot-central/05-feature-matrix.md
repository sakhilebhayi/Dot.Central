# Dot.Central — Feature Implementation Matrix

Status values: **Done** (verified by tests and, where UI, in the browser) · **Partial** · **Present/non-functional** · **Missing** · **Blocked** (named dependency). Evidence column must point at tests, commits or screenshots before a row becomes Done. Updated as work lands.

| ID | Requirement (brief §) | Requirement source | Status 2026-10-15 | Dependency | Evidence |
|---|---|---|---|---|---|
| F-01 | Remove prohibited/customer names from seed, tests, placeholders | brief §2 | Partial — a real mine name in seeder/test/placeholder | — | — |
| F-02 | Roles operator/supervisor/manager/administrator enforced in policies | §7, DC-AC-1..3 | Present/non-functional (Jetstream roles never checked) | — | — |
| F-03 | Control room ↔ Dot.Mines connection (token, cadence, timezone) | §6 | Missing | D1 | — |
| F-04 | Site scoping via control-room assignment | §7 DC-AC-2 | Missing | F-02 | — |
| F-05 | Audit log, append-only, on all operational actions | §G, DC-AU-* | Missing | — | — |
| F-06 | Dot.Mines client + `/sync` delta + machine mirror with provenance | §6, DC-EQ-1 | Missing | F-03 | — |
| F-07 | Freshness evaluator + presentation state (Operating/Idle/Offline/Stale/Unknown) | §B,C, DC-EQ-2 | Missing | F-06 | — |
| F-08 | Engine hours from metrics with recorded_at | §B | Missing | F-06 | — |
| F-09 | Webhook receiver (signature, dedup) for alert/offline/geofence | §6 | Missing | F-03 | — |
| F-10 | Integration health page + shell indicator | §I, DC-DQ-2 | Missing | F-06 | — |
| F-11 | Operational overview (fleet status, active alerts, attention, freshness, shift progress) | §A | Missing (current dashboard counts agents) | F-07, F-12, F-14 | — |
| F-12 | Live fleet board + equipment drill-down | §B, DC-EQ-4 | Missing | F-07 | — |
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
| F-24 | Front-end build on Vite; remove CDN Tailwind/Alpine; one navigation | §11 | Partial | — | — |
| F-25 | Rule-based assistance (idle too long, missing telemetry, threshold deviations, prioritisation) | §8 | Missing | F-15 | — |
| F-26 | Stale-session scan (existing) | — | Done (tests) | cron | `ScanStaleSessionsCommandTest` |
| F-27 | AI-agent command centre (existing, out of scope) | — | Done (tests) | — | agent tests |
| F-28 | Deployment/recovery documentation | §13.9 | Missing | — | — |
| F-29 | Shift line-up / allocation module | DT docs | Deferred (D4) | spec | — |
| F-30 | Manual production capture | DT docs | Out of scope (D3) | — | — |
