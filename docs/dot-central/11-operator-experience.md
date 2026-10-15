# Dot.Central: Operator Experience Specification

**Source:** the complete `DT/` folder, reviewed 2026-10-15: the four design documents, the prototype's shell, stylesheet and every screen (`js/ui/*`, `css/app.css`), the domain layer it drives (`js/core/*`), the earlier static mock-up in `legacy-prototype/`, and both prototypes run in the browser as an operator (including deliberately entering bad data to see the refusals).
**Purpose:** record how the owner wants Dot.Central to *feel* and *work* for the people in the control room, so that every screen built from Increment 3 onward is judged against it. Customer-specific content is excluded; what remains is the product's character.

---

## 1. The experience in one paragraph

An operator signs in and is told, before anything else, which shift is open and whether anything needs them. Everything is organised around the shift: it is started, run, reviewed, closed and handed over, and every figure on screen belongs to it. The system does the remembering (what was running, what was down, what the last reading was, who was on which machine) and the operator does the deciding. Capture is keyboard-first and immediate: type, press Enter, see the result. Nothing is silently accepted or silently rejected: a bad value is refused *with the reason and the fix*, a doubtful value is saved *and flagged*, and anything that needs a supervisor is routed to them. Figures are never decorative: each one says where it came from and when. At the end, the shift cannot be closed with unexplained critical items, and the handover the next crew sees is the same record, not a retyped summary.

The two prototypes show the owner's taste moving from an impressive-looking "digital twin" (the legacy mock-up: scanlines, count-up numbers, glowing status pulses, an "emergency override" button, no capture, no workflow) to a working instrument (the current prototype: no decorative motion, large readable figures, explicit rules, one-click fixes). Dot.Central follows the second: *premium because it is precise, not because it glows*.

## 2. Principles (in priority order)

1. "Does anything need me?" is answered first. Badges on Exceptions and Approvals, severity tiles, the blocking count on End Shift, the shift pill in the top bar. A screen's first row of information is status, not statistics.
2. The shift is the spine. Every screen states which shift it shows; the top bar carries the current shift and its status everywhere; the dashboard has a workflow strip (Start → Line-up → Hours → Production → Downtime → Exceptions → End) whose steps tick as the shift progresses. When no shift is open, screens say so and link to Start Shift rather than showing stale data as if live.
3. Capture once, validate immediately, calculate automatically. The operator never types a derived value (opening reading, run time, variance, tonnage, factor). Every save runs the rules and shows the result inline; calculations appear next to the entry, not on another page.
4. Refusals explain and offer the fix. The message contract: *title · rule code · outcome badge*, then *what was entered*, *what was expected and why*, then "→ what to do", and where a legitimate exception exists, a one-click path ("Hour meter was replaced, request approval", "Move here", "Repaired, allocate", "Move operator to this machine"). Never a bare "invalid input".
5. Doubt is saved and flagged, not blocked. Only impossible values are refused. Suspicious values save with an orange outline and an exception; missing explanations are asked for; conflicts show both sources.
6. Every machine needs a place or a reason. A unit that is not working has a reason code (breakdown, service, standby, no operator, off site). Zero without a reason is an exception, and recording the reason clears it automatically.
7. **Provenance on every figure.** Source (telemetry / manual / imported / carried forward), timestamp, and whether it is validated, approved or pending. ", " for absent values; never a zero that means "unknown".
8. Reports are the same records. No report has its own storage; nothing is retyped; generation is audited; the handover pack is the live record, snapshotted at close.
9. Exceptions resolve themselves when the data is fixed, reopen if the condition returns, and are never duplicated for the same condition.
10. **Accountability without surveillance.** Who/when/old/new/reason on every change; no individual operator performance league tables.

## 3. The shell

