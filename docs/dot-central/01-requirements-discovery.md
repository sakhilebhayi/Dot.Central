# Dot.Central — Requirements and Discovery Report

**Source reviewed:** the `DT/` prototype repository (`docs/01-discovery.md`, `docs/02-system-design.md`, `docs/03-ux-validation-simulation.md`, `docs/04-features.md`, `README.md`, `legacy-prototype/`, `js/core/*`), 2026-10-15.
**Purpose:** extract the reusable, customer-neutral requirements for Dot.Central and record what was deliberately left behind.
**Status:** discovery complete; nothing in this document is implemented until the implementation matrix says so.

The `DT/` material describes a browser-only prototype of a *control-room operating system* for one customer's open-cast mining operation. It was derived from a cell-by-cell analysis of that customer's shift spreadsheets. Per the Dot.Central brief, every customer, site, pit, crew, person, fleet number, production factor and target in that material is **excluded**. What follows is the generalised requirement set.

---

## 1. What the source material is, and is not

| Aspect | Finding |
|---|---|
| Nature | A requirements study plus a working browser prototype (localStorage, no server, no API) of manual shift capture: engine-hour readings, hourly truck loads, downtime, a shift line-up, exceptions, approvals, handover, reports, audit. |
| Telemetry | The prototype has **no live telemetry**. It imports one telematics export after the fact and reconciles it against manual tallies. Its "equipment status" is derived from manual readings and typed downtime events, never from a feed. |
| Overlap with Dot.Central | Strong on the *operational control* side: shift lifecycle, handover, exceptions/approvals, audit, equipment master, data quality, reporting discipline, permission model. Weak on the *live monitoring* side, which is the heart of the Dot.Central brief and must come from Dot.Mines. |
| Overlap with Dot.Mines | The prototype's equipment master, telematics device register, cycle/production records and operator register belong to Dot.Mines' domain (fleet intelligence). Dot.Central must not re-own them. |
| Trustworthiness | The design docs are carefully evidence-based, but they describe a prototype. `04-features.md` §"Known limits" lists ~70 defects and shortcuts (service-layer checks missing, rules never raised, fixed thresholds, no pit scoping). None of it is production code and none of it should be ported literally. |

## 2. Generalised requirements extracted

The codes below (DC-xx) are Dot.Central requirement IDs used by the implementation matrix.

### 2.1 Shift lifecycle and handover (reusable as-is)

- **DC-SH-1** A shift is `site × date × shift type`; exactly one may be current per site. State machine: `PLANNED → OPEN → IN_REVIEW → CLOSED → APPROVED`, plus `REOPENED` (supervisor, reason required, audited). Edits after `CLOSED` require reopening.
- **DC-SH-2** Shift types (start/end times, production hours) and crews/roster are **configuration per site**, not constants.
- **DC-SH-3** Closing a shift runs a final review; it is refused while any CRITICAL item is still unexplained. The refusal lists the blocking items.
- **DC-SH-4** Closing issues a **handover pack** and stores a snapshot: shift summary, open alerts/exceptions by severity, equipment down or on standby at shift end with "since", equipment requiring follow-up, open tasks, notes. The next shift's start acknowledges it (who/when), or it can be acknowledged explicitly. Handovers persist and are readable across sessions by authorised users.
- **DC-SH-5** The current shift is always visible in the application shell (a "shift pill"), with a direct path to start one when none is open.
- **DC-SH-6** A supervisor approves a closed shift; approvals and reopenings are audited with reason/comment.

### 2.2 Exceptions, alerts and approvals (reusable; the taxonomy is the valuable part)

