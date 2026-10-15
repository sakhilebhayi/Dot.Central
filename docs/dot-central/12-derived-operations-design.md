# Dot.Central — Derived Operations Design (prototype features on Dot.Mines data)

**Owner instruction (2026-10-15):** the features and pages of the `DT/` prototype must be reflected in Dot.Central, but most if not all data must be derived from Dot.Mines; control-room operators have little input and act only where necessary.
**Status:** design for Increments 3–6, implemented from this date. Resolves decisions D2 (Dot.Central owns shifts), D3 (no manual production capture) and D4 (line-up becomes a derived fleet board) in `03-architecture.md`.

## 1. Principle: observe, derive, act

| Prototype (manual capture) | Dot.Central (derived) | Operator input that remains |
|---|---|---|
| Operator types closing hour-meter readings | Opening and closing readings are the Dot.Mines metric at or before shift start and end (`operating_hours`, provider clock) | none; may **explain** an exception |
| Operator ticks loads per truck per hour | Loads are deltas of the cumulative `load_count` counter in metrics; tonnes are Dot.Mines daily production records | none |
| Operator records downtime with a reason | Stoppages are **observed**: transitions of Dot.Mines status (offline, maintenance) and engine-running captured by sync | **classify** a stoppage with a reason code |
| Operator builds the line-up (faces, trucks, operators) | The **fleet board** shows where each machine is (mine area, last geofence, state) from Dot.Mines | none |
| Operator starts/closes the shift, writes handover | Shift is proposed from the room's shift types; the handover pack is computed | **confirm start**, **close with notes**, **acknowledge handover**; supervisor approves / reopens |
| Operator explains, supervisor approves exceptions | Rules run over derived data on every sync and at review | **explain / approve / reject / dismiss** |
| Alerts typed in | Alerts mirrored from Dot.Mines and raised by Dot.Central rules | **acknowledge / assign / escalate / resolve** |
| Administration catalogues | Per-room configuration | administrator edits shift types, reason codes, thresholds |

Nothing an operator enters changes a Dot.Mines fact. Every operator action is audited with reason text.

## 2. Derived data added to the mirror

- **`equipment_metrics`** (replaces `equipment_hour_readings`): one row per Dot.Mines metric fetched — `recorded_at`, `operating_hours`, `idle_hours`, `fuel_level`, `speed`, `engine_running`, `load_count`, `payload_cumulative`, `odometer`, `ingested_at`. The hours job fetches up to 24 h of history per machine per run (one call), so shift derivations have the readings they need.
- **`equipment_status_events`**: every observed change of `mines_status` or `engine_running`, with `observed_at` (provider clock where known, else sync time), `source` (`sync`, `webhook`, `metric`). Stoppages are intervals built from these.
- **`production_records`** mirror from the `/sync` production scope: `mines_record_id`, `equipment_id`, `record_date`, `shift`, `quantity`, `unit`, `mines_status`, `sync_version`.
- **`downtime_classifications`**: operator classification of an observed stoppage (equipment, started_at, ended_at nullable, reason code, note). Links the human reason to the machine fact without altering it.

## 3. Shifts

- `shift_types` per control room (code, name, start, end, crosses midnight), `shifts` (`PLANNED → OPEN → IN_REVIEW → CLOSED → APPROVED`, `REOPENED`), `shift_handovers` (notes, snapshot, issued/acknowledged), `shift_unit_summaries` (persisted at close; recomputed on reopen).
- `ShiftDerivation` computes, for the shift window and each non-retired machine: opening/closing hours and their clocks, run hours, idle hours delta, loads delta, production tonnes (records whose date and shift map to the window), stoppage minutes by observed cause, final state, freshness. Live while open; frozen on close.
- Close is refused while CRITICAL exceptions are unexplained; the handover pack is the frozen summary plus open items and notes.

## 4. Rules (first set, all over derived data; thresholds per room)

| Code | Condition | Severity → outcome |
|---|---|---|
| TEL-01 | Expected machine has no usable telemetry during the shift (Unknown) | CRITICAL → MISSING |
| TEL-02 | Telemetry stale (> 2× cadence) for longer than `stale_minutes` | WARNING → SUSPICIOUS |
| EQ-01 | Offline for longer than `offline_minutes` during the shift | WARNING → REQUIRES_EXPLANATION |
| EQ-02 | Operating hours decreased between readings | CRITICAL → CONFLICT |
| EQ-03 | Haul unit ran ≥ `zero_loads_hours` with no load-count change | REVIEW → REQUIRES_EXPLANATION |
| EQ-04 | Idle hours grew by ≥ `idle_hours_threshold` while engine running | REVIEW → REQUIRES_EXPLANATION |
| EQ-05 | Day has run hours but no Dot.Mines production record | INFO |
| DT-01 | Observed stoppage ≥ `classify_after_minutes` with no classification | REVIEW → REQUIRES_EXPLANATION |
| AL-01 | Critical alert unacknowledged for ≥ `unack_minutes` | WARNING |
| SH-01 | Shift past its end and not closed | WARNING |
| SH-02 | Shift closed with machines Unknown | INFO (recorded on the handover) |

Findings are keyed `(rule, shift, equipment)`; re-evaluation refreshes open findings, resolves ones no longer true, reopens recurrences; explained/approved/dismissed items are left alone.

## 5. Pages (prototype → Dot.Central)

Overview (landing) · Start Shift · Fleet board (line-up) · Shift Operations · Engine Hours · Production · Downtime · End Shift & Handover · Exceptions · Approvals · Data Quality · Alerts · Reports · Equipment · Administration · Audit Log · Health. All read from the mirror and derivations; the action surface is the list in §1.

## 6. Out of scope / not derivable today

Material and cut/block per load (Dot.Mines has no load-level material), operator-per-machine (no operator identity in the Dot.Mines API), telemetry-vs-manual tally reconciliation (no manual tally exists). These are recorded in `10-outstanding-issues.md`.