- Top bar (fixed, 56 px): product mark · control-room name · shift pill (label + status badge; click → Shift Operations; "No open shift: start one" when none) · operational clock (in the control room's timezone) · user name and role label · log out. Add for Dot.Central: a data-health chip (Dot.Mines connection state and freshness summary) so an information-system failure is visible on every screen and never mistaken for an operational one.
- Left navigation (fixed, ~232 px, uppercase section labels):
  - **Shift**. Overview, Start Shift, Line-up (deferred D4), Shift Operations, Engine Hours, Production, Downtime, End Shift & Handover
  - **Control**. Exceptions (red badge: open on current shift), Approvals (yellow badge: awaiting decision), Data Quality
  - **Information**. Reports, Equipment, Fleet (live), Map, Telematics Reconciliation (future)
  - **Administration**. Administration, Audit Log, Health
  Items are hidden when the role holds none of the screen's permissions; typing the address shows "Not available for your role: this screen needs X; your role is Y".
- **Active item:** 3 px left rule in the signal colour plus signal-coloured text, flush with the nav edge (not a floating pill).
- **Main area:** max 1600 px, 24 px gutters; cards share a 1 px rule grid rather than floating with shadows.
- **Responsive:** 1100 px collapses 4–6-column tile rows to two; 900 px hides the nav (a replacement menu is required in Dot.Central, the prototype had none); 760 px single column; 600 px shrinks the shift pill. Print hides chrome and prints black on white.

## 4. Interaction patterns to reproduce exactly

| Pattern | Behaviour |
|---|---|
| **Keyboard-first grids** | Engine Hours: type closing, Enter saves and moves down; blur saves if changed. Production grid: Enter/Tab saves and moves right then to the next row, Shift+Tab back. Focus returns to the right cell after the re-render; a click is never lost to a redraw. |
| **Inline result** | Accepted: field turns green ("EX saved: run 9.0 h · variance 1.0 h · recorded by N at 05:40"); accepted-with-findings: orange, findings listed; refused: red, keeps focus, findings above the table with the fix. |
| **Type-ahead with identity** | Operator picker offers "Full name · employee code"; a bare name is accepted only when unique; ambiguous or unknown names are refused with a toast explaining what to pick. |
| Counters that tell you what is left | "92 entered · 0 missing", "Missing only (n)" filter, "Checks before confirming (n)", "Blocking (critical, unexplained) n / ready to close". |
| **Exception card** | Code · title · outcome badge · status badge; message; "→ fix"; explanation and resolution once present; footer "shift · unit · model · raised <when> by SYSTEM"; role-aware buttons Explain / Approve / Reject / Resolve conflict / Dismiss (never Dismiss on CRITICAL). The same card is reused on Overview, Shift Operations, End Shift, Exceptions, Approvals and Reports. |
| **Dialog discipline** | Reasons are required text fields that keep the dialog open until filled; "Cancel" + a verb button ("Close shift and issue handover", "Correct the time", "Record"); clicking the backdrop cancels. |
| **Toasts** | Bottom-right, 4 s (7 s for errors), used only for confirmations that need no decision. |
| **Time-bounded changes** | Once a shift is under way, a change carries the time it happened ("Changes recorded at", "moved at"); future times and times outside the shift are refused; recorded times can be clicked to correct, with the linked record corrected too. |
| **Carry-forward with a decision** | What was down at the end of the last shift is asked about once at the next start ("still down" / "repaired"); nothing is re-asked. |
| **Automatic tidy-up, always audited** | A breakdown takes a machine off its place from the moment it stopped; recording downtime clears the zero-run exception; a placement clears "not on line-up". Each is written to the audit log with reason text. |
| **Drill-down without losing context** | Tiles on Shift Operations open the capture screens; Data Quality rows open filtered Exceptions; Equipment rows open the unit with its history and audit trail; reports link back to the shift. |
| **Role-aware read-only** | Managers and administrators see the same screens with inputs disabled and action buttons absent, plus a sentence saying who acts ("The shift operator or supervisor closes the shift. This view is read-only."). |

## 5. Screen-by-screen translation to Dot.Central

The prototype is manual-first; Dot.Central is feed-first with manual capture as tagged fallback. The *shape* of each screen carries over; the data source changes.

| Prototype screen | Dot.Central screen | What stays | What changes |
|---|---|---|---|
| Login brand panel with live tiles (shift in progress, open exceptions, data loaded, equipment master) | Login | The idea that the sign-in page already answers "is a shift open, does anything need me" | Jetstream/SSO login; tiles read from Dot.Central's own tables; no PIN buttons |
| Control Room Dashboard | **Operational Overview** | Workflow strip; six shift tiles; severity tiles; "Exceptions needing attention" (top 8); loads/activity per hour bars; equipment by class table; units down at shift end | Tiles become fleet-state counts (Operating / Idle / Offline / Stale / Unknown / Unavailable) from Dot.Mines with freshness; production tiles from Dot.Mines daily records tagged `telemetry`; a data-freshness tile |
| Shift Operations | Shift Operations | Five linking tiles (line-up, readings, production, downtime, exceptions), faces table, latest downtime, exceptions on this shift | Faces table deferred with D4; add "machines needing attention" from the live feed |
| Start Shift | Start Shift | Proposal of the next shift; roster crew pre-selected; "Loaded automatically" list; previous handover card (production, units still down/standby, open exceptions, units without reading); start → next screen | Expected equipment comes from the Dot.Mines mirror for the room; opening engine hours carried from the last validated value (feed or manual) |
| Engine Hours | Engine Hours | Fixed fleet order; class filters; "Missing only"; keyboard grid; inline validation; meter-replacement approval path; operator type-ahead; note column; "+ Unit not listed" | Opening and closing readings pre-filled from Dot.Mines `operating_hours` with `source=telemetry` and "as of"; operator may override with reason (audited, `source=manual`); rule set EH-01…EH-12 as configured thresholds |
| Production | Production (read-mostly) | Tiles with targets and progress bars; per-face/per-unit tables; factor shown per load | Loads/cycles from Dot.Mines production records; the hourly capture grid only behind a per-room feature flag (D3) |
| Downtime | Incidents & Downtime | Category hour tiles; zero-run banner; event table with source column; record/edit dialog with "Still down at shift end"; delete requires reason | Reason catalogue configurable per team; events may be seeded from Dot.Mines `machine.offline`/alerts with `source=dot_mines` and confirmed by the operator |
| Exceptions · Approvals · Data Quality | Same three screens | Filters (shift, severity, status, group); counts; cards; supervisor inbox with conflicts and shifts to approve; recent decisions; data-quality tiles and "open items by rule / worst units / shifts" | Add integration-health findings as their own group so system failures are distinguishable |
| End Shift & Handover | End Shift & Handover | Readiness tiles; calculated shift result vs targets; faces worked; units down/standby at end; close with notes; handover issued/acknowledged; approve; reopen with reason; print | Unchanged in shape; snapshot persisted and *shown* (the prototype stored but never displayed it) |
| Reports | Reports | Tabs; shift and date selectors; print; CSV for supervisors+; GENERATE audit; "Hours layout" familiarity for legacy users | Report set per the roadmap; no spreadsheet validation tab |
| Equipment | Equipment | List with filters and tiles; unit page with current hours (derived), history sparkline, master record, telematics device, audit trail; status/pit change with reason | Master data read from Dot.Mines mirror; local fields limited to control-room status and notes |
| Telematics Reconciliation | (future) Reconciliation | Accuracy tiles and thresholds; per-unit reconcile dialog with reason codes | Only if manual counts exist (D3); thresholds and reasons configurable |
| Administration | Administration | Tabbed master data; every change needs a reason and is audited; thresholds in one place | Catalogues are per team/room; no operational clock |
| Audit Log | Audit Log (built) | Search, action filter, action-count tiles, CSV | Already shipped in Increment 1 |
| Legacy mock-up: Fleet Ops / Telemetry matrix / Production intelligence / Speeding / Operator tagging | **Fleet** and Map | The *questions* those pages asked: which units are operating, which are faulty, who is on what, where is everyone | Built from real Dot.Mines data with freshness; no fabricated matrix, no decorative animation |

## 6. Visual language

This aligns with the owner's established control-room direction (Dot.Memory, 2026-08-26) and with the prototype:

- **Type:** IBM Plex superfamily only: Plex Sans Condensed for display and panel labels, Plex Sans for body (15 px base), Plex Mono with tabular numerals for every figure. Not Syne, Inter, Space Grotesk, Geist or Figtree.
- **Surfaces:** night-first palette (`#0B0E11` page, `#0D1117` / `#161B22` / `#1C2128` panels) with a day theme; panels share edges on a 1 px rule grid; 4 px radius at most; no drop shadows, no glass blur, no radial blooms behind the logo.
- **Signal colour:** amber (`#F2A70B` night / `#8A5C07` day) for the active state, primary buttons and the shift pill, matching the Dot mark. Not sky blue.
- **Status colours (fixed semantics):** red CRITICAL/INVALID/MISSING; orange WARNING/SUSPICIOUS; yellow REVIEW/REQUIRES_EXPLANATION/REQUIRES_APPROVAL/CONFLICT/EXPLAINED; blue INFO/OPEN/IN_REVIEW/REOPENED; green VALID/APPROVED/RESOLVED/CLOSED/ACTIVE; grey DISMISSED/PLANNED/REJECTED/unknown.
- **Figures:** 28–30 px monospace readouts in tiles; right-aligned tabular columns; ", " for absent; units always shown (h, t, m³, %); percentages with one decimal at most.
- **Tiles:** label (12 px uppercase, letter-spaced) · value · sub-line stating the source or the comparison; a thin progress bar where a target exists; border colour only as a state (critical/warn/good), never as decoration. Avoid rows of four identical tiles with no hierarchy: the first tile answers the state question.
- **Motion:** none beyond 150 ms hover/focus transitions and a single slow live-pulse on the connection dot. No scanlines, count-ups, flicker or glow (removed deliberately between the two prototypes).
- **Density:** operators read at a glance; tables at 14 px with 7–8 px row padding, sticky headers, hover tint; wide tables scroll inside their panel.
- Check before calling a screen done: run the Impeccable detector on the rendered page and verify contrast on both panel tones (see the design-language memory); then walk the screen as an operator with the keyboard only.

## 7. From the prototype, deliberately not carried

- Scanline overlays, count-up numbers, status glow/pulse animations, "Emergency override" button, "Global Command Center" framing (legacy mock-up).
- PIN login with one-click role buttons; the operational clock; the simulation runner; "reset data from spreadsheets".
- Customer logo, control-room name, pit, crew letters, supervisor and operator names, fleet numbers, cut/block lists, material names, factors, targets, telematics vendor names and rules.
- Fixed thresholds in code (12 h, 10 h, 70 %, 8 trucks): all become per-room configuration.
- Any screen that fabricates data (the legacy mock-up's matrices and heatmaps).

## 8. Acceptance questions for every new screen

1. Does the first row answer "does anything need me?" and name the shift?
2. Can the whole capture flow be completed with the keyboard, and does focus land where the operator expects after each save?
3. Does every refusal show code, what was entered, why, and a fix, with a one-click path where a legitimate exception exists?
4. Does every figure show its source and age, and does a missing value render as ", "?
5. Is the screen honest when there is no shift, no data, stale data, or a failed sync?
6. Do managers/administrators get the read-only version with the sentence that says who acts?
7. Is every state change in the audit log with a reason where one was required?
8. Does the Impeccable detector come back clean, and is the type IBM Plex, the signal amber, and the layout a shared-edge panel grid?