- **DC-AL-1** One validation/evaluation pipeline produces findings with: `rule code`, `severity` (CRITICAL / WARNING / REVIEW / INFO), `outcome` (INVALID / SUSPICIOUS / REQUIRES_EXPLANATION / REQUIRES_APPROVAL / CONFLICT / MISSING / INFO), affected entity, evidence (`detail` JSON), and a **fix path**. Each outcome maps to one UI behaviour (refuse / save and flag / save only with explanation / save pending approval / store both values / raise at review).
- **DC-AL-2** Exception lifecycle: `OPEN → EXPLAINED → APPROVED | REJECTED`, or `RESOLVED` (by system when the condition clears, reopened if it returns), or `DISMISSED` (never for CRITICAL). Every transition records user, time, text.
- **DC-AL-3** Findings are **upserted** per (rule, entity, shift): re-evaluation refreshes an open finding rather than duplicating it; system-resolved findings reopen when the condition recurs. This is the dedup rule the brief asks for.
- **DC-AL-4** Error-message contract: title + rule code + outcome badge; what was entered; what was expected and why; what to do next. Never a bare "invalid input".
- **DC-AL-5** A supervisor "inbox" (Approvals) shows items awaiting decision, conflicts (two sources disagree, both values shown), and closed shifts awaiting sign-off, with a recent-decisions history.
- **DC-AL-6** Unresolved high-severity items remain visible: navigation badges, dashboard tiles, shift review and handover all draw from the same open-item query.
- **DC-AL-7** Findings carry `raised_by` = SYSTEM | AI | user; automated/AI findings only ever *add* exception rows and never change authoritative records.

### 2.3 Audit (reusable as-is)

- **DC-AU-1** Append-only audit log: user, role, entity, field, old value, new value, action (CREATE/UPDATE/DELETE/OVERRIDE/APPROVE/REJECT/GENERATE/IMPORT/LOGIN/ACK), reason, `automatic` flag, approver, shift. One row per changed field.
- **DC-AU-2** Manual interventions capture user, timestamp, reason, previous value and resulting value (brief §G). Overridden values keep their provenance (`source = MANUAL_OVERRIDE`) rather than being replaced.
- **DC-AU-3** Report generation and exports write a GENERATE row with filters and a data snapshot reference so an output can be reproduced.
- **DC-AU-4** Denied permission attempts on the service layer are logged.
- **DC-AU-5** A per-record "History" view and a searchable, exportable audit explorer for supervisors/managers/admins.

### 2.4 Equipment and operational state (split with Dot.Mines)

- **DC-EQ-1** Equipment identity, class, model and site allocation are **owned by Dot.Mines**. Dot.Central references equipment by Dot.Mines identifiers and caches only what it needs for display and joins (id, fleet number, class, site), with provenance and sync timestamp.
- **DC-EQ-2** Classification of operational state (operating / idle / stopped / unavailable / faulted / unknown) must be *derived from Dot.Mines' own liveness semantics*, and **stale or missing telemetry maps to an explicit UNKNOWN / STALE state**, never to "stopped" or "idle". (Brief §C and §9; the prototype's "never treat a blank as zero" principle generalises directly.)
- **DC-EQ-3** "Current hours" and similar derived figures come from the last validated reading or feed, never a typed column; the source and timestamp are shown with the value.
- **DC-EQ-4** Equipment drill-down: current state, last update, active faults/alerts, open exceptions, recent downtime, utilisation over recent shifts, and audit history, without leaving the operational context.

### 2.5 Downtime, incidents and tasks (reusable with generalisation)

- **DC-IN-1** Downtime/incident events: equipment, reason code from a configurable catalogue (category, counts-against-availability flag, requires-description flag), start, end, description, entered by/at. Validation: end ≥ start, within shift window (tolerance configurable), no overlapping events for one unit, duration ≤ shift length, BREAKDOWN requires description.
- **DC-IN-2** Events still open at shift end carry into the handover and can be decided once at the next shift start ("still down" vs "repaired"), never re-asked.
- **DC-IN-3** Tasks/follow-ups: assignee, due, status, link to the originating alert/exception/incident, escalation when overdue. (Brief §G; the prototype has no task entity, so this is a Dot.Central addition.)

### 2.6 Production and performance (reusable *definitions*, Dot.Mines data)

- **DC-PR-1** Every metric declares: definition, unit, period, source, and treatment of incomplete data. Metric catalogue from the prototype that generalises: run time, utilisation (run ÷ shift hours), availability (shift hours − downtime in availability categories ÷ shift hours), loads/cycles per truck, loads per operating hour, production vs target, shift start-up/shut-down (first/last activity vs shift bounds).
- **DC-PR-2** Load/volume factors are configuration keyed by `material class × equipment class` with effective dates, never constants in code and never attached to a screen position. Values are **customer configuration** and are not seeded.
- **DC-PR-3** Values from different origins (telemetry, manual entry, import, estimate) are tagged with `source` and never silently mixed; cross-source differences beyond a configurable tolerance raise a CONFLICT finding showing both values.
- **DC-PR-4** Targets are configuration per site × material class × period with effective dates; performance tiles show "no target configured" rather than 0 %.

