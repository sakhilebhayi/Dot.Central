# Dot.Central: Line-up Corrections Design (I-29)

**Date:** 2026-10-18
**Status:** the owner asked for I-29 (`10-outstanding-issues.md`, row I-29) to be built. Plan: `docs/superpowers/plans/2026-10-18-lineup-corrections.md`. Slices 1 (corrections) and 2 (linked faces) are merged and live; slice 3 (material split, §5) is on branch `feat/lineup-face-split` and its PR, not yet merged. Sections 4.9 and 5.9 record how slices 2 and 3 were built.
**Governing documents:** `11-operator-experience.md` (feel, refusal contract, keyboard-first), `12-derived-operations-design.md` §1 (observe, derive, act; nothing an operator enters changes a Dot.Mines fact), `13-shift-lineup-design.md` (the line-up as built), `03-architecture.md` D3 (no manual production capture).

---

## 0. Decisions for the owner

The owner has said the answer will be "go with the recommendations". Each row gives the recommendation and what the alternative would cost.

| # | Question | Recommendation | Cost of the alternative |
|---|---|---|---|
| D-1 | Arrival / departure corrections: what are they? | **A correction of Central's own line-up record.** The operator states the time a machine really arrived on (or left) a face. The stint it came from (or went to) moves with it. Dot.Mines is never called and no Dot.Mines fact changes. | Keeping today's workaround ("take it off and place it again") keeps creating overlapping history: verified on 2026-10-18, a truck placed at 10:30, taken off and placed again at 10:00 ends up recorded on the face twice, 10:30–12:00 and 10:00–open. |
| D-2 | Who may correct? | **Operators and supervisors** (the existing `lineup` ability, `app/Policies/ShiftPolicy.php:24-27`). A reason is required. Every correction goes into the audit log, onto the handover and into the Dot.Memory loop. | Supervisor-only means the operator who clicked late has to find a supervisor, and supervisors become the bottleneck at shift change. It adds no control: the correction is already visible on the handover. |
| D-3 | Corrections on a closed shift? | **Current shifts only** (OPEN, IN_REVIEW, REOPENED). A closed shift is corrected by reopening it, which already exists (`ShiftService::reopen`, `app/Services/Shifts/ShiftService.php:177-190`). | Editing a CLOSED or APPROVED line-up would change a handover that was already issued and acknowledged. The snapshot is frozen at close (`ShiftService.php:152-155`), so the handover would stop being the record. |
| D-4 | Linked faces (one loading unit on two faces): a room setting, or only an indicator? | **An indicator, on the rule that already exists.** Faces at the same cut that share a block may share a loading unit (`LineupFace::sameWorkplaceAs`, `app/Models/LineupFace.php:86-89`; applied in `LineupService::place`, `app/Services/Lineup/LineupService.php:336`). Surface it on the face card, the fleet board, the handover and the report. Count the two faces as one for LU-06 and LU-07. Refuse a cut or block edit that would leave the loader on two unrelated faces. | A room setting ("allow a loader on two faces") adds an administration toggle and a second branch in `place()`, with no evidence that any room wants to forbid the physical case. A setting that allows sharing at *any* cut would let the line-up claim one machine was in two places. |
| D-5 | Material split: percentage, tonnage, time, or label? | **A time split only.** "It changed at 12:00 — new face from then" closes the face at 12:00 and opens a new face with the new material and the same machines. No quantity is entered or apportioned. A material that was wrong from the start is corrected in place. | A percentage or tonnage split means someone types or apportions production by material, which is manual production capture and conflicts with D3. Dot.Mines has no material per load (`12-derived-operations-design.md` §6, I-24), so Central has nothing to split by. To get quantities the owner would first have to lift D3. |
| D-6 | "Breakdowns at shift change" (prototype) | **Do not build.** DOWN is derived from Dot.Mines status and the operator's classifications (`LineupService::downAt`, `LineupService.php:121-139`; `13-shift-lineup-design.md` §2: "there is no 'breakdowns at shift change' decision"). | Building it would make the operator type that a machine is repaired. That contradicts "DOWN is never typed" (`LineupService.php:31-32`) and would fork availability from Dot.Mines. |
| D-7 | Fix the time holes found while designing (§6)? | **Yes, in the first slice.** `place()` refuses a stint that overlaps an earlier stint of the same machine, and a move recorded before the machine arrived where it is. Times the clocks skipped (DST) are refused. | Without these fixes, corrections can be undone by the old workaround, and history can still overlap. |
| D-8 | Mirror to Dot.Memory? | **Yes:** a correction and a split each become an `action` record through `AuditLoopMapper`. Fix the existing line-up mapping defects (§9) in the same slice. | Without mirroring, Memory cannot recall "this machine's moves are often recorded late". |
| D-9 | Order and smallest valuable slice | **Slice 1:** corrections end to end, plus the time-rule fixes and the mapper fix (plan Tasks 1–6). **Slice 2:** linked faces (Task 7). **Slice 3:** material split (Tasks 8–9). | Building the split first gives the least value. Without load-level material the split changes only labels and history, while late moves happen every shift. |

**Not built, plainly:** "breakdowns at shift change" (D-6); any quantity, percentage or tonnage attached to a material split (D-5, conflicts with D3); the prototype's "re-value all loads" and "move loads booked after the change" (Central has no loads per face to re-value). Evidence of need: the owner asked for I-29. The prototype study validated all three features as scenarios S27 (timed move), S29 (material change at 12:00) and S30 (moves and a future release) (`DT/docs/03-ux-validation-simulation.md:154-157`). The I-29 workaround produces wrong history today (D-1).

## 1. Evidence

### 1.1 How the DT prototype handled these features

