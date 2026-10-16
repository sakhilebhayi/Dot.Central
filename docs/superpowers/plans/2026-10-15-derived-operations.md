# Derived Operations (Increments 3–6) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every prototype page exists in Dot.Central with data derived from the Dot.Mines mirror; operators only confirm shifts, write handover notes, explain exceptions, act on alerts and classify stoppages.

**Architecture:** `docs/dot-central/12-derived-operations-design.md`. Derivation services are pure over the mirror; pages are Blade on `.dot-panels`/`.dot-ledger`, live boards use Livewire `wire:poll`. Every operator action goes through a policy, writes audit, and never edits a mirrored fact.

**Tech Stack:** as before; no new dependencies (the map, D6, stays pending approval).

## Global Constraints
As in the Increment 2 plan, plus: no manual data entry fields for engine hours, loads, production or allocation; per-room thresholds live in `control_room_settings`; "—" for absent values; derived values show their source and clock.

### Task A: Derived data foundation
Files: migrations `create_equipment_metrics_table` (+ drop `equipment_hour_readings`), `create_equipment_status_events_table`, `create_production_records_table`, `create_control_room_settings_table`, `create_shift_types_table`, `create_downtime_reasons_table`; models + factories; `MinesSynchroniser` records status events on every upsert, mirrors production from the sync scope, and `refreshEngineHours` fetches 24 h of metrics (`hours_back=24, limit=200`) into `equipment_metrics`; `ControlRoomSettings` defaults; seeding of default shift types (Day 06:00–18:00, Night 18:00–06:00 as a *default*, editable) and a generic reason catalogue on room creation. Tests: schema, status-event capture on status change, production mirror upsert, metrics history dedupe, settings defaults.

### Task B: Shifts and derivation
Files: migrations `shifts`, `shift_handovers`, `shift_unit_summaries`; models; `ShiftService` (propose, open, review, close, acknowledge, approve, reopen) with policies; `ShiftDerivation` (per-unit summary over the window); pages Start Shift, Shift Operations, End Shift & Handover; state-bar shift pill wired. Tests: proposal sequencing, refuse second open shift, close refused with open CRITICAL, handover persisted and acknowledged by next start, reopen requires reason, derivation numbers (opening/closing/run/loads/tonnes/stoppage minutes) from fixture metrics.

### Task C: Derived operational pages
Engine Hours (derived table, filters, "Explain" on flagged rows), Production (tonnes per machine per day + loads from counters, totals, optional targets), Downtime (observed stoppages with classify dialog), Fleet board (machines grouped by area with state and last geofence, `wire:poll`). Tests per page: correct derivation, read-only for every role, classify action audited.

### Task D: Rules, exceptions, approvals, data quality
`exceptions` + `exception_events` tables; `RuleEngine` with the §4 rules and upsert/resolve/reopen semantics; evaluated after each sync and on review; pages Exceptions (filters), Approvals (inbox + shifts to approve), Data Quality (counts by rule/unit/shift); the shared exception card with role-aware actions. Tests: each rule fires and clears; dedupe; lifecycle; permissions.

### Task E: Overview, Alerts workflow, Reports, Administration
Overview becomes the landing page (agent hub moves to `/agents` dashboard); Alerts page with acknowledge/assign/escalate/resolve + `alert_events`, optional push to Dot.Mines; Reports (shift, engine hour, production, downtime, handover, equipment) with CSV and GENERATE audit; Administration (shift types, reasons, thresholds, members, connection). Tests for each.

### Task F: Live verification, docs
Run against local Dot.Mines; screenshots; update matrix, wiki, issues, roadmap.

---

**Status 2026-10-15:** Tasks A–F done. Task E commit `48b6842`; live-verification fix `a9ac08c` (refused Dot.Mines push is reported, not hidden). Open: map (D6), general tasks (I-26), transitional inline-grid CSS block in `app.css` still in use by the older room pages.