### 2.7 Reports and history (reusable)

- **DC-RP-1** Reports are query definitions over persisted records plus a renderer; no report has its own storage. Outputs carry shift, generated-at, generated-by, data version. CSV export gated by permission; print layout.
- **DC-RP-2** Shift comparison, incident investigation and data-quality reporting over a date range, scoped to the user's sites.

### 2.8 Data quality and system health (reusable principle; new scope for integration health)

- **DC-DQ-1** A data-quality view: missing, suspicious, requiring explanation, conflicting, unapproved overrides, late entries, invalid-blocked, by rule and by unit and by shift.
- **DC-DQ-2** (Brief §I, not in prototype) Integration health: Dot.Mines connectivity, last successful sync per source, data freshness per source, API/auth failures, failed jobs, retry status. Operators must be able to tell an operational incident from an information-system failure.

### 2.9 Access and roles (reusable, mapped onto Jetstream teams)

- **DC-AC-1** Four roles justified by the documentation: **Operator** (capture, explain, start/close shift, acknowledge handover), **Supervisor** (everything an operator can, plus approve/reject/dismiss, resolve conflicts, approve/reopen shifts, export, audit view), **Manager** (read-only operations across sites, resolve conflicts, export, audit view), **Administrator** (master data, configuration, users, thresholds; cannot capture or approve operational data).
- **DC-AC-2** Site scoping: a user holds a list of sites; the service layer refuses actions outside it. The prototype never enforced this; Dot.Central must.
- **DC-AC-3** Permissions are checked in the service/action layer and again in the UI; the UI is never the only guard.

### 2.10 UX principles (reusable)

- **DC-UX-1** Dark, high-contrast control-room theme; monospace numerics; status colour semantics fixed across the app (red CRITICAL/INVALID/MISSING, orange WARNING/SUSPICIOUS, yellow REVIEW/explanation/approval/CONFLICT, blue INFO/OPEN, green VALID/APPROVED/RESOLVED/CLOSED, grey DISMISSED/PLANNED/REJECTED).
- **DC-UX-2** Workflow strip for the shift; KPI tiles with sub-lines stating the source; tables with explicit "—" for absent values; toasts; dialogs with required-reason text; keyboard-first capture grids.
- **DC-UX-3** Every value shows its provenance and last-update time where it matters (brief §C, §9).

## 3. Requirements deliberately **not** imported

| Item in `DT/` | Reason excluded | Disposition |
|---|---|---|
| Customer, operation, control-room, pit, crew, supervisor and operator names; fleet numbers; device IDs | Prohibited customer-specific references | Not reproduced anywhere. |
| Load factors (m³/t per load by truck make), coal densities, dozing rate, discard factor, daily targets | Customer production parameters | Replaced by empty, versioned configuration tables (DC-PR-2, DC-PR-4). |
| Material catalogue (seam names, throw-out, etc.), cut/block mine-plan lists | Site geology and mine plan | Replaced by a configurable material-class and material catalogue with no seed content. |
| Shift times 06:00/18:00, 10-hour budget, 12-hour shift length, 14:00 blasting slot | Site operating assumptions | Shift-type configuration per site; no defaults beyond "a shift has a start and an end". |
| Telematics-vendor-specific rules ("raw count + 1", geofence reason list, 95 %/90 % thresholds, bucket factors) | Vendor and site specific | Reconciliation stays generic: `manual vs telemetry`, tolerance configurable, reason codes configurable. Flagged for clarification (see §5). |
| Spreadsheet-inherited rule codes XR-03, PR-11, TL-04 ("legacy sheet double-counts", "multiplier typed in sheet") | Only meaningful for spreadsheet import | Dropped. |
| Hourly load grid and manual tally capture as the *primary* production source | Dot.Mines owns production/cycle data | Manual capture is retained only as a fallback source tagged `MANUAL`, behind a per-site feature flag, and only if Dot.Mines cannot supply the figure (see §5). |
| Shift line-up (faces, cut/blocks, truck-to-loader allocation, operator per machine, timed moves) | Valuable but large, and it is dispatch/allocation — overlapping Dot.Mines' dispatch states and Dot.Central's existing dispatch-decision scaffold | Deferred; recorded in the outstanding-issues register as a candidate Phase 4b module once Dot.Mines' dispatch contract is verified. |
| Prototype roles' PIN login, operational clock, simulation runner, data reset | Prototype mechanics | Not applicable. |
| Dot.Brain's description of Dot.Central (named mine in its worked example, dispatch-workflow node IDs, DKP packs) | Aspirational, unverified against code, and contains a real mine name | Treated as non-authoritative. |