- **Arrival / departure corrections.** Every "Since" time and every time under "Moved off this face" is a link that opens "When did <fleet> arrive on <face>?" or "When did <fleet> leave <face>?". Its text reads "Recorded HH:MM. A move is one moment: the face it came from (or went to) is corrected with it." It has a time field and a reason field, with the buttons "Cancel" and "Correct the time" (`DT/js/ui/screens/lineup.js:62-77`; `DT/docs/04-features.md:765-788`). The service `retimeAllocation` (`DT/js/core/services.js:431-479`) moves the partner stint with the corrected one and refuses several cases:
  - times out of order;
  - a time outside the life of the face;
  - an overlap with another stint (a linked loader is exempt);
  - time gained while a breakdown was recorded (AL-02).

  It also trims standby recorded during the newly worked time. Clicking a machine that is already on the target face while "Changes recorded at" is earlier than its arrival opens the same dialog (`DT/js/ui/screens/lineup.js:86-93`).
- **Material split.** A material change on a working face needs a decision (`DT/js/core/services.js:297-303`; `DT/docs/04-features.md:845-866`). One choice is "The material was wrong — re-value all <n> loads" (the face is edited in place). The other is "It changed at HH:MM — new face from then". `splitFace` (`DT/js/core/services.js:345-372`) closes the face at that time and opens a new face with `splitFrom`. The machines carry on to the new face. Loads booked for later hours move to the new face, and loads in the hour of the change are flagged. The load handling is production capture.
- **Linked faces.** A loading unit may work two faces at once only at the same cut and a shared block ("coal and its parting"). The face card shows "· same loader as face <n>" and the fleet button shows "face 1+2". AL-06 "no trucks" is not raised when the linked face has trucks. A face that took over from another after a split is never linked to it (`DT/js/core/lineup.js:53`, `:69-77`; `DT/js/core/rules.js:322`; `DT/docs/04-features.md:874-888`). A cut or block edit that breaks the link is refused, with the fix "Move the loading unit here" (`DT/js/core/services.js:305-308`).

### 1.2 What Dot.Central does today

- **Times.** "Changes recorded at" accepts HH:MM inside the shift and refuses future times. A typed time earlier than the shift start is read as the next day (`LineupService::changeAt`, `LineupService.php:75-106`). The Blade panel is at `resources/views/livewire/lineup.blade.php:36-42`. A placement starts at that time (`LineupService.php:357-360`), and a move ends the old stint at that time (`:344-348`). A release refuses a time before arrival (`:372-374`). The "Since" column and "Moved off this face" are plain text (`lineup.blade.php:127`, `:136`).
- **Hole 1, verified on 2026-10-18 with a throwaway test that was then deleted.** `place()` never checks ended stints of the same machine. Release at 12:00 followed by a placement at 10:00 leaves the history `["10:30-12:00","10:00-open"]`.
- **Hole 2.** A move whose time is before the machine's arrival on its current face deletes that stint without saying so: `endAllocation` deletes when `at <= from_at` (`LineupService.php:381-384`). `release()` and `setStandby()` refuse this case (`:372-374`, `:407-409`), but `place()` does not.
- **Linked faces.** Sharing is allowed in `place()` (`:336`), and the fleet button already shows `face 1+2` (`lineup.blade.php:206`). Not surfaced:
  - the face card header (`lineup.blade.php:97`);
  - the fleet board, which shows only the first placement (`app/Livewire/FleetBoard.php:86`);
  - the Shift Operations faces table (`app/Http/Controllers/ShiftController.php:95-106`);
  - the handover snapshot (`ShiftService.php:220-224`);
  - the line-up report (`app/Http/Controllers/ReportController.php:133-150`).

  LU-06 is raised on a linked face without trucks even when its partner has them, and LU-07 counts each face separately (`LineupService.php:648-664`). `editFace` changes the cut or blocks without checking for a shared loader. Verified: after changing the cut of the second face, the loader still had 2 open stints.
- **Material.** The material is free text on the face (`database/migrations/2026_10_15_000017_create_lineup_tables.php:35`). `editFace` overwrites it at any time with no decision (`LineupService.php:263-270`). Closing a face and adding a new one works, but every machine then has to be clicked again.
- **What reads the line-up.** `ShiftDerivation` imports no line-up model (`app/Services/Shifts/ShiftDerivation.php:5-15`). Run hours, idle hours, loads, production and stoppage minutes come from metrics, status events, classifications and production records only. The line-up is read by:
  - the pool and checks (`LineupService.php:146-212`, `:641-678`);
  - LU-11 "ran but never placed", which looks at any stint in the shift (`app/Services/Rules/RuleEngine.php:179-187`);
  - the handover snapshot (`ShiftService.php:214-233`);
  - Start Shift's outstanding list (`ShiftController.php:63-92`);
  - the faces table (`ShiftController.php:95-106`);
  - the fleet board (`FleetBoard.php:40-90`);
  - the line-up report (`ReportController.php:133-150`);
  - the Memory confirmation record (`app/Services/Memory/AuditLoopMapper.php:182-199`).
- **Audit and Memory.** Line-up models are `Auditable` (`app/Models/Concerns/Auditable.php:15-41`): created, updated and deleted rows are written without reason text. `Audits::record` passes every row to `MemoryRecorder::fromAudit` (`app/Services/Audits.php:64-66`), which runs `AuditLoopMapper` and swallows errors (`app/Services/Memory/MemoryRecorder.php:56-62`). The line-up refusal hook is `MemoryRecorder::assignmentRefused` (`MemoryRecorder.php:98-111`). It is called only when Dot.Mines refuses an operator (`LineupService.php:497-499`).
- **Concurrency.** `LineupService` uses no transaction. In the whole app, `DB::transaction` appears only in Fortify and Jetstream actions. Production runs SQLite in WAL mode with DEFERRED transactions and a 5 s busy timeout (`config/database.php`, sqlite block), and `lockForUpdate` compiles to nothing on SQLite (`vendor/laravel/framework/src/Illuminate/Database/Query/Grammars/SQLiteGrammar.php:31-34`).

## 2. Principles applied

1. **A correction annotates Central's own record.** It changes `lineup_allocations.from_at` and `to_at`, Central's `lineup_standbys`, and Central's `lineup_faces`. It never writes:
   - `equipment.mines_*`;
   - `equipment_status_events`, `equipment_metrics` or `production_records`;
   - mirrored alerts;
   - `downtime_classifications`. These rows are Central's own, but they classify a Dot.Mines stoppage, so a line-up correction leaves them alone.

   It never calls `MinesClient`, and the tests assert `Http::assertNothingSent()`.
