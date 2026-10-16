# Dot.Central and Dot.Memory: decisions, outcomes, recall and platform incidents

**Date:** 2026-10-16. **Owner decision:** option 3: record the control room's decisions and outcomes into Dot.Memory's intelligence loop, show Memory's context back on alerts and exceptions, and report Dot.Central's own integration failures to Memory's ops incident store.

## 1. What Dot.Memory offers (verified from its code)

Production: `https://memory.infodot.co.za`, Sanctum bearer tokens (Jetstream API tokens on a Dot.Memory user).

- Intelligence loop, `POST /api/intelligence/{events,decisions,actions,outcomes}`: every record carries `loop_id`, `event_id` (idempotent: a repeated `event_id` returns the existing record), `platform`, `source`, `subject_type`, `subject_id`, `subject_label`, `team_id`, `user_id`, `occurred_at`, `signature`, `detail` (array, stored encrypted). Stage fields: decision `recommendation`, `confidence` 0..1, `risk` 0..1, `autonomy_level` in observe/recommend/approve/execute/autonomous, `requires_approval`; action `action_kind`, `executor_platform`, `mechanic_ref`, `approval_status` in not_required/pending/approved/rejected, `execution_status` in pending/succeeded/failed/refused/rolled_back; outcome `verdict` in improved/unchanged/worsened/inconclusive, `measure`, `value_before`, `value_after`.
- Context, `GET /api/intelligence/context?subject_type&subject_id&signature&platform`: `known`, `first_seen`, `last_seen`, `observations`, `timeline`, `what_was_tried` (actions with their outcome verdicts), `what_worked` (per signature), `recurrence`, `gaps`.
- Ops incidents, `POST /api/ops/incidents`, `PATCH /api/ops/incidents/{uid}`, `GET /api/ops/recall?platform&signature`: `incident_uid`, `platform`, `environment`, `detection_source`, `signature`, `component`, `severity` sev1..sev4, `status` open/remediating/resolved/escalated/rolled_back, `detected_at`, `resolved_at`, `record`.
- Reference client: Dot.Brain's guardian (`services/guardian/src/memory-client.js`): bearer token, 15 s timeout, failed writes go to a local outbox and are retried; recall failure degrades to "no history".

## 2. Principles

1. Memory never blocks an operator. Every write goes to a local outbox in the same transaction as the action and is sent by a queued job; recall has a 3 s timeout and a 10 minute cache and degrades to "no history".
2. Nothing new is typed. Every record is derived from what the control room already records (the audit log, the mirror, the line-up push result).
3. One subject vocabulary shared with Dot.Mines and the guardian: `subject_type` `equipment` with `subject_id` = the Dot.Mines machine id, so Memory's context for a machine is cross-platform; `control_room:<id>` when no machine is involved; `shift:<id>` for shift outcomes.
4. Idempotent: `event_id` is derived from the audit row or the source event, so retries never duplicate.
5. Tokens stay out of source, logs and chat; stored encrypted like the Dot.Mines token; `MinesSynchroniser::fail()`-style sanitising on errors.

## 3. Connection (per team)

Table `memory_connections`: `team_id` (unique), `base_url`, `api_token` (encrypted), `enabled`, `status` (unconfigured / verified / credential_invalid / unreachable), `last_verified_at`, `last_write_at`, `last_context_at`, `last_error`, timestamps. `Auditable` (token redacted). Administrators configure it on the Health page (`/health`), new "Dot.Memory" card: base URL, token, Test credential (`GET /api/user`), enable/disable. Readouts: status, last write, outbox pending and failed counts, last context fetch, open incidents reported.

`MemoryClient` (`App\Services\Memory\MemoryClient`): `whoAmI()`, `record(stage, payload)`, `context(subjectType, subjectId, signature)`, `storeIncident(payload)`, `updateIncident(uid, payload)`; 401/403 marks the connection `credential_invalid`; connection errors mark `unreachable` without disabling it. `MemoryClientFactory::for(team)` returns null when unconfigured or disabled.

## 4. Writes: the outbox

Table `memory_outbox`: `team_id`, `stage` (event/decision/action/outcome/incident/incident_update), `payload` (json), `event_id`, `attempts`, `last_error`, `next_attempt_at`, `sent_at`, timestamps. `MemoryRecorder::queue(...)` inserts; `FlushMemoryOutbox` job runs after every insert (dispatched) and every minute from the scheduler as a backstop; sends rows in order, marks `sent_at`, on failure increments `attempts` with backoff 1, 5, 15, 60 minutes and gives up after 20 attempts (row kept, visible on Health as failed). Rows older than 30 days and sent are pruned by the scheduler.

## 5. What gets recorded (mapping)