## 4. Contradictions and gaps found in the source

1. **Documentation vs prototype code.** `04-features.md` is explicit that where docs and code disagree, the code wins, and lists the gaps: service-layer status checks missing on several writes, rules in the catalogue never raised (EH-07, EQ-03, TL-03), thresholds fixed in code while settings exist, site scoping never enforced. Dot.Central must treat the *design* documents as requirements and the prototype as evidence of UX intent only.
2. **Dot.Brain vs Dot.Central wiki.** Dot.Brain calls Dot.Central an operational-intelligence centre for mining dispatch; the Dot.Central wiki says it was built as an AI-agent command centre with a dispatch scaffold added later. The brief resolves this: Dot.Central is the control-room OS. The AI command-centre domain remains in the repo and is out of scope for this work (see audit).
3. **"Dot.Mines provides telemetry" vs the prototype's manual-first world.** The prototype assumed no server and no feed. Dot.Central inverts this: feed-first, manual as tagged fallback. Which metrics Dot.Mines actually supplies is established by the integration specification, not by this document.
4. **Shift ownership.** Dot.Mines has its own "Shift & Team Management" feature (per its README). Who owns the shift entity is an open architectural question (see §5).
5. **Telemetry engine hours.** The source's telematics export had empty engine-hour fields; the prototype therefore kept manual readings as the only source. Whether Dot.Mines supplies engine hours is to be verified, not assumed.

## 5. Flagged for clarification (not invented)

| # | Question | Why it matters |
|---|---|---|
| Q1 | Does the shift entity live in Dot.Mines or Dot.Central? | Both have shift concepts. Duplicating it breaks "do not duplicate Dot.Mines data"; not owning it breaks handover/closure control. |
| Q2 | Is manual production capture (loads per truck per hour) in scope for Dot.Central at all, or only reconciliation of Dot.Mines cycles? | Determines whether a capture grid is built. |
| Q3 | Which telemetry metrics are required at launch (engine hours, fuel, faults, cycles, location)? | Depends on what Dot.Mines actually exposes; drives the matrix. |
| Q4 | Is the shift line-up / allocation module wanted in Dot.Central, and how does it relate to Dot.Mines dispatch states and the existing Dot.Central dispatch-decision scaffold? | Large module; needs a boundary decision. |
| Q5 | Should the AI-agent command centre remain in the same application? | Affects navigation, roles and the product identity. |
| Q6 | Multi-tenancy unit: is a Dot.Central "organisation" = a Jetstream team = a Dot.Mines team? | Needed for the permission matrix and the integration's tenant mapping. |
| Q7 | Reconciliation thresholds and reason codes: configurable per site (recommended) or fixed? | Avoids importing vendor-specific rules. |

## 6. Where each generalised requirement goes

| Area | Dot.Central | Dot.Mines | Integration |
|---|---|---|---|
| Equipment master, telemetry, location, engine hours, cycles, fuel, faults | — | owns | Central reads via API, caches with provenance |
| Live fleet status classification | presents, with staleness rules | computes liveness | contract on state + freshness fields |
| Shifts, handover, closure, approval | owns (pending Q1) | may have own | mapping to be decided |
| Alerts raised by Dot.Mines (faults, thresholds) | acknowledges, assigns, escalates, resolves | raises | event or poll; dedup by source id |
| Exceptions from Central's own rules | owns | — | — |
| Incidents, tasks, downtime (operational record) | owns | — | may publish summaries back (future) |
| Reports, audit, data quality | owns | — | — |
| Integration health | owns | — | sync log, freshness per source |
| Configuration (shift types, reasons, factors, targets, thresholds) | owns, per site | — | — |
