# Dot.Central: Dot.Memory Integration

**Date:** 2026-10-16. Owner decision: option 3 of the design (`docs/superpowers/specs/2026-10-16-dot-memory-integration-design.md`): record the control room's decisions and outcomes, show Memory's context back on alerts and exceptions, and report Dot.Central's own integration incidents. Plan: `docs/superpowers/plans/2026-10-16-dot-memory-integration.md`.

## 1. What this is

Dot.Memory is the ecosystem's memory (ADR-0015 in Dot.Brain: Memory remembers, Brain thinks, the executor acts). Dot.Central joins the loop as an executor that reports: every decision an operator or supervisor makes, the actions taken and whether Dot.Mines accepted them, and the outcomes, in Memory's own vocabulary. In return the control room sees, under an alert or an exception, what was tried before on that machine and what worked.

Nothing new is typed. Every record is derived from the audit log, the Dot.Mines mirror or the line-up push result, and Memory never blocks an operator: writes wait in a local outbox and are sent by a queued job; recall has a three-second timeout and a ten-minute cache.

## 2. The connection (per team)

One connection per team, configured by an administrator on the Health page (`/health`, card "Dot.Memory"): base URL (defaults to the ecosystem registry), API token, enabled. The token is stored with the `encrypted` cast, redacted from audit rows and never shown again.

Minting the token, two ways (Dot.Memory PR `feature/service-tokens`):

1. Service account (recommended): generate the plaintext locally, hash it, and run Dot.Memory's `provision-service-token.yml` with `service=dot-central` and that hash. The workflow prints a token id; the bearer value is `<id>|<plaintext>` and never exists on the server. Rotation is the same run again.

```bash
plain=$(openssl rand -hex 32); printf '%s' "$plain" | shasum -a 256; echo "plaintext: $plain"
```

2. Personal token: sign in to Dot.Memory, open Profile, then API Tokens, and create a token. It works only while that person's account exists.

Dot.Central calls `GET /api/user` on Test credential.

Statuses: `unverified` (saved, not tested), `verified`, `unreachable` (the last write or test could not reach Memory; writes keep queuing), `credential_invalid` (Memory answered 401 or 403; nothing is sent until the token is replaced).

Readouts on the card: status, last write, outbox pending, outbox failed / stuck, open incidents, last recall.

## 3. What is recorded

Vocabulary shared with Dot.Mines and the guardian: a machine is subject type `equipment` with the Dot.Mines machine id; anything without a machine belongs to `control_room:<id>`; shifts and line-ups are `shift:<id>`. `platform` is always `dot-central`.

| Control-room event | Stage | Fields |
|---|---|---|
| Alert mirrored for the first time | observation | signature `dot-central:alert:<severity>`; title, source |
| Operational exception raised | observation | signature `dot-central:exception:<rule code>`; title, message, outcome |
| Exception explained | decision | recommendation = the explanation; confidence 1.0; risk 0.9 / 0.5 / 0.2 by severity; autonomy `approve`; requires approval |
| Exception approved / rejected | action | `exception.approve` / `exception.reject`, approval status approved / rejected |
| Exception resolved / dismissed | outcome | verdict improved / inconclusive; measure `exception.status` |
| Alert acknowledged / assigned / escalated / resolved | action | `alert.acknowledge` etc.; execution status succeeded when pushed to Dot.Mines, refused when Dot.Mines refused, pending when not pushed |
| Alert cleared (by an operator or by Dot.Mines) | outcome | verdict improved; measure `alert.time_to_clear_minutes` |
| Stoppage classified | decision + action | recommendation = the reason; autonomy `execute`; `downtime.classify` |
| Line-up confirmed | decision + action | `line-up v<n>`; faces, placed and standby counts; `lineup.confirm` |
| Operator assignment | action | `operator.assign`; executor `dot-mines` when pushed; succeeded / pending / refused (with Dot.Mines' blockers) |
| Shift started | observation | signature `dot-central:shift:started` |
| Shift closed | outcome | measure `shift.stoppage_hours`; verdict improved / unchanged / worsened against the previous closed shift, inconclusive for the first |
| Shift approved / reopened | action | `shift.approve` / `shift.reopen` |

Records are keyed by subject state (`dot-central:alert:<id>:resolve`, `dot-central:exception:<id>:explained`), so a model's own audit row and the workflow's explicit row collapse into one record and a retry never duplicates. Reasons and explanations travel in `detail`, which Memory stores encrypted. System alerts about the platform itself are not recorded.

## 4. Recall

Under each alert card, each exception card and on the equipment page, a strip reads, in the control-room language: "Memory: seen before 4 times since 12 Aug · tried acknowledge (improved), resolve (improved) · worked best: resolve", or "no history for this machine", or "Dot.Memory unreachable, last fetched 14:02". It loads after the page, caches ten minutes per subject (two minutes when unreachable) and never raises. The strip appears only when the team has a usable connection.

## 5. Platform incidents

`memory:report-incidents` runs every five minutes and reports to Memory's ops incident store, opening an incident while a condition holds and resolving it when it clears:

| Condition | Signature | Severity |
|---|---|---|
| A Dot.Mines connection is credential-invalid | `dot-central:mines_connection:credential_invalid` | sev2 |
| The last three sync runs of a connection failed | `dot-central:mines_sync:failing` | sev2 |
| Queue heartbeat older than 15 minutes while the scheduler runs | `dot-central:queue:stalled` | sev1 |
| Memory outbox rows that gave up after 20 attempts | `dot-central:memory_outbox:stuck` | sev3 |

Webhook silence is not a condition: no event is a normal state. Incidents are sent through the same outbox, so Memory being down delays them rather than losing them.

## 6. Processes and failure modes

- Outbox: `FlushMemoryOutbox` is dispatched after every write and by `memory:flush` every minute; sent rows are pruned after 30 days (`memory:prune`). Backoff 1, 5, 15, 60 minutes; a refused payload (HTTP 422) is given up on and counted as stuck; a rejected credential stops the team's sends and marks the connection.
- The whole integration is inert without a connection: no rows, no requests, no strip.
- Configuration: `config/memory.php` (`MEMORY_REQUEST_TIMEOUT`, `MEMORY_CONTEXT_TIMEOUT`, `MEMORY_CONTEXT_CACHE_MINUTES`, `MEMORY_OUTBOX_RETENTION_DAYS`).

## 7. Tests

`MemoryConnectionModelTest`, `MemoryClientTest`, `MemoryOutboxTest`, `AuditLoopMapperTest` (one method per mapping row), `MirrorHooksTest`, `MemoryHealthCardTest`, `MemoryContextTest`, `IncidentReporterTest`.

## 8. Not done

Recall is cross-platform: the context query carries no platform filter, so Dot.Mines' own records for the same machine (Dot.Mines branch `feature/memory-loop`) appear in the strip once that side is live (I-33). Dot.Brain decisions flowing back into the control room are the next loop, once Memory holds history.