2. **DOWN stays derived.** A correction that would put a machine on a face while Dot.Mines reported it `offline` or `maintenance`, or while an availability-counting classification covered it, is refused (LU-02). The same rule already applies to placement (`LineupService.php:325-327`). "Stopped" comes only from those explicit values (`LineupService::STOPPED`, `LineupService.php:40`), never from missing telemetry.
3. **No production capture (D3).** None of the three features adds a quantity, a load count or a percentage.
4. **Refusals follow the contract** in `11-operator-experience.md` §2.4: code, what was entered, why, and the fix. Where a legitimate exception exists there is a one-click path.
5. **Audited with reason; mirrored through the audit row.** Nothing posts to Dot.Memory directly.

## 3. Feature A: arrival and departure corrections

### 3.1 What the operator or supervisor sees and does

- On a timed line-up (shift started or line-up confirmed) on a current shift, for a user with the `lineup` ability:
  - Every **"Since"** time on a face card becomes a small ghost button showing the recorded time, for example `2026-10-15 10:30`. Its accessible name is: *"Correct when HT-01 arrived on Face 2 — Loading area 2 — Overburden (recorded 2026-10-15 10:30)"*. Its tooltip is *"Correct when HT-01 arrived on this face"*.
  - Under **"Moved off this face:"**, every departure time becomes a button named: *"Correct when HT-01 left Face 1 — Loading area 1 — Overburden (recorded 2026-10-15 10:30)"*.
  - A corrected time carries a `corrected` badge whose tooltip reads *"was 10:30: the move was clicked late"*.
- **The dialog** is an inline panel above the faces, the same pattern as the face form. It has `role="dialog"`, `aria-labelledby` pointing to its title and `aria-describedby` pointing to its help line.
  - Title: *"When did HT-01 arrive on Face 2 — Loading area 2 — Overburden?"*, or *"When did HT-01 leave Face 1 — …?"*
  - Help line: *"Recorded 2026-10-15 10:30. A move is one moment: the face it came from is corrected with it. Times are the control room's clock (Africa/Johannesburg)."* For a departure it says "the face it went to".
  - Fields: **"Time (HH:MM)"**, prefilled with the recorded time and focused when the panel opens, and **"Reason"**, required, with the placeholder "e.g. the move was clicked late".
  - Buttons: **"Correct the time"** (primary, submits) and **"Cancel"**.
  - A refusal stays inside the panel with `role="alert"` and keeps the panel open.
- **On success** the panel closes. A `role="status"` notice reads: *"HT-01 on Face 2 — Loading area 2 — Overburden at 10:00 (was 10:30); off Face 1 — Loading area 1 — Overburden at the same time."*
- **Shortcut from the fleet panel.** With "Changes recorded at" set to a time earlier than the machine's recorded arrival, clicking a machine that is already on the target face opens the same panel, prefilled with that time. Without such a time it says *"HT-01 is already on this face (since 10:30; select that time on its row to correct it)."* Today this case silently returns the existing stint (`LineupService.php:330-333`), and the page shows a misleading "HT-01 → Face 1" (`app/Livewire/Lineup.php:155`).
- **Keyboard path:**
  1. Tab to the Since time and press Enter. The panel opens with focus in Time.
  2. Type the time, press Tab, type the reason, press Enter to submit.
  3. Escape cancels (`wire:keydown.escape`).
  4. The result is announced by the status region.
- **Read-only users** (managers, administrators, or a closed shift) see the times as plain text, and the existing read-only sentence stays (`lineup.blade.php:45-46`).

### 3.2 Who may

`ShiftPolicy::lineup` covers operators and supervisors (`ControlRoomPolicy::operate`, `app/Policies/ControlRoomPolicy.php:53-57`). It is checked by `Lineup::editable()` and `run()` (`app/Livewire/Lineup.php:66-69`, `:288-301`). The service refuses a non-current shift with SH-04 (`LineupService.php:697-702`). No new permission is added.

### 3.3 Rules (all refusals keep the data unchanged)

| Code | When | Text (entered · why · fix) |
|---|---|---|
| SH-04 | Shift not current | existing text |
| LU-05 | Line-up still planning | "Nothing to correct while planning: before the shift starts every machine is on its face from shift start." |
| LU-05 | No time, bad time, outside the shift, in the future, or skipped by the clocks | existing `changeAt` texts, plus "01:30 did not happen on 29 Mar in Europe/London: the clocks went forward. Enter the time on the clock after the change." |
| LU-05 | No reason | "Say why the time is corrected, for example "the move was clicked late"." |
| LU-05 | Departure of a stint that is still open | "HT-01 is still on Face 2 — …. Take it off with "Remove" at the time it left." |
| LU-05 | Times out of order (own or partner stint) | "Times out of order: HT-01 left Face 1 — … at 10:30. Pick a time before that." / "… only arrived on Face 1 — … at 08:00. Correct that time first, or pick a time after it." |
| LU-05 | Outside the life of a face | "Outside the life of the face: Face 1 — … ran 07:00–now. Pick a time while the face was working." |
| LU-05 | Overlaps another stint of the machine (a shared loader on a linked face is exempt) | "Overlaps another entry: HT-02 was on Face 1 — … 08:00–09:00. Correct that entry first, or pick a time that does not overlap it." |
| LU-02 | The machine would gain face time while DOWN | "HT-02 was down then: Dot.Mines: maintenance since 09:30. If the stoppage is wrong, correct it where it is recorded: Dot.Mines for its status, the Downtime page for a classification." |
| LU-15 (new) | The time changed since the screen showed it, or the compare-and-set failed | "Someone changed this time meanwhile: HT-01 is now recorded on Face 1 — … at 10:00. Check it and correct again if it is still wrong." |

**On success:**

