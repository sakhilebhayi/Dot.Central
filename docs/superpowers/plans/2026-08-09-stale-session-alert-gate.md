# Stale-Session Alert Scanning + Approval Gate Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build this platform's first-ever scheduled process — a stale-operator-session scan — split so raising an informational alert about operational silence happens automatically (Level 1), while ending a possibly-forgotten shift stops for a human decision (Level 2).

**Architecture:** A new `central:scan-stale-sessions` console command (this repo's first scheduled job) checks every active `OperatorSession` (no `ended_at`) for how long its control room has gone without a `DispatchDecision`. Past 4 hours, it raises a `warning` `Alert` (deduped by title) and notifies the team, replicating `AlertController::store()`'s existing notification behavior. Past 8 hours, it opens a `StaleSessionProposal`. A new `StaleSessionProposalController` (plain resource-style controller, matching this domain's own established no-Livewire convention) lets any team member — the same bar `OperatorSessionController::update()` already uses to end a shift — end the session for real or dismiss the proposal.

**Tech Stack:** Laravel 13 (pgsql in production, sqlite in tests), plain Blade + resource controllers (no Livewire in this domain), PHPUnit.

## Global Constraints

- `StaleSessionProposal` uses `HasControlRoomTeamScope` (unlike Dot.Billing's `DunningCase`, which deliberately didn't) — confirmed by reading `app/Models/Concerns/HasControlRoomTeamScope.php` directly: its condition is `if (Auth::check() && Auth::user()->currentTeam)`, the same non-fail-closed shape as Dot.Analytics's `HasTeamScope`, not Dot.Billing's fail-closed one. The reviewer here is a normal team member, so team-scoping is exactly what's wanted.
- `StaleSessionProposalController::end()`/`dismiss()` authorize with `abort_unless($request->user()->belongsToTeam($controlRoom->team), 403)` — identical to `AlertController`/`OperatorSessionController`'s existing bar. Do not invent a stricter role; ending a stale session through this gate is the same action a human already takes manually via `OperatorSessionController::update()`.
- `Alert` has no `type` column — dedup the Level 1 alert by an exact-match title constant (`STALE_SESSION_ALERT_TITLE`) plus `cleared_at IS NULL`, not a type field that doesn't exist.
- This repo uses model factories and `Model::factory()->for(...)->create()` in tests (confirmed: `AlertFactory`, `ControlRoomFactory`, `DispatchDecisionFactory`, `OperatorSessionFactory` all exist) — give `StaleSessionProposal` the same, and use it the same way in tests. Do not use `Model::create()` directly (that's Auction/Billing's convention, not this repo's).
- Match `resources/views/control-rooms/show.blade.php`'s existing inline-style card convention exactly (`var(--card-bg)`, `var(--card-border)`, `'Syne'` headings) for any new markup.
- Per this repo's own `CLAUDE.md` Laravel Boost guidelines ("Verification Scripts" section): do not create verification scripts or use `tinker` when tests cover the functionality and prove it works.
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.

---

### Task 1: `stale_session_proposals` table + `StaleSessionProposal` model + factory

**Files:**
- Create: `database/migrations/2026_08_09_000001_create_stale_session_proposals_table.php`
- Create: `app/Models/StaleSessionProposal.php`
- Create: `database/factories/StaleSessionProposalFactory.php`
- Test: `tests/Unit/Models/StaleSessionProposalTest.php`

**Interfaces:**
- Produces: `StaleSessionProposal` model, `$fillable = ['operator_session_id', 'control_room_id', 'hours_silent', 'status', 'resolved_at', 'resolved_by']`, relations `operatorSession()`, `controlRoom()`, `resolver()`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_09_000001_create_stale_session_proposals_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stale_session_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hours_silent');
            $table->string('status')->default('pending');
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stale_session_proposals');
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: runs without error.

- [ ] **Step 3: Write the model**

Create `app/Models/StaleSessionProposal.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Database\Factories\StaleSessionProposalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaleSessionProposal extends Model
{
    /** @use HasFactory<StaleSessionProposalFactory> */
    use HasControlRoomTeamScope, HasFactory;

    protected $fillable = [
        'operator_session_id', 'control_room_id', 'hours_silent',
        'status', 'resolved_at', 'resolved_by',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function operatorSession(): BelongsTo
    {
        return $this->belongsTo(OperatorSession::class);
    }

    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
```

- [ ] **Step 4: Write the factory**

Create `database/factories/StaleSessionProposalFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaleSessionProposal>
 */
class StaleSessionProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'operator_session_id' => OperatorSession::factory(),
            'control_room_id' => ControlRoom::factory(),
            'hours_silent' => 8,
            'status' => 'pending',
        ];
    }
}
```

- [ ] **Step 5: Write a model test**

Create `tests/Unit/Models/StaleSessionProposalTest.php`:

```php
<?php

namespace Tests\Unit\Models;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleSessionProposalTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_belongs_to_a_session_a_control_room_and_a_resolver(): void
    {
        $team = Team::factory()->create();
        $controlRoom = ControlRoom::factory()->for($team)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create();
        $resolver = User::factory()->create();

        $proposal = StaleSessionProposal::factory()
            ->for($session, 'operatorSession')
            ->for($controlRoom)
            ->create(['status' => 'pending']);

        $this->assertTrue($proposal->operatorSession->is($session));
        $this->assertTrue($proposal->controlRoom->is($controlRoom));
        $this->assertNull($proposal->resolver);

        $proposal->update(['status' => 'ended', 'resolved_at' => now(), 'resolved_by' => $resolver->id]);
        $this->assertTrue($proposal->fresh()->resolver->is($resolver));
    }

    public function test_it_is_scoped_to_the_current_teams_control_rooms(): void
    {
        $userA = User::factory()->withPersonalTeam()->create();
        $userB = User::factory()->withPersonalTeam()->create();
        $roomA = ControlRoom::factory()->for($userA->currentTeam)->create();
        $roomB = ControlRoom::factory()->for($userB->currentTeam)->create();

        StaleSessionProposal::factory()->for($roomA)
            ->for(OperatorSession::factory()->for($roomA), 'operatorSession')->create();
        StaleSessionProposal::factory()->for($roomB)
            ->for(OperatorSession::factory()->for($roomB), 'operatorSession')->create();

        $this->actingAs($userA);

        $this->assertSame(1, StaleSessionProposal::count());
    }
}
```

Uses `User::factory()->withPersonalTeam()->create()` (the same fixture
`tests/Feature/ControlRoomTest.php` already establishes as this repo's
correct way to guarantee a working `currentTeam`) rather than manually
wiring a `Team` + `current_team_id`, which needs an explicit `->save()`
after `->associate()` to actually persist — easy to get wrong, so this
plan uses the already-proven pattern instead.

- [ ] **Step 6: Run the tests**

Run: `php artisan test --compact tests/Unit/Models/StaleSessionProposalTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 7: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_09_000001_create_stale_session_proposals_table.php \
  app/Models/StaleSessionProposal.php \
  database/factories/StaleSessionProposalFactory.php \
  tests/Unit/Models/StaleSessionProposalTest.php
git commit -m "$(cat <<'EOF'
feat: stale_session_proposals table + StaleSessionProposal model

Adds the record opened when an active operator session has logged no
dispatch decision for 8+ hours -- see
docs/superpowers/specs/2026-08-09-stale-session-alert-gate.md.

Uses HasControlRoomTeamScope (verified its condition directly rather
than assuming from another platform) since the reviewer here is a
normal team member, unlike Dot.Billing's cross-tenant DunningCase.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: `central:scan-stale-sessions` command + schedule

**Files:**
- Create: `app/Console/Commands/ScanStaleSessions.php`
- Modify: `routes/console.php` (add the schedule entry)
- Test: `tests/Feature/Console/ScanStaleSessionsCommandTest.php`

**Interfaces:**
- Consumes: `OperatorSession` (existing), `DispatchDecision` (existing), `Alert`/`AlertRaisedNotification` (existing), `StaleSessionProposal` (Task 1).
- Produces: Artisan command `central:scan-stale-sessions`.

- [x] **Step 1: Write the failing command test**

Create `tests/Feature/Console/ScanStaleSessionsCommandTest.php`:

```php
<?php

namespace Tests\Feature\Console;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\User;
use App\Notifications\AlertRaisedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScanStaleSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_silent_4_hours_raises_a_warning_alert_once(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(5),
            'ended_at' => null,
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();
        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', [
            'control_room_id' => $controlRoom->id,
            'severity' => 'warning',
        ]);
        Notification::assertSentToTimes($owner, AlertRaisedNotification::class, 1);
    }

    public function test_a_session_silent_8_hours_also_opens_a_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertDatabaseHas('stale_session_proposals', [
            'operator_session_id' => $session->id,
            'control_room_id' => $controlRoom->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_session_with_a_recent_decision_is_left_alone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);
        DispatchDecision::factory()->for($controlRoom)->for($owner, 'decidedBy')->create([
            'sequence' => 1,
            'decided_at' => now()->subMinutes(30),
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertSame(0, Alert::count());
        $this->assertSame(0, StaleSessionProposal::count());
    }

    public function test_an_ended_session_is_skipped(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => now()->subHours(1),
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertSame(0, Alert::count());
        $this->assertSame(0, StaleSessionProposal::count());
    }
}
```

- [x] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/Console/ScanStaleSessionsCommandTest.php`
Expected: FAIL — command `central:scan-stale-sessions` does not exist yet.

- [x] **Step 3: Write the command**

Create `app/Console/Commands/ScanStaleSessions.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\Alert;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Notifications\AlertRaisedNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Notification;

class ScanStaleSessions extends Command
{
    protected $signature = 'central:scan-stale-sessions';

    protected $description = 'Raise an alert when an active operator session has logged no dispatch activity for a while, and open a review proposal if the silence continues -- never ends a session on its own.';

    private const ALERT_THRESHOLD_HOURS = 4;

    private const PROPOSAL_THRESHOLD_HOURS = 8;

    private const ALERT_TITLE = 'No dispatch activity detected';

    public function handle(): int
    {
        $activeSessions = OperatorSession::whereNull('ended_at')->with('controlRoom')->get();
        $processed = 0;

        foreach ($activeSessions as $session) {
            try {
                $this->evaluate($session);
                $processed++;
            } catch (\Throwable $e) {
                $this->error("Failed to evaluate operator session #{$session->id}: {$e->getMessage()}");
            }
        }

        $this->info("Evaluated {$processed} active operator session(s).");

        return self::SUCCESS;
    }

    private function evaluate(OperatorSession $session): void
    {
        $controlRoom = $session->controlRoom;

        $lastDecidedAt = $controlRoom->dispatchDecisions()->max('decided_at');
        $sinceActivity = $lastDecidedAt ? max($session->started_at, $lastDecidedAt) : $session->started_at;
        // Carbon 3's diffInHours() returns a signed difference by default
        // (unlike Carbon 2, which was always absolute) -- $sinceActivity is
        // always in the past here, so pass absolute: true explicitly rather
        // than relying on either version's default.
        $hoursSilent = now()->diffInHours($sinceActivity, absolute: true);

        if ($hoursSilent < self::ALERT_THRESHOLD_HOURS) {
            return;
        }

        $alreadyAlerted = Alert::where('control_room_id', $controlRoom->id)
            ->where('title', self::ALERT_TITLE)
            ->whereNull('cleared_at')
            ->exists();

        if (! $alreadyAlerted) {
            $alert = $controlRoom->alerts()->create([
                'severity' => 'warning',
                'title' => self::ALERT_TITLE,
                'description' => "\"{$session->shift_label}\" shift has logged no dispatch decisions in over ".self::ALERT_THRESHOLD_HOURS.' hours.',
                'triggered_at' => now(),
            ]);

            Notification::send($controlRoom->team->allUsers(), new AlertRaisedNotification($alert));
        }

        if ($hoursSilent < self::PROPOSAL_THRESHOLD_HOURS) {
            return;
        }

        $alreadyProposed = StaleSessionProposal::where('operator_session_id', $session->id)
            ->where('status', 'pending')
            ->exists();

        if (! $alreadyProposed) {
            StaleSessionProposal::create([
                'operator_session_id' => $session->id,
                'control_room_id' => $controlRoom->id,
                'hours_silent' => $hoursSilent,
                'status' => 'pending',
            ]);
        }
    }
}
```

- [x] **Step 4: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/Console/ScanStaleSessionsCommandTest.php`

First run: 2 passed, 2 failed -- `now()->diffInHours($sinceActivity)`
returned `-5` for a session started 5 hours ago (Carbon 3.13.0, confirmed
via `composer show nesbot/carbon`, changed `diffInHours()` to a signed
result by default; Carbon 2 was always absolute). Fixed with
`diffInHours($sinceActivity, absolute: true)` (see the corrected Step 3
code above). Actual after the fix: PASS, 4 tests, 0 failures.

- [x] **Step 5: Add the schedule entry**

`routes/console.php` currently has only the stock `inspire` command. Add:

```php
use App\Console\Commands\ScanStaleSessions;
use Illuminate\Support\Facades\Schedule;

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-stale-session-alert-gate.md.
Schedule::command(ScanStaleSessions::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/ScanStaleSessions.php routes/console.php \
  tests/Feature/Console/ScanStaleSessionsCommandTest.php
git commit -m "$(cat <<'EOF'
feat: central:scan-stale-sessions -- this platform's first scheduler entry

Raises a warning Alert (deduped by title, since Alert has no `type`
column) when an active operator session has logged no dispatch
decision in 4+ hours, notifying the team the same way
AlertController::store() already does for a human-raised alert.
Opens a StaleSessionProposal at 8+ hours instead of guessing whether
to end the session -- that stays human-only (Task 3).

Scheduled hourly in routes/console.php, this platform's first
Schedule:: entry.

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `StaleSessionProposalController` + routes + view wiring

**Files:**
- Create: `app/Http/Controllers/StaleSessionProposalController.php`
- Modify: `routes/web.php` (add the two PATCH routes)
- Modify: `app/Http/Controllers/ControlRoomController.php` (`show()` passes pending proposals)
- Modify: `resources/views/control-rooms/show.blade.php` (render pending proposals)
- Test: `tests/Feature/StaleSessionProposalTest.php`

**Interfaces:**
- Consumes: `StaleSessionProposal` (Task 1).
- Produces: routes `stale-session-proposals.end`, `stale-session-proposals.dismiss`.

- [ ] **Step 1: Write the failing feature test**

Create `tests/Feature/StaleSessionProposalTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaleSessionProposalTest extends TestCase
{
    use RefreshDatabase;

    private function makeProposal(): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);
        $proposal = StaleSessionProposal::factory()
            ->for($session, 'operatorSession')
            ->for($controlRoom)
            ->create(['hours_silent' => 9, 'status' => 'pending']);

        return compact('owner', 'controlRoom', 'session', 'proposal');
    }

    public function test_a_team_member_can_end_the_session(): void
    {
        ['owner' => $owner, 'session' => $session, 'proposal' => $proposal] = $this->makeProposal();

        $response = $this->actingAs($owner)
            ->patch(route('stale-session-proposals.end', $proposal));

        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertSame('ended', $proposal->fresh()->status);
        $this->assertSame($owner->id, $proposal->fresh()->resolved_by);
        $response->assertRedirect(route('control-rooms.show', $proposal->controlRoom));
    }

    public function test_a_team_member_can_dismiss_the_proposal(): void
    {
        ['owner' => $owner, 'session' => $session, 'proposal' => $proposal] = $this->makeProposal();

        $this->actingAs($owner)->patch(route('stale-session-proposals.dismiss', $proposal));

        $this->assertNull($session->fresh()->ended_at);
        $this->assertSame('dismissed', $proposal->fresh()->status);
    }

    public function test_a_user_from_a_different_team_is_forbidden(): void
    {
        ['session' => $session, 'proposal' => $proposal] = $this->makeProposal();
        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->patch(route('stale-session-proposals.end', $proposal))
            ->assertForbidden();

        $this->assertNull($session->fresh()->ended_at);
        $this->assertSame('pending', $proposal->fresh()->status);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact tests/Feature/StaleSessionProposalTest.php`
Expected: FAIL — route `stale-session-proposals.end` doesn't exist yet.

- [ ] **Step 3: Write the controller**

Create `app/Http/Controllers/StaleSessionProposalController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\StaleSessionProposal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Resolves a StaleSessionProposal (opened by central:scan-stale-sessions
 * when an active operator session has logged no dispatch activity for a
 * while). Mirrors AlertController/OperatorSessionController's exact
 * authorization shape -- any team member, the same bar
 * OperatorSessionController::update() already uses to end a shift.
 */
class StaleSessionProposalController extends Controller
{
    public function end(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
    {
        $controlRoom = $staleSessionProposal->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $staleSessionProposal->operatorSession->update(['ended_at' => now()]);
        $staleSessionProposal->update([
            'status' => 'ended',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }

    public function dismiss(Request $request, StaleSessionProposal $staleSessionProposal): RedirectResponse
    {
        $controlRoom = $staleSessionProposal->controlRoom;
        abort_unless($request->user()->belongsToTeam($controlRoom->team), 403);

        $staleSessionProposal->update([
            'status' => 'dismissed',
            'resolved_at' => now(),
            'resolved_by' => $request->user()->id,
        ]);

        return redirect()->route('control-rooms.show', $controlRoom);
    }
}
```

- [ ] **Step 4: Add the routes**

In `routes/web.php`, add `use App\Http\Controllers\StaleSessionProposalController;`
to the top import block. Inside the existing authenticated group, after the
`operator-sessions` routes:

```php
Route::patch('stale-session-proposals/{staleSessionProposal}/end', [StaleSessionProposalController::class, 'end'])
    ->name('stale-session-proposals.end');
Route::patch('stale-session-proposals/{staleSessionProposal}/dismiss', [StaleSessionProposalController::class, 'dismiss'])
    ->name('stale-session-proposals.dismiss');
```

- [ ] **Step 5: Run the test to verify it passes**

Run: `php artisan test --compact tests/Feature/StaleSessionProposalTest.php`
Expected: PASS, 3 tests, 0 failures.

- [ ] **Step 6: Wire pending proposals into `ControlRoomController::show()`**

In `app/Http/Controllers/ControlRoomController.php`, inside `show()`, add
after the existing `$controlRoom->load([...])` call:

```php
$pendingProposals = $controlRoom->staleSessionProposals()->where('status', 'pending')->with('operatorSession.operator')->get();
```

Add `'pendingProposals' => $pendingProposals,` to the `view('control-rooms.show', [...])` array.

This requires a `staleSessionProposals(): HasMany` relation on `ControlRoom`
— add it to `app/Models/ControlRoom.php` alongside the existing
`dispatchDecisions()`/`alerts()`/`operatorSessions()` relations:

```php
public function staleSessionProposals(): HasMany
{
    return $this->hasMany(StaleSessionProposal::class);
}
```

- [ ] **Step 7: Render pending proposals in the view**

In `resources/views/control-rooms/show.blade.php`, add a full-width banner
section immediately after the closing `</p>` of the mines-site/active line
and before the `<div style="display:grid;...">` grid, matching this file's
existing card style exactly:

```blade
@if($pendingProposals->isNotEmpty())
<div style="background:var(--card-bg);border:1px solid #f59e0b;border-radius:0.875rem;padding:1.25rem;margin-bottom:1.5rem;">
    <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0 0 1rem;">Awaiting Review</h2>
    @foreach($pendingProposals as $proposal)
        <div style="border-top:1px solid var(--divider);padding:0.6rem 0;display:flex;align-items:center;justify-content:space-between;gap:0.5rem;">
            <div>
                <div style="font-size:0.78rem;color:var(--text-primary);font-weight:600;">{{ $proposal->operatorSession->operator->name ?? 'User #'.$proposal->operatorSession->user_id }} — {{ $proposal->operatorSession->shift_label }}</div>
                <div style="font-size:0.68rem;color:var(--text-muted);">No dispatch activity for {{ $proposal->hours_silent }}h</div>
            </div>
            <div style="display:flex;gap:0.4rem;">
                <form method="POST" action="{{ route('stale-session-proposals.end', $proposal) }}">
                    @csrf @method('PATCH')
                    <button type="submit" style="background:none;border:none;color:#e11d48;cursor:pointer;font-size:0.68rem;">End Session</button>
                </form>
                <form method="POST" action="{{ route('stale-session-proposals.dismiss', $proposal) }}">
                    @csrf @method('PATCH')
                    <button type="submit" style="background:none;border:none;color:#22c55e;cursor:pointer;font-size:0.68rem;">Dismiss</button>
                </form>
            </div>
        </div>
    @endforeach
</div>
@endif
```

- [ ] **Step 8: Manual verification**

Per this repo's own no-tinker rule, do not verify with `tinker` or a
throwaway script — the feature test in Step 5 and the full regression
(Task 4) already exercise this. Skip manual verification.

- [ ] **Step 9: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/StaleSessionProposalController.php \
  routes/web.php \
  app/Http/Controllers/ControlRoomController.php \
  app/Models/ControlRoom.php \
  resources/views/control-rooms/show.blade.php \
  tests/Feature/StaleSessionProposalTest.php
git commit -m "$(cat <<'EOF'
feat: resolve stale-session proposals from the control room show page

StaleSessionProposalController lets any team member (same bar
OperatorSessionController::update() already uses) end the session for
real or dismiss the proposal. Wired into the existing control-rooms.show
view as a banner above the three-column layout, matching this repo's
established plain-controller/Blade convention for this domain (no
Livewire, per wiki.md's own stated reasoning).

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

Run: `php artisan test --compact`
Expected: 0 failures.

- [ ] **Step 2: Run Pint across the whole repo**

Run: `vendor/bin/pint --format agent`
Expected: `passed` (or auto-fixes with no functional change).

- [ ] **Step 3: Report**

Report the final test count and confirm the working tree is clean
(`git status --short`). No manual tinker verification, per this repo's own
Laravel Boost guideline — the test suite (Tasks 1-3) already proves the
scan and review flows work.

---

## Self-Review Notes

- **Spec coverage:** §1 (scan command) → Task 2 Step 3, schedule → Task 2
  Step 5. §2 (proposal table + model) → Task 1. §3 (controller) → Task 3
  Step 3. §4 (routes) → Task 3 Step 4. §5 (view) → Task 3 Steps 6-7.
  Testing Strategy → Task 1 Step 5, Task 2 Step 1, Task 3 Step 1. All spec
  sections have a task.
- **Placeholder scan:** none found — every step has complete code. Two real
  bugs caught in this pass and fixed inline rather than hedged for the
  executor: Task 1 Step 5's first test draft used
  `$userA->currentTeam()->associate($teamA)` without a following `->save()`
  (would silently not persist), replaced with the already-proven
  `User::factory()->withPersonalTeam()->create()` fixture; Task 2 Step 3
  called `\Illuminate\Support\Facades\Notification::send(...)` inline
  instead of importing `Notification`, replaced with a proper `use`.
- **Type consistency:** `StaleSessionProposal::$fillable` (Task 1 Step 3)
  matches every `::create()`/`::update()` call across Tasks 1-3 exactly.
  `ScanStaleSessions`'s two threshold constants
  (`ALERT_THRESHOLD_HOURS = 4`, `PROPOSAL_THRESHOLD_HOURS = 8`) match the
  spec's Design §1 exactly and match the test fixtures in Task 2 Step 1
  (5 hours triggers the alert only, 9 hours triggers both). `ControlRoom::staleSessionProposals()`
  (Task 3 Step 6) is a new relation added alongside the three that already
  exist there (`dispatchDecisions()`, `alerts()`, `operatorSessions()`) —
  confirmed by reading `app/Models/ControlRoom.php` directly before writing
  this step, not assumed.