Source: `Audits::record()` calls `MemoryRecorder::fromAudit($entry)` after the audit row exists; two source events have no audit row (mirror writes) and call the recorder directly. Nothing is recorded when the team has no enabled connection.

| Control-room event | Stage | Fields |
|---|---|---|
| Alert mirrored for the first time (sync or webhook) | observation | subject = equipment or room; signature `dot-central:alert:<severity>`; detail title, source |
| Operational exception raised (automatic) | observation | signature `dot-central:exception:<rule_code>`; detail title, message, outcome |
| Exception explained | decision | recommendation = the explanation; confidence 1.0 (a person decided); risk by severity (CRITICAL 0.9, WARNING 0.5, REVIEW 0.2); autonomy `approve`; requires_approval true |
| Exception approved / rejected | action | action_kind `exception.approve` or `exception.reject`; executor `dot-central`; approval_status approved/rejected; execution succeeded |
| Exception resolved / dismissed | outcome | verdict improved (resolved) or inconclusive (dismissed); measure `exception.status` |
| Alert acknowledged / assigned / escalated / resolved | action | action_kind `alert.acknowledge` etc.; execution_status from the Dot.Mines push outcome: succeeded when pushed, `refused` when Dot.Mines refused, `pending` when not pushed |
| Alert cleared in Dot.Mines (mirror) | outcome | verdict improved; measure `alert.time_to_clear_minutes`; value_after = minutes from trigger to clear |
| Stoppage classified | decision + action | recommendation = reason label; autonomy `execute`; action_kind `downtime.classify`; detail counts toward availability |
| Line-up confirmed | decision + action | recommendation `line-up v<n>`; detail faces, placed, standby, no-operator counts; action_kind `lineup.confirm` |
| Operator assignment pushed | action | action_kind `operator.assign`; executor `dot-mines`; execution succeeded / refused (with blockers in detail) / pending (not pushed) |
| Shift started | observation | subject `shift:<id>`; detail shift type, open issues carried forward |
| Shift closed | outcome | subject `shift:<id>`; measure `shift.stoppage_hours`; value_before = previous closed shift's stoppage hours, value_after = this one; verdict improved / unchanged / worsened by comparison, inconclusive when there is no previous shift |

`loop_id` groups a thread: `dot-central:alert:<id>`, `dot-central:exception:<id>`, `dot-central:shift:<id>`, `dot-central:lineup:<id>`. `user_id` is the Dot.Central actor id; `team_id` the Dot.Central team id. Reasons and explanations go in `detail`, which Memory stores encrypted.

## 6. Recall on the alert and exception cards

A lazy Livewire component `MemoryContext` under each alert card and exception card (and on the equipment page): asks `context` for the card's subject and signature, cached 10 minutes per subject+signature, 3 s timeout. Shows, in the control-room language: "Seen before: 4 times since 12 Aug", "Tried: acknowledge and resolve (improved 3 of 3)", "Worked best: <action kind>", or "No history in Dot.Memory", or "Dot.Memory unreachable" with the time of the last successful fetch. Never a spinner longer than the placeholder; failures never raise.

## 7. Platform incidents

`memory:report-incidents` runs every five minutes from the scheduler and keeps `memory_incidents` (`team_id`, `incident_uid`, `signature`, `component`, `severity`, `status`, `detected_at`, `resolved_at`). Signatures, all `dot-central:` prefixed:

| Condition | Signature | Severity |
|---|---|---|
| A Dot.Mines connection is `credential_invalid` | `mines_connection:credential_invalid` | sev2 |
| The last three sync runs of a connection failed | `mines_sync:failing` | sev2 |
| Queue heartbeat older than 15 minutes while the scheduler runs | `queue:stalled` | sev1 |
| Memory outbox has rows that gave up (20 attempts) | `memory_outbox:stuck` | sev3 |

Opens an incident when a condition appears, resolves it when it clears; `incident_uid` = `dot-central:<team>:<signature>:<component>:<detected date>`. Reported through the same outbox (`incident`, `incident_update` stages). Webhook silence is not reported: no event is a normal state and would produce false positives.

## 8. Not in scope

Writing to Dot.Memory from the agent hub; recall anywhere other than alerts, exceptions and the equipment page; replacing the audit log; Dot.Brain decisions flowing back into Dot.Central (that is the next loop, once Memory has history).

## 9. Documents and tests

`docs/dot-central/14-dot-memory-integration.md` carries the operational facts (how to mint the token, what is sent, what Health shows). PHPUnit covers the mapping (one test per row of §5 using `Http::fake()` and the outbox), outbox retry and give-up, client error classification, the Health card, the recall component states, the incident reporter transitions, and `ProhibitedReferencesTest` still scans everything.