- The stint's edge changes. The **partner** (the stint of the same machine that ended exactly at the old arrival, or began exactly at the old departure) changes with it.
- Standby recorded during the newly worked span is trimmed: ended, removed, or started later. This follows `DT/js/core/services.js:472-476`.
- One `lineup_corrections` row is written.
- One audit row is written with action `override`, entity `LineupCorrection`, the old and new ISO times of both edges, and the reason *"Arrival on Face 2 — … corrected from 10:30 to 10:00: The move was clicked late"*.

### 3.4 What changes and what does not

| Changes (Central's own) | Does not change |
|---|---|
| `lineup_allocations.from_at` / `to_at` of the stint and its partner | Anything mirrored from Dot.Mines: `equipment.mines_*`, status events, metrics, production records, alerts |
| `lineup_standbys` overlapping the newly worked span | `downtime_classifications` and DOWN (corrections are refused instead) |
| The line-up report's From / Until and notes | Run hours, idle hours, loads, production, stoppage minutes and availability: `ShiftDerivation` does not read the line-up (`ShiftDerivation.php:5-15`) |
| The handover snapshot, when the shift is (re)closed | `shift_operator_assignments` (operator rows are per machine, not per face) and anything pushed to Dot.Mines |
| "since HH:MM" on the fleet board | The line-up status and version: a confirmed line-up stays confirmed |
| Copy-from-previous, but only when a departure is moved across the last minute of the shift (`LineupService::proposal`, `LineupService.php:545-547`) | LU-11, which only asks whether the machine had *any* stint, and corrections never delete one |

### 3.5 Audit and Dot.Memory

- **Audit.** The `override` row on `LineupCorrection` carries the reason. The two allocation edges are changed by compare-and-set updates (§3.8), so they write no separate `updated` rows. The `override` row holds both edges' old and new values.
- **Memory.** `AuditLoopMapper` maps `LineupCorrection` + `override` to one `action` record with:
  - `action_kind` `lineup.correct_time`, `executor_platform` `dot-central`, `execution_status` `succeeded`;
  - signature `dot-central:lineup:time-corrected`;
  - loop `dot-central:shift:<id>`, the loop operator assignments already use (`AuditLoopMapper.php:202-209`);
  - subject `equipment`, keyed by the Dot.Mines machine id: `LoopSubject` gains `LineupCorrection` (`app/Services/Memory/LoopSubject.php:26-29`);
  - detail `edge`, `old_at`, `new_at`, `minutes`, `allocation_id`, `partner_allocation_id`.

### 3.6 Handover, fleet board, report

- **Handover snapshot** (`ShiftService::lineupSnapshot`) gains `corrections`: a list of `{text, reason, by, at}`. Start Shift's handover card shows *"Line-up times corrected (n)"*, one line each: "HT-01 on Face 1 — … at 10:00 (was 10:30). — The move was clicked late (Operator Name)".
- **Fleet board:** a placed machine reads "Available · Face 1 — … · since 10:00". The correction updates the "since".
- **Line-up report:** the Note column appends "arrival corrected from 10:30: <reason>" on the corrected stint and "moved with a correction (<reason>)" on its partner.

### 3.7 Data model

New table `lineup_corrections`, created by an additive migration:

| Column | Type and constraint |
|---|---|
| `id` | primary key |
| `control_room_id` | FK, cascade |
| `shift_id` | FK, cascade |
| `lineup_allocation_id` | FK, cascade |
| `partner_allocation_id` | unsigned big int, nullable, no FK |
| `equipment_id` | FK, cascade |
| `edge` | `ARRIVAL` or `DEPARTURE` |
| `old_at` | timestamp |
| `new_at` | timestamp |
| `reason` | string(255) |
| `corrected_by` | FK users, null on delete |
| timestamps | |

Index: `(shift_id, lineup_allocation_id)`. The model is team-scoped (`HasControlRoomTeamScope`) but not `Auditable`, because the service writes the reasoned `override` row itself. Plain `Schema::create` with foreign keys works on SQLite and Postgres, and production is SQLite. There is no data backfill.

### 3.8 Edge cases

- **Across a shift boundary.** HH:MM is read inside the shift window. A time earlier than the shift's local start time means the next day (`LineupService.php:90-95`), so a night shift's "23:40" lands before midnight and "01:00" lands after it. A time outside the window is refused. A machine that really moved during the previous shift belongs to that shift's line-up. Correcting it means reopening that shift, which `ShiftService::reopen` allows only when no other shift is open (`ShiftService.php:183-185`).
- **Closed shifts.** SH-04. After a supervisor reopens, corrections are allowed. The re-close rebuilds the snapshot and summaries (`ShiftService.php:147-155`), so the handover shows the correction.
- **Two supervisors at once.** The panel remembers the time it showed (`expected`). If the stored time differs at save, the result is LU-15. Everything is read again under a lock: `correctTime` and `place` run in a transaction whose first statement is `LineupService::lockMachine`, an UPDATE of the machine's stints in the shift followed by a locking read of the machine's own row. On SQLite (WAL, DEFERRED) the UPDATE is the first write, so it takes the single write lock (waiting up to the busy timeout) and every later read sees the latest commit; `lockForUpdate` compiles to nothing there. On Postgres the UPDATE row-locks the stints that exist and the `lockForUpdate` on the Equipment row serialises a machine that has none yet (a first placement). The stint, its partner and its neighbours are then re-read and every check runs again, so a lost race ends in LU-15 or a refusal with a full rollback. The lock relies on its UPDATE being the first statement of the transaction; a caller that reads first must set the SQLite `transaction_mode` to IMMEDIATE or lock explicitly.
- **Operator unassigned in Dot.Mines meanwhile.** It does not matter. The correction does not read or write operator rows or `equipment.mines_operator_*`. The face card keeps showing Dot.Mines' current operator hint (`lineup.blade.php:122`), and the sync keeps applying unassignments (`app/Services/Mines/MinesSynchroniser.php:397-421`).
- **Machine retired in Dot.Mines meanwhile.** Its stints remain history and can still be corrected. The face card shows "?" for retired machines (`lineup.blade.php:112-115`, existing behaviour).
- **Machine stopped by Dot.Mines inside the gained span.** LU-02 (§3.3). A span the machine loses (arrival moved later with no partner) is never refused: the machine becomes FREE in the gap, which is honest. No standby is invented.
- **Room time zone and DST.** The room's clock is `MinesConnection.timezone`, or UTC without a connection (`app/Models/ControlRoom.php:109-114`), and the panel names it. A time that does not exist (spring forward) is refused (LU-05). In the repeated hour (fall back) a typed time is two instants. The later one is taken when it is inside the shift and has already happened (not in the future); otherwise the first. This also covers a closed or past shift, where the later one has always happened. The success notice says which pass was used, for example "01:40 GMT, the second time the clock showed 01:40". Verified for Europe/London (25 Oct: 01:40 becomes 00:40 UTC for the first pass, 01:40 UTC for the second) and Australia/Sydney (5 Apr). Everything is stored in UTC.
- **Stint ended by a face closure.** A departure later than `closed_at` is refused (life of the face). An earlier departure is allowed.
- **Shared loader on linked faces.** Overlaps with its stints on a linked face are allowed, as in `DT/js/core/services.js:457-458`.

### 3.9 Tests

- `tests/Feature/LineupCorrectionTest.php` covers:
  - arrival with partner, with the audit row, operator rows and derived summaries unchanged, and no HTTP;
  - departure with partner, and the same time again changing nothing;
  - every refusal in §3.3;
  - standby trimming (ended and removed);
  - a stale second supervisor (LU-15);
  - a night shift across midnight.
- `tests/Feature/LineupServiceTest.php` (DST and the place holes).
- `tests/Feature/LineupPageTest.php` (panel, a11y names, refusal inside the panel, the fleet-panel shortcut, read-only).
- `tests/Feature/LineupRulesAndHandoverTest.php` (snapshot, Start Shift, report, fleet board).
- `tests/Feature/Memory/AuditLoopMapperTest.php` (the `action` record).

## 4. Feature B: linked faces

### 4.1 What the user sees

- **Face card header:** "Loading face · EX-01 · 2 truck(s) · same loading unit as Face 2". This is plain text, part of the region's accessible name context.
- **Placing the shared loader:** the notice reads "EX-01 → Face 2 — … (also loads Face 1: same cut and block)."
- **Fleet panel button:** the label `face 1+2` already exists (`lineup.blade.php:206`). Its accessible name becomes "EX-01 (placed on Face 1 and Face 2) — put on the target face".
- **Fleet board card:** "Available · Face 1 — … · C1 / B1 B2 + Face 2 — … · C1 / B2 · since 07:00".
- **Shift Operations faces table:** the loading units cell reads "EX-01 (also Face 2)".
- **Handover snapshot:** each face gains `linked_with` (["Face 2"]). Start Shift's "Previous faces" line appends " · same loading unit as Face 2".
- **Line-up report:** the face label is suffixed " · same loading unit as Face 2".
- **Editing the cut or blocks of a linked face** so that the two faces are no longer at the same cut and block is refused with: *"LU-01 EX-01 also loads Face 1 — …. After this change the faces are no longer at the same cut and block, so it cannot work both. Choose "Move the loading unit here" to take it off Face 1 — …, or keep the cut and blocks."* The one-click button "Move the loading unit here" saves again and ends the loader's stint on the other face at the change time.
- There is no new action and no configuration. A linked face is derived: two ACTIVE faces of the shift that pass `sameWorkplaceAs` and share an open LOADER stint. A face that took over after a split is never linked to its predecessor, because the predecessor is CLOSED.

### 4.2 Who may

Placing and editing use the existing `lineup` ability. Viewing uses `view`.

### 4.3 Checks

- **LU-06** "loaders but no trucks" is not raised when any face in the linked group has trucks.
- **LU-07** is evaluated per linked group: trucks across the group against `trucks_per_loader` × the distinct loaders across the group. The title is "N trucks on Face 1 + Face 2 for M loading unit(s)". The exception key uses the group's first face (`LU-07|<shift>|f<face_id>`). Exceptions keyed on the other face are system-resolved by the ledger on the next evaluation (`app/Services/Rules/ExceptionLedger.php:54-62`).
- A singleton face keeps today's texts and keys exactly, so existing tests stand.

### 4.4 What changes and what does not

No derived figure changes. Only these exception outcomes change:

- fewer false LU-06 findings;
- LU-07 may now fire where one loader serves too many trucks across two faces.

Dot.Mines is not touched.

### 4.5 Audit and Memory

- Placement, release and edits are audited as today by `Auditable`.
- The refusal is not stored, like every refusal.
- "Move the loading unit here" ends a stint with the reason "Moved: Face 2 — … changed cut / blocks" in `ended_reason`.
- No new Memory record: a link is a derived fact, not a decision.

### 4.6 Data model

None.

### 4.7 Edge cases

- Three faces linked through one loader form one group (union-find).
- A loader shared by faces at different blocks of the same cut is not linked, because `sameWorkplaceAs` needs a shared block. It is therefore refused LU-01 at placement, as today.
- Copy-from-previous keeps the link: `place(…, move: true)` still treats a same-workplace loader as shared (`LineupService.php:336`, `:344`).
- "Move here" cannot move a loader between two linked faces, because it stays shared. The operator removes it from the face it left. This is prototype behaviour, `DT/docs/04-features.md:886`.

### 4.8 Tests

`tests/Feature/LineupLinkedFacesTest.php`:

- the link is derived;
- no LU-06 when the linked face has trucks;
- LU-07 is pooled;
- a link-breaking edit is refused, and "Move the loading unit here" works;
- the page and the fleet board show the link.

### 4.9 As built (slice 2, branch `feat/lineup-linked-faces`)

Built as §4.1 to §4.8 describe, with these differences and additions found while building and testing it:

- **The placement notice** names the other faces *this loading unit* works ("EX-02 → Face 2 — … (also loads Face 1: same cut and block)"), not every face linked to the target.
- **A move or correction of a loading unit on linked faces is one move.** The stints on the other side of the move are all partners (a move from Face 1 + Face 2 to Face 3 at 10:30 ends both stints and starts Face 3 at 10:30; correcting any of the three edges moves all three). The loading unit's linked stints on *this* side join the moment only when the machine really moved or went on standby then; two separate "Remove" clicks in the same minute stay independent. Refusals for a genuine overlap are unchanged. `lineup_corrections.partner_allocation_id` keeps the first stint that moved with the corrected one and the additive column `also_allocation_ids` (JSON) keeps the rest, so the report, the handover and Memory name them all. The correction panel says which faces change with the time when there is more than one.
- **A standby and a stint of one machine never overlap, closed or open.** A standby back-dated into a closed stint or a closed standby, a placement inside a closed standby, a correction that would drag the standby next to a move over another entry, and ending a standby at or before its start are all refused (LU-05) with the time to use instead. Ending a standby someone else already ended is LU-15.
- **Locks.** `setStandby`, `endStandby`, `release`, `closeFace` and `editFace` run in a transaction that takes the machine lock first and reads again, like `place()` and `correctTime()`. `place()`, `closeFace()` and `editFace()` also lock the face (`lockFace`: machines first, then the face, so no two callers wait on each other) so an edit of the cut or blocks and a placement cannot interleave and leave a loading unit on two unrelated faces.
- **Sentences print the room clock**, not UTC. A release or face-closing reason over 255 characters is refused like a correction reason (the inputs stop at 255).
- **Verified** with a sequential fuzz (every operation, invariants checked after each) and a 4-process run on a file-based SQLite database (WAL, DEFERRED, 5 s busy timeout): no overlapping stints or standbys, no stint ending before it starts, no lock errors.

Review fixes to slice 2:

- **A work area breaks every link.** A link survives an edit only while the edited face stays a loading face at the same cut and block; turning a linked face into a work area is refused LU-01 ("… is a work area, so the faces cannot share a loading unit") with the same "Move the loading unit here" path. `linkedFaces` only knows loading faces.
- **A placement before the face started** and **closing a face before it started or before a machine already left it** are refused (LU-05, room clock).
- **Postgres lock order:** the machine's equipment row is locked before its stints (stints first deadlocked under three or more writers), machines in id order, then the face. SQLite keeps the UPDATE first.
- **"Move the loading unit here"** has an accessible name that says which unit and face, and takes the keyboard focus when the refusal appears.
- **Known limits (not changed):** a "Remove" and a later move of the same loading unit in the same minute read as one move when the stints are corrected (the correction moves every edge at that minute); link history is not versioned (derived from current cut and blocks and open stints); "Move here" at exactly the other stint's start deletes that zero-length stint; material, activity and cut lengths are not bounded in the service; the page's `loader_ids` are passed to `place()`, which refuses a machine of another room (LU-03).

## 5. Feature C: material split (time split)

### 5.1 What the user sees and does

- On a timed line-up, if the operator edits a loading face whose material is set and changes the material, and the change time is after the face started, the save is refused with a decision. This is the prototype's PR-10 decision without the loads (`DT/js/core/services.js:297-303`). The refusal reads:
  *"LU-14 Face 1 — Loading area 1 — Overburden · C1 / B1 has been loading Overburden since 07:00. Say whether the material was wrong from the start (correct it on this face) or the face changed material at a time (a new face carries on from then; the line-up keeps Overburden until that time)."*
  - **"The material was wrong — correct it"** edits the face in place. It writes one audit row, action `override`, with the reason "Material corrected from Overburden to Interburden (wrong from the start)".
  - **"It changed at 12:00 — new face from then"** splits the face. The time is "Changes recorded at", or now. It is refused when anything other than the material was changed in the form: *"LU-05 Change only the material for a split. The new face takes over the location, cut, blocks, notes and machines as they are now. Undo the other changes, split, then edit the new face."*
- **After a split** the notice reads: *"Face 1 — … closed at 12:00; Face 2 — Loading area 1 — Interburden · C1 / B1 carries on with the same 3 machines from 12:00."*
  - The new face's header reads "· carries on from Face 1 (Overburden until 12:00)".
  - The closed-faces line already lists "Face 1 … (closed 2026-10-15 12:00: Material changed to Interburden)" (`lineup.blade.php:145`).
- During planning there is no question: the edit applies, as today.
- **Keyboard path:** after Enter on Save, the refusal region appears with the two buttons next in tab order. Tab, then Enter.

### 5.2 Who may

The `lineup` ability.

### 5.3 Rules

| Code | When | Text |
|---|---|---|
| LU-05 | Same or empty material | "Pick the new material: the new face needs a different material." |
| LU-05 | Work area | "Only a loading face changes material; for a work area, edit its activity." |
| LU-05 | Planning | "Before the shift starts, edit the face instead: nothing has happened on it yet." |
| LU-05 | Closed face | existing "is closed. Add a new face instead." |
| LU-05 | Time at or before the face started | "Face 1 — … only started at 07:00. Pick a later time." |
| LU-15 | The face was closed or changed by someone else between read and write (compare-and-set on `status = ACTIVE`) | "Face 1 — … was changed by someone else meanwhile. Check it and try again." |

**On split, in one transaction:**

1. Close the old face by compare-and-set (`status` CLOSED, `closed_at` T, `close_reason` "Material changed to Interburden[: reason]", `closed_by`).
2. Create the new face (next `seq`; same kind, location, cut, blocks and notes; new material; `active_from` T; `split_from_face_id`).
3. Move the old face's stints:
   - an open stint that began before T ends at T ("Face changed material"), and a new stint starts on the new face at T with the same role;
   - a stint that began at or after T is re-pointed to the new face;
   - a stint that ended after T but began before it is cut at T, and the remainder (T to its old end) is created on the new face with the same `ended_reason`. This is the prototype's late-split rule, `DT/js/core/services.js:359-367`.
4. Write an explicit audit row for the close.

Operator assignments are untouched.

### 5.4 What changes and what does not

- **Changes:** the line-up's faces and stints, the report and the handover (`split_from` on the face).
- **Does not change:**
  - production, loads and every derived figure (there is no per-face production in Central, I-24);
  - Dot.Mines;
  - `material` is still a label (free text, `13-shift-lineup-design.md` §5).

### 5.5 Audit and Memory

- **Audit:** a `created` row for the new face (`Auditable`, including `split_from_face_id`), `created` / `updated` rows for the moved stints, and an explicit `updated` row for the closed face with the reason. The "correct it" path writes one `override` row with all changed fields and the reason, saved quietly so that no reason-less duplicate is written.
- **Memory:** `LineupFace` + `created` + `split_from_face_id` becomes an `action` record with:
  - `action_kind` `lineup.split_face`, signature `dot-central:lineup:face-split`;
  - subject `shift` (`LoopSubject` gains `LineupFace`);
  - detail `face_id`, `split_from_face_id`, `material_before`, `material_after`, `at`.

  Ordinary face creation still maps to nothing.

### 5.6 Data model

`lineup_faces.split_from_face_id`: unsigned big int, nullable, indexed. It is added with `Schema::table` and no foreign key, following the precedent of `database/migrations/2026_10_15_000018_create_geofences_and_face_locations.php:23` and `2026_10_15_000019_add_dot_mines_operator_links.php:20-21` (column additions without constraints are safe on SQLite and Postgres).

### 5.7 Edge cases

- **Split recorded late** (T in the past): stints that ended between T and now are cut at T (§5.3).
- **Linked faces:** a shared loader carries on to the new face, which has the same cut and blocks, so the link to the partner face continues. The closed predecessor is never linked.
- **Two supervisors splitting the same face:** the second gets LU-15 from the compare-and-set.
- **A split followed by a correction:** a stint's arrival on the new face cannot be corrected to before T, because of the life of the face. To move the change time, the operator corrects the stints (or closes and re-adds the face). There is no "move the split" action in this build.
- **Night shift:** T follows the `changeAt` rules (§3.8).

### 5.8 Tests

`tests/Feature/LineupMaterialSplitTest.php`:

- the decision is refused with LU-14;
- the correct-in-place path, with its audit;
- a split with machines carried, re-pointed and cut;
- every refusal;
- a stale second split.

Page, handover and Memory tests are in Task 9.

### 5.9 As built (slice 3, branch `feat/lineup-face-split`)

Built as §5.1 to §5.8 describe, with these differences and additions found while building and testing it:

- **No new migration.** `lineup_faces.split_from_face_id` came with slice 1's migration (§5.6). Nothing stores a quantity, a tonnage or a percentage; a test lists the columns of `lineup_faces` and `lineup_allocations` to keep it so (D3).
- **Locks.** `splitFace` runs in one transaction that takes `lockMachine` for every machine that was ever on the face (id order, not only the ones it moves: another supervisor correcting a stint on this face holds the same machine lock), then `lockFace`, then `lockShiftFaces`, and reads everything again under them. Closed meanwhile: LU-15. A machine put on the face meanwhile: LU-15. The new material equal to the current one (someone corrected it): LU-05. `editFace` re-reads the face under its lock too (the LU-14 question is asked about the material as it is now) and returns the face as saved.
- **Face numbers.** The numbering of a shift's faces is serialised (`lockShiftFaces`, taken last) because there is no unique index on `(shift_id, seq)`: `addFace` and `splitFace` read the highest number inside the lock. Postgres locks the shift row `FOR NO KEY UPDATE`, which does not conflict with the key-share locks that inserting a stint takes on that row (`FOR UPDATE` would). SQLite takes its write lock first with an update, like the machine lock.
- **Bounds.** A material is 1 to 64 characters (the column is `varchar(64)`; counted in characters, not bytes): "The material is 65 characters; keep it to 64 or fewer." The rule is in `validateFace`, so it also covers a new face and an edit. The reason of a split is bounded so that "Material changed to <material>: <reason>" fits the 255-character `close_reason`: "The reason is 240 characters; with this material keep it to 228 or fewer."
- **The question is asked only about a real change.** A spelling, case or padding of the same material is no change; a face with no material takes its first one without a question; a change recorded within the minute the face started has nothing to split (the question and the split compare to the minute, because a split is recorded at an HH:MM); a work area has no material. A correction of nothing is an ordinary save. Clearing the material of a working face is a change and is audited as "… to none".
- **Machines at the split moment.** A machine whose stint was under way is cut at the time and its remainder, with the old end and the old reason, moves to the new face. A stint that began at or after the time moves unchanged. A stint that ended at or before the time stays on the old face untouched (a stint that ended exactly at the time is not cut: that would leave an empty entry). A machine on standby at the time is on no face and stays on standby with no change; one that goes on standby after the time keeps its working time before it on the new face and its standby afterwards.
- **Linked faces, defined.** A link is derived (§4.1), so the new face is linked to the same faces the old one was if and only if the rule still holds when the page is read: the new face has the same cut and blocks as the old one, takes its open loading units, and the other face is still active and still shares one of those loading units. The closed face is linked to nothing. If the other face was closed, or its loading unit left it, before or after the split, there is no link. The rule still guards the new face (an edit that leaves the shared cut and block is refused as on any linked face).
- **A split followed by a correction.** The stints on both sides of the split moment look like the two halves of one move (same machine, one ends where the other begins), so correcting either edge would drag the other with it. Both are refused by the life-of-the-face rule of slice 1 (an arrival on the new face before the split, or a departure from the old face after it), with the slice 1 sentence "Outside the life of the face: Face 2 — … ran 10:00–now. Pick a time while the face was working." Other edges of the same stints are corrected as before.
- **Clock.** The time is read by `changeAt`, so a time the clocks skipped is refused and a time they showed twice takes the later pass once it has happened. A refusal that names the time a face started says which pass when it is ambiguous: "Face 2 — … only started at 01:40 GMT, the second time the clock showed 01:40. Pick a later time."
- **The decision buttons.** After LU-14 the keyboard moves to "The material was wrong — correct it", which is followed in tab order by "It changed at HH:MM — new face from then". HH:MM is "Changes recorded at" or now when the question was asked, and the split is recorded at that time even if the click comes a minute later. After a save or a split the keyboard goes to the Edit button of that face. Any other change in the form (kind, location, cut, blocks, notes, loading units) makes the split refuse with the sentence in §5.1. The notice reads "… carries on with the same machine" or "… with the same N machines" or "… carries on from 12:00 (no machine was on it)".
- **Where it shows.** The face header ("· carries on from Face 1 (Overburden until 10:00)"), the handover snapshot (`split_from`), Start Shift's previous faces, the line-up report (the closed face's rows end with "Face changed material" and the new face's rows begin at the split time), and Dot.Memory (`lineup.split_face`, subject `shift`; the detail carries the operator's words in `reason`). The fleet board shows "since 10:00" for a carried machine: it has been on that face since then.
- **Review fixes.** The two decision buttons carry both choices: after LU-14 and "The material was wrong — correct it", a link-breaking edit is refused LU-01 and "Move the loading unit here" saves with the correction too (one click each ends in a saved face; before, each button dropped the other's flag and the two refusals alternated). Editing "Changes recorded at" while the question is on the screen withdraws the question, so the split always uses the time its button names. A correction refused at the edge of a split says why: "Face 1 changed material at 10:00 and Face 2 carries on from then, so a move across that moment cannot be corrected past it." (room clock).
- **Verified.** 55 mutations of the new rules, each applied alone (54 caught by a test; the one that survives, asking the question while planning, is equivalent because a planning face starts at the shift start). A sequential fuzz (18,000 operations of every writer, invariants after every 60) and 8-process runs (12,000 operations each, about 450 splits) on a file-based SQLite database (WAL, DEFERRED, 5 s busy timeout) and on a scratch Postgres database: no lock error, no deadlock, no overlapping stints or standbys, no inverted stint, no duplicate face number, `integrity_check` ok. Taking the locks out is detected: no numbering lock gave 55 "database is locked" errors on SQLite and duplicate face numbers on Postgres; no machine locks, or the face before the machines, gave 60 and 29 Postgres deadlocks.
- **Follow-up, not built: a unique index on `lineup_faces(shift_id, seq)`.** The numbering lock (`lockShiftFaces`) stops new duplicates, but production may already hold duplicate numbers from before it. A migration that adds the index would fail on such data and break the deploy, so it needs a production data check first (`select shift_id, seq, count(*) from lineup_faces group by 1, 2 having count(*) > 1`), a renumbering of any duplicates, then the index.
- **Known limits (not changed).** There is no "move the split" action: to change the split time the operator corrects the stints (inside the life of each face) or closes and re-adds the face. A split can be recorded only for a face of the current shift; a carried machine's history is two rows (the report shows both). Link history is not versioned. A face can still be closed at the minute it started (a face of zero length); a split cannot be recorded at that minute.

## 6. Shared time-rule fixes (part of slice 1)

1. **`place()` refuses an overlap** with an ended stint of the same machine (LU-05 "Overlaps an earlier entry: HT-01 was on Face 1 — … until 12:00. Record the change at 12:00 or later, or first correct when it left that face (select its time under "Moved off this face")."). A shared loader on a linked face is exempt. This follows the prototype's `overlapCheck`, `DT/js/core/services.js:268-276`.
2. **`place(…, move)` refuses a move recorded before the machine arrived** on the face it leaves (LU-05 "Time before the machine arrived: …"), instead of silently deleting that stint.
3. **`changeAt` refuses a time the clocks skipped** (LU-05, §3.3).

## 7. Refusal codes added

| Code | Meaning |
|---|---|
| LU-14 | A material change on a working face needs a decision |
| LU-15 | Changed meanwhile by someone else |

Both are refusals only: they are not added to `RuleEngine::CODES` (`RuleEngine.php:24`) and never become exceptions. LU-08 and LU-12 stay unused, because the `13-shift-lineup-design.md` §7 mapping reserves them for prototype rules that were not ported.

## 8. Migration (one file, additive)

`database/migrations/2026_10_18_000021_create_lineup_corrections_and_face_splits.php` creates `lineup_corrections` (§3.7) and adds `lineup_faces.split_from_face_id` (§5.6). `down()` drops both. Verify on a scratch SQLite file and on a scratch local Postgres database. Local development runs Postgres (`.env` `DB_CONNECTION=pgsql`) and production runs SQLite.

## 9. Found while reading (fixed in plan Task 1 where it touches the line-up)

- **`AuditLoopMapper::lineup` queries a column that does not exist.** It runs `where('shift_lineup_id', …)` on `lineup_allocations` and `lineup_standbys` (`AuditLoopMapper.php:190-191`). Neither table has that column (`2026_10_15_000017_create_lineup_tables.php:50-81`). The effect differs by database:
  - On SQLite (production), an unresolved double-quoted identifier is read as a string literal. Verified on 2026-10-18: the counts are silently 0.
  - On Postgres (local pilot) the query throws, `fromAudit` swallows it (`MemoryRecorder.php:60-62`), and the confirmation record is lost.
- **The mapper discards its own detail.** `base(...) + ['detail' => ...]` keeps the *left* `detail` (PHP array union), so version, faces, placed and standby never reach Memory (`AuditLoopMapper.php:196-197`).
  - Task 1 fixes both defects for the line-up record.
  - The same union pattern also drops detail in `exception()`, `downtime()` and `assignment()` (`AuditLoopMapper.php:100-205`). It is out of I-29's scope and reported separately.

## 10. Documents to update when built

- `13-shift-lineup-design.md` §6 (last bullet) and §11: corrections, linked faces and the split are built, with a pointer to this document.
- `10-outstanding-issues.md` I-29: "Built (slices 1-2 live, slice 3 in PR); closes when slice 3 merges". The "breakdowns at shift change" part is closed as not built (D-6).
- `05-feature-matrix.md` F-29: add the three features and the tests.
