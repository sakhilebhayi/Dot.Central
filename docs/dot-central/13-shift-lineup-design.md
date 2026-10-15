# Dot.Central — Shift Set-up and Line-up Design

**Date:** 2026-10-15
**Status:** design for the owner's instruction of 2026-10-15: *"user logs in → sets up shift → sets up line-up → operator-to-machine assignment → then everything else follows (dashboard, handover); users must see the outstanding issues; the system must show which machines are available for operating and what issues each machine has, like the DT prototype."* Reverses the deferral of D4 (`03-architecture.md`): the line-up is built now, on top of the derived data of `12-derived-operations-design.md`.

## 1. What the DT prototype does (verified in `DT/js/core/lineup.js`, `DT/js/ui/screens/lineup.js`, `DT/docs/04-features.md` §Shift Line-up)

- At shift start the operator places every machine: a **face** (loading unit + material + cut + blocks) gets its trucks; **work areas** (dozing, drilling, roads…) get support machines; every machine gets an **operator**; a machine that is not working gets a **reason** (standby / no operator / off site) or is **down** (breakdown, service).
- Line-up status DRAFT → CONFIRMED (versioned). Before the shift starts and while unconfirmed, changes are *planning* (effective from shift start, a move replaces); afterwards every move is *timed*.
- Machines still down at the end of the previous shift are decided once ("Breakdowns at shift change"); faces are copied from the previous shift; usual operators of the crew are filled in.
- Rules AL-01…AL-13: refusals (already on another face, down, wrong type for the role, operator on two machines, bad time/cut/block) and review items (free machine with no reason, placed machine without operator, ran but never placed, line-up never confirmed, face without trucks / loader, too many trucks per loader).
- The dashboard's workflow strip ticks "Line-up" when confirmed; Shift Operations shows the faces; the handover lists units still down and on standby.

## 2. What Dot.Mines provides (verified in `~/Dot/mines` on 2026-10-15)

| Need | Dot.Mines | Consequence for Dot.Central |
|---|---|---|
| The fleet on the shift | `Machine` mirrored (`equipment`) | Every non-retired mirrored unit is on the shift. No "expected list" to type. |
| Machine down | `machines.status` offline / maintenance, observed as status events; plus the operator's classification | DOWN is **derived**; there is no "breakdowns at shift change" decision. A unit that Dot.Mines still reports stopped is still down. |
| Machine class (loader / haul / support) | `machine_type` is a brand, but Dot.Mines normalises it with `EquipmentType` for licences; **since 2026-10-15 (`feature/central-operators-api`) `equipment_type` is on the machine resource and the fleet scope** | `equipment.role_class` comes from `equipment_type` (`role_class_source = mines`); the heuristic only runs for `other`; administrator override still wins. |
| Operators | `Operator`, qualifications/medicals/trainings, `OperatorMachineAssignment` with an eligibility gate (`OperatorAssignmentService`); **since 2026-10-15 (`feature/central-operators-api`) published through `operators` / `operator_assignments` sync scopes, `/operators`, `/machines/{id}/operator-assignments` and `operator.*` webhooks** | The room's register is **mirrored from Dot.Mines** (linked to locally added people by employee number); `equipment.mines_operator_*` holds who Dot.Mines has on each machine; a line-up assignment of a Dot.Mines operator is **pushed through Dot.Mines' gate** and refused with the blockers when Dot.Mines refuses; an outage keeps it locally with a note. Locally added operators (unknown to Dot.Mines) still work, flagged as local. |
| Mine areas | `MineArea` in the `reference` sync scope (already pulled, only applied as a name on equipment) | New `mine_areas` mirror table; a face may point at one. |
| Faces, cut, blocks, material | `MinePlanUpload` exists but is not in the API; production records carry quantity + unit, no material | Faces are the control room's decision: kind, optional Dot.Mines area, optional material / cut / blocks (free text, validated against optional room lists). |

## 3. Principle

The **line-up is the one place the control room decides** (where each machine works, who operates it, why a machine is not working). Everything the line-up needs to know — which machines exist, which are down, which are reporting, engine hours — is derived from Dot.Mines and never retyped. Nothing in the line-up edits a Dot.Mines fact.

## 4. Workflow (what the user does, in order)

