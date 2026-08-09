# Stale-Session Alert Scanning + Approval Gate — Design Spec

## Context

This spec is part of the ecosystem-wide Autonomy & Owner-Independence
Program (per [brain.autonomy.md](https://github.com/sakhilebhayi/Dot.Brain/blob/main/brain.autonomy.md)
§2), applied here to Dot.Central.

**The platform audit was checked against real code and found accurate.**
[`Dot.Brain/platforms/dot-central.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-central.md)
reports zero background automation — confirmed directly: `routes/console.php`
has only the stock `inspire` command, `bootstrap/app.php` has no
`->withSchedule()` closure, and `app/Jobs` doesn't exist. Every
state-changing endpoint in the mining-dispatch domain runs synchronously
inside an authenticated human HTTP request.

**This is not an invented gap.** The audit's own Gap Summary names almost
exactly this: *"a scheduled Artisan command... that recomputes alert
thresholds... on a timer, running end-to-end without requiring [a human]
to initiate or approve it."* The mining-dispatch domain (`ControlRoom`/
`DispatchDecision`/`Alert`/`OperatorSession`, added 2026-08-01) already has
the fields to build this from — `OperatorSession.started_at`/`ended_at`,
`DispatchDecision.decided_at`, `Alert.severity`/`triggered_at` — without
inventing a live Dot.Mines telemetry feed that doesn't exist
(`mines_site_ref` is a bare string reference per wiki.md §3a, not a live
integration).

## Goal

Build the alert-scanning job the audit names, and gate the one real
decision it can surface: an operator session with no dispatch activity for
an extended period might mean a forgotten, un-ended shift — but ending it
is a real action with the same weight `OperatorSessionController::update()`
already treats it as, so the system proposes, never executes.

## Design

### 1. `app/Console/Commands/ScanStaleSessions.php` (`central:scan-stale-sessions`)

This platform's first scheduled command. For every `OperatorSession` that
is active (`started_at` set, `ended_at` null):

```
silentFor = now()->diffInHours(max(startedAt, latestDispatchDecisionInRoom.decided_at ?? startedAt), absolute: true)

if silentFor >= 4 hours and no uncleared Alert titled STALE_SESSION_ALERT_TITLE exists for this control room:
    raise Alert(control_room_id, severity: 'warning',
                title: STALE_SESSION_ALERT_TITLE,  -- exact-match constant; Alert has no `type`
                                                     -- column to dedup on, so title is the key,
                                                     -- same as `cleared_at is null` is the "active" check
                description: "<shift_label> shift has logged no dispatch decisions in over 4 hours.")
    -- matches AlertController::store()'s existing notification behavior
       (not literally invoked -- that method expects an HTTP Request; the
       command replicates its Notification::send(...) call directly):
       Notification::send($controlRoom->team->allUsers(), new AlertRaisedNotification($alert))
       -- every team member, not "all except the raiser" -- there is no
       human raiser to exclude when the system itself raises it

if silentFor >= 8 hours and no pending StaleSessionProposal exists for this operator session:
    StaleSessionProposal::create(operator_session_id, control_room_id, hours_silent, status: 'pending')
```

**Correction found during implementation, via a genuinely failing test:**
`now()->diffInHours($pastTimestamp)` was written assuming Carbon 2's
always-absolute-value behavior. This repo runs Carbon 3.13.0 (confirmed
via `composer show nesbot/carbon`, not assumed), where `diffInHours()` and
the other `diffInX()` methods return a **signed** difference by default —
`$other - $this`, negative when `$other` is in the past. The first test
run produced `hoursSilent = -5` for a session started 5 hours ago, which
silently failed the `>= 4 hours` check every time (no exception, just a
wrong number) rather than raising the expected alert. Fixed by passing
Carbon's explicit `absolute: true` named argument, which both Carbon 2 and
3 support, making the call version-independent rather than relying on
either version's default.

4 and 8 hours are reasonable, explicit defaults (no existing business rule
names a shift-silence threshold anywhere in this repo) — both as named
constants on the command, not magic numbers, so a future pass can tune them
without hunting through the method body.

Each session processed inside its own try/catch — one bad row is logged
and skipped, not allowed to abort the whole scan (matches
`DetectRetentionPurgeCandidates`'s established per-row resilience
convention from this program's earlier work).

Scheduled in `routes/console.php` — this platform's first `Schedule::`
entry — `->hourly()->withoutOverlapping()`.

### 2. `stale_session_proposals` table + `StaleSessionProposal` model

| Column | Type | Notes |
|---|---|---|
| `operator_session_id` | FK → `operator_sessions`, cascade delete | the session in question |
| `control_room_id` | FK → `control_rooms`, cascade delete | denormalized for direct scoping/display without a join through the session |
| `hours_silent` | unsigned int | snapshot at proposal-creation time |
| `status` | string, default `pending` | `pending` / `ended` / `dismissed` |
| `resolved_at` | nullable timestamp | |
| `resolved_by` | nullable FK → `users`, `nullOnDelete` | |
| timestamps | | |

Uses `HasControlRoomTeamScope` — **unlike** Dot.Billing's cross-tenant
`DunningCase` (where the reviewer wasn't a team member and the trait would
have wrongly blocked them), the reviewer here *is* a normal team member
reviewing their own team's control room, so the existing team-scoping
trait is exactly what's wanted, not something to bypass. Confirmed by
reading `app/Models/Concerns/HasControlRoomTeamScope.php` directly (not
assumed by analogy): its condition is `if (Auth::check() &&
Auth::user()->currentTeam)`, the same non-fail-closed shape already
verified for Dot.Analytics's `HasTeamScope` — a genuinely teamless request
just gets no scoping, it isn't blocked, so there is no equivalent bug risk
here even if a review path were ever reached without a team.

Gets its own factory (`StaleSessionProposalFactory`), matching this repo's
own established convention — every other domain model here
(`ControlRoomFactory`, `AlertFactory`, `DispatchDecisionFactory`,
`OperatorSessionFactory`) has one, and this repo's tests build fixtures via
`Model::factory()->for(...)->create()`, not `Model::create()` — a different
convention from Auction/Billing, followed here rather than assumed.

### 3. `app/Http/Controllers/StaleSessionProposalController.php`

Plain resource-style controller, matching this domain's own explicit
convention (wiki.md §3a: *"the only existing Livewire component
(`AgentChat`) is chat-specific... plain controllers matched the rest of
the app's convention... better than introducing Livewire"*). Mirrors
`AlertController`/`OperatorSessionController`'s exact shape: `abort_unless
($request->user()->belongsToTeam($controlRoom->team), 403)` — the same
any-team-member bar `OperatorSessionController::update()` already uses to
end a shift, not a stricter role. Ending a stale session through this
gate is the identical action a human already takes manually today; the
gate only removes the "did anyone notice" gap, not who's allowed to act.

```php
public function end(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
{
    $controlRoom = $staleSessionProposal->controlRoom;
    abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

    $staleSessionProposal->operatorSession->update(['ended_at' => now()]);
    $staleSessionProposal->update([
        'status' => 'ended', 'resolved_at' => now(), 'resolved_by' => $request->user()->id,
    ]);

    return redirect()->route('control-rooms.show', $controlRoom);
}

public function dismiss(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
{
    $controlRoom = $staleSessionProposal->controlRoom;
    abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

    $staleSessionProposal->update([
        'status' => 'dismissed', 'resolved_at' => now(), 'resolved_by' => $request->user()->id,
    ]);

    return redirect()->route('control-rooms.show', $controlRoom);
}
```

### 4. Routes

Alongside the existing `control-rooms.*`/`alerts.*`/`operator-sessions.*`
routes in the same authenticated group:

```php
Route::patch('stale-session-proposals/{staleSessionProposal}/end', [StaleSessionProposalController::class, 'end'])
    ->name('stale-session-proposals.end');
Route::patch('stale-session-proposals/{staleSessionProposal}/dismiss', [StaleSessionProposalController::class, 'dismiss'])
    ->name('stale-session-proposals.dismiss');
```

### 5. View

Pending proposals for a control room are rendered inline on the existing
`control-rooms.show` Blade view, alongside the current operator-sessions
list — read the real view first to match its existing table/card markup
exactly rather than guessing a style.

## Testing Strategy

- `tests/Feature/Console/ScanStaleSessionsCommandTest.php`: a session
  silent 4+ hours raises exactly one `warning` `Alert` and doesn't
  duplicate it on a second run; a session silent 8+ hours also opens
  exactly one `StaleSessionProposal`; a session with a recent dispatch
  decision is left alone; an already-`ended_at` session is skipped
  entirely.
- `tests/Feature/StaleSessionProposalTest.php`: a team member can end the
  session (session `ended_at` set, proposal `ended`) or dismiss (proposal
  `dismissed`, session untouched); a user from a different team gets 403
  and nothing changes.

## Out of Scope

- Wiring `mining.alert.raised`/`mining.dispatch.outcome` domain events
  toward Dot.Brain (wiki.md §7, separately unbuilt, needs the DKP
  publishing pipeline this repo doesn't have yet).
- Any live Dot.Mines telemetry integration — this spec only ever reads
  this repo's own already-recorded `decided_at`/`started_at` timestamps.
- Auto-ending a session, under any threshold — every path in this spec
  stops at a proposal; only a human `end()` action ever sets `ended_at`.
- Tuning the 4/8-hour thresholds beyond picking reasonable, clearly-named
  defaults — no real shift-length business rule exists in this repo to
  derive them from more precisely.