1. **Log in.** Landing = Operational Overview.
2. **Start Shift** (operator / supervisor). The next shift is proposed; the card "Handover from <previous>" shows what is **still outstanding**: units still down (derived at the previous shift's end), units on standby, open exceptions carried, previous faces. Starting the shift acknowledges the handover and opens the **Line-up**.
3. **Shift Line-up.** "Copy from <previous shift>" recreates its faces, places the machines that are not down now, and fills operators from the previous shift. The operator adjusts by clicking machines, picks an operator per placed machine, gives every free machine a reason (Standby / No operator / Off site) or leaves it to be flagged, and **confirms**. The "Checks before confirming" card lists what is still open (LU-06…LU-13).
4. **Everything else follows:** Overview (line-up status, faces, operators), Shift Operations (faces table), Fleet board / **Availability** (which machines are available for operating, each with its issues), Engine Hours, Production, Downtime, Exceptions, End Shift & Handover (faces, operators, units still down / on standby in the snapshot).
5. **Next shift** starts from that handover.

## 5. Model (new tables, all Auditable, all scoped to the control room through the shift)

- `operators` — `control_room_id, mines_operator_id?, employee_number, first_name, last_name, preferred_name?, tag_id?, is_active, notes?`. Unique (room, employee_number). Administered under Administration → Operators.
- `equipment.role_class` (`LOADER | HAUL | SUPPORT | null`), `equipment.role_class_source` (`auto | manual`). Heuristic at sync when unset (see §9); administrators change it on the Administration → Equipment roles tab with a reason.
- `mine_areas` — `control_room_id, mines_area_id, name, status, sync_version, last_synced_at` (mirror; not Auditable).
- `shift_lineups` — `shift_id` (unique), `status DRAFT|CONFIRMED`, `version`, `confirmed_by/at`, `copied_from_shift_id?`.
- `lineup_faces` — `shift_id, seq, kind LOADING|AREA, location_source geofence|area|default, location_ref?, location_name, mine_area_id?, material?, activity?, cut?, blocks json, notes?, status ACTIVE|CLOSED, active_from, closed_at?, close_reason?, closed_by?`. **Where a face is** (owner instruction 2026-10-15) comes from one list at a time (`LocationCatalogue`): the room's Dot.Mines **geofences** (mirrored into `geofences` from `GET /api/v1/geofences` on every sync; dropped ones retired); when there are none, its Dot.Mines **mine areas**; when neither is configured, **default mining names** (Loading area 1–4, Dumping area 1–3, Stockpile 1–2, ROM pad, Crusher feed, Waste dump 1–2, Haul road 1–2, Pit ramp 1–2, Workshop, Fuel bay, Water point). A key outside the current list is refused (LU-05), so a face never mixes a live geofence with a made-up name; the list switches to geofences on the next sync after they are set up in Dot.Mines.
- `lineup_allocations` — `shift_id, face_id, equipment_id, role LOADER|HAUL|SUPPORT, from_at, to_at?, ended_reason?, created_by, ended_by?`. A machine has at most one open allocation per shift (a loader may hold two only on faces at the same cut sharing a block, as in the prototype).
- `lineup_standbys` — `shift_id, equipment_id, downtime_reason_id` (category STANDBY / NO_OPERATOR / OFFSITE), `from_at, to_at?, note?, created_by`. Ends automatically when the machine is placed.
- `shift_operator_assignments` — `shift_id, equipment_id, operator_id, from_at, to_at?, assigned_by`. One open row per machine and one per operator per shift (LU-04 refusal, "Move operator" ends the other).

Line-up state per machine at a reference time (now clamped into the shift): **DOWN** (derived: Dot.Mines status offline/maintenance, or an availability-counting classification covering now) → **PLACED** (open allocation) → **STANDBY** (open standby) → **FREE**.

## 6. Time rules

- *Planning*: line-up DRAFT and now < shift start → every change is effective from shift start; a move replaces (the old row is deleted, audited "(planning)").
- *Timed*: shift started or line-up confirmed → `from_at` = now clamped into the window, or the time the operator sets in "Changes recorded at" (HH:MM inside the shift, not in the future; LU-05 otherwise). A move ends the old allocation at that time.
- Arrival / departure corrections and material splits of the prototype are **not built** (I-29).

## 7. Rules (added to `RuleEngine`, group "Line-up"; DT codes AL-xx become LU-xx because AL-01 is already the alert rule)

| Code | Severity → outcome | When |
|---|---|---|
| LU-01…LU-05 | refusal at write (ValidationException with the code) | already on another face (offer "Move here"); machine is DOWN; wrong role for the class; operator already on another machine (offer "Move operator"); bad time / closed face / loading face without a loading unit when trucks are added |
| LU-06 | WARNING → SUSPICIOUS | loading face with loaders but no trucks, or trucks but no loader |
| LU-07 | WARNING → SUSPICIOUS | trucks on a face > `trucks_per_loader` (room setting, default 8) × loaders |
| LU-09 | REVIEW → REQUIRES_EXPLANATION | machine FREE: not placed, not down, no standby (once the shift has started or the line-up is confirmed) |
| LU-10 | REVIEW → REQUIRES_EXPLANATION | placed machine without an operator |
| LU-11 | REVIEW → SUSPICIOUS | shift over: machine ran (run hours > 0) but was never allocated |
| LU-13 | REVIEW → REQUIRES_EXPLANATION | shift started and the line-up has faces but is not confirmed |

All are upserted / system-resolved by the existing ledger; none blocks the close (only CRITICAL does).

## 8. Pages

- **Shift Line-up** (`/shifts/{shift}/lineup`, Livewire): header (label, DRAFT / CONFIRMED badge, "Changes recorded at" when timed, Copy from previous, + New face / work area, Fill operators, Confirm / Re-confirm, Print); KPI strip (faces, trucks placed, loaders placed, operators, down, standby); face cards (target face, machine table with role / machine / operator select / since / ✕, quick-add by name); fleet panel grouped by role class with filters All / Free / Placed / Down-standby; "Not on the line-up" with Standby / No operator / Off site buttons; "Checks before confirming".
- **Fleet board → Availability**: each machine card gains role class, line-up state (face n / free / standby / down), operator, and an issues line (open alerts, open exception codes, stale / unknown telemetry, unclassified stoppage); new filters Available / Needs attention; readouts Available / Placed / Free / Down / Standby.
- **Start Shift**: handover card gains "Still down at previous end", "On standby", "Open exceptions carried" (top 12), "Previous faces". After "Start shift" → Line-up.
- **Workflow strip**: Start shift · Line-up (confirmed) · Fleet board · Engine hours · Production · Downtime · Exceptions (no CRITICAL) · End shift.
- **Shift Operations / Overview**: faces table (material / work, loaders, trucks, support, cut / blocks) and line-up status readout.
- **Handover snapshot**: adds `faces`, `operators`, `standby_now`.
- **Reports**: "Shift line-up" (faces, machines, operators, from / until; machines not on a face with their reason).
- **Administration**: tabs "Operators" and "Equipment roles".

## 9. Role-class heuristic (default only; administrators override)

HAUL when model / manufacturer / name matches `/\b(adt|a\d{2}[a-z]?|b\d{2}[a-z]?|hd\d|777|785|haul|truck|dumper)\b/i` or `capacity` ≥ 15 t; LOADER when it matches `/\b(ex\d|pc\d|ec\d|excavator|shovel|loader|wa\d|l\d{3}|fel|tlb)\b/i`; otherwise SUPPORT. The source is recorded so an auto value is re-derived on later syncs until an administrator sets it.

## 10. Permissions

`operate` (operator, supervisor): faces, placements, standby, operators, confirm. `manage` (administrator): operator register, equipment roles, room lists (materials, cuts, blocks). Managers read everything. Closed shift (CLOSED / APPROVED) refuses every line-up change (SH-04 text) until reopened.

## 11. Out of scope / not derivable (recorded in `10-outstanding-issues.md`)

- Operator identity, qualifications and medicals from Dot.Mines (I-28: Dot.Mines API additions proposed: `operators` + `operator_assignments` sync scopes; `POST /machines/{id}/operator-assignments` that calls `OperatorAssignmentService`).
- Pushing the line-up's operator assignment back to Dot.Mines (depends on I-28).
- Arrival / departure corrections, material splits, linked faces (I-29).
- Materials with load factors and tonnage valuation (needs Dot.Mines material data; I-24).
