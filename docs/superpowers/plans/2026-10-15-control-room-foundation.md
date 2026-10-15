# Control-Room Foundation (Increment 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make Dot.Central safe to build a control room on: neutral fixtures, enforced roles, site scoping, an append-only audit log, a Dot.Mines connection model, and a Vite-built shell.

**Architecture:** Keep the existing Jetstream-team tenancy and the plain-controller + Blade convention. Add policies backed by four Jetstream team roles, a `control_room_user` assignment pivot for site scope, an `audit_log` table written by a small `Audits` service inside the same request, and a `mines_connections` table (encrypted token) replacing the dangling `mines_site_ref`. Move the authenticated shell from CDN Tailwind/Alpine to the existing Vite pipeline.

**Tech Stack:** Laravel 13.17, PHP 8.5, Livewire 3.8 (bundles Alpine), Jetstream 5.5, Tailwind 3.4 via Vite 8, PHPUnit 12 (SQLite in-memory), Pint, Larastan.

## Global Constraints
- Never add the excluded customer's name, its sites, pits, crews, people, fleet numbers, factors or targets; no real mine names in fixtures (spec `01-requirements-discovery.md §3`).
- No new Composer dependencies. One npm change only: remove the unused `@tailwindcss/vite` v4 package (mismatch with Tailwind v3); no additions.
- Every change ships with a PHPUnit test; run `php artisan test --compact --filter=<Name>` after each task, `vendor/bin/pint --dirty --format agent` before each commit.
- Roles are exactly `operator`, `supervisor`, `manager`, `administrator` (spec `07-security-and-permissions.md §2`). Team owners are administrators (Jetstream `hasTeamRole` already returns true for owners).
- Service-layer authorisation via policies; Blade only hides what the policy would refuse.
- All timestamps UTC in storage.

---

### Task 1: Neutral fixtures and the prohibited-reference guard

**Files:**
- Modify: `database/seeders/MiningDispatchSeeder.php`
- Modify: `resources/views/control-rooms/create.blade.php:16-18`
- Modify: `tests/Feature/ControlRoomTest.php`
- Modify: `database/factories/ControlRoomFactory.php`
- Create: `tests/Feature/ProhibitedReferencesTest.php`

**Interfaces:** none.

- [ ] **Step 1: Write the failing guard test.** The excluded terms are stored base64-encoded so the repository never contains them in plain text; the test decodes them at runtime and scans every text file under `app/`, `config/`, `database/`, `resources/`, `routes/`, `tests/`, `docs/dot-central/`.

```php
<?php

namespace Tests\Feature;

use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Dot.Central must stay customer-neutral. The terms below are the
 * excluded customer/site names (and one real mine name that was used as
 * demo data), base64-encoded so that this test file itself never contains
 * them in plain text. Add a term by appending base64_encode(strtolower($term)).
 */
class ProhibitedReferencesTest extends TestCase
{
    /** @var list<string> */
    private const ENCODED_TERMS = [
        'YWZyaWNvYWw=', 'a2xpcGZvbnRlaW4=', 'bmV3IGxhcmdv', 'a29sb21lbGE=',
    ];

    /** @var list<string> */
    private const SCANNED_DIRECTORIES = [
        'app', 'config', 'database', 'resources', 'routes', 'tests', 'docs/dot-central',
    ];

    public function test_repository_contains_no_prohibited_customer_references(): void
    {
        $terms = array_map(fn (string $encoded): string => base64_decode($encoded, true), self::ENCODED_TERMS);

        $finder = Finder::create()
            ->files()
            ->in(array_map(fn (string $dir): string => base_path($dir), self::SCANNED_DIRECTORIES))
            ->exclude(['vendor', 'node_modules'])
            ->name(['*.php', '*.blade.php', '*.md', '*.json', '*.js', '*.css', '*.yml', '*.yaml', '*.txt']);

        $hits = [];
        foreach ($finder as $file) {
            if ($file->getRealPath() === __FILE__) {
                continue;
            }
            $contents = strtolower((string) file_get_contents($file->getRealPath()));
            foreach ($terms as $term) {
                if (str_contains($contents, $term)) {
                    $hits[] = $file->getRelativePathname().' contains an excluded term';
                }
            }
        }

        $this->assertSame([], $hits, implode(PHP_EOL, $hits));
    }
}
```

- [ ] **Step 2: Run it; expect FAIL** listing `MiningDispatchSeeder.php`, `control-rooms/create.blade.php`, `ControlRoomTest.php`.

Run: `php artisan test --compact --filter=ProhibitedReferencesTest`

- [ ] **Step 3: Replace the fixtures.** In the seeder use `'name' => 'Demo Control Room'`, drop `mines_site_ref`, alert titles `'Telemetry missing on three haul units'` and `'Sync completed with partial data'`. In `create.blade.php` the `mines_site_ref` field is removed entirely in Task 6; for now change the placeholder to `e.g. north-pit`. In `ControlRoomTest` use `'Demo Control Room'` and `'north-pit'`. In the factory keep `fake()->city()` but it is random; fine.

- [ ] **Step 4: Run; expect PASS.** Then run the full suite: `php artisan test --compact` — all green.

- [ ] **Step 5: Commit** `test: guard against prohibited customer references; neutral dispatch fixtures`.

---

### Task 2: Backfill-safe `agents.team_id` migration

**Files:**
- Modify: `database/migrations/2026_08_10_000001_add_team_id_to_agents_table.php`
- Create: `tests/Feature/AgentsTeamIdMigrationTest.php`

- [ ] **Step 1: Failing test** — create the schema up to the migration before this one, insert an agent row with no team, run the migration, assert the row now has the first team's id.

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AgentsTeamIdMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_agents_are_backfilled_with_the_first_team(): void
    {
        Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true]); // agent_knowledge + team_id
        $this->assertFalse(Schema::hasColumn('agents', 'team_id'));

        $teamId = DB::table('teams')->insertGetId(['user_id' => DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]), 'name' => 'First Team', 'personal_team' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('agents')->insert(['name' => 'Legacy', 'slug' => 'legacy', 'system_prompt' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($teamId, (int) DB::table('agents')->where('slug', 'legacy')->value('team_id'));
    }
}
```

- [ ] **Step 2: Run; expect FAIL** (NOT NULL constraint on insert during migrate, or missing backfill).
- [ ] **Step 3: Implement**: add the column nullable, backfill `UPDATE agents SET team_id = (SELECT MIN(id) FROM teams) WHERE team_id IS NULL`, then `->nullable(false)->change()` guarded by `if (DB::table('teams')->exists())`. Keep the FK.
- [ ] **Step 4: Run; PASS.** Full suite green.
- [ ] **Step 5: Commit** `fix: make agents.team_id migration safe on databases with existing agents`.

---

### Task 3: Four roles and policies

**Files:**
- Create: `app/Support/Role.php` (enum)
- Modify: `app/Providers/JetstreamServiceProvider.php`
- Create: `database/migrations/2026_10_15_000001_rename_legacy_team_roles.php`
- Create: `app/Policies/ControlRoomPolicy.php`, `app/Policies/AlertPolicy.php`, `app/Policies/DispatchDecisionPolicy.php`, `app/Policies/OperatorSessionPolicy.php`, `app/Policies/StaleSessionProposalPolicy.php`
- Modify: `app/Models/User.php` (add `roleOn(Team $team): ?Role`, `hasAnyRoleOn(Team $team, Role ...$roles): bool`)
- Modify: controllers `ControlRoomController`, `AlertController`, `DispatchDecisionController`, `OperatorSessionController`, `StaleSessionProposalController` — replace `abort_unless(belongsToTeam)` with `$this->authorize(...)`.
- Modify: `tests/Feature/ControlRoomTest.php` (role attach `'editor'` → `'operator'`)
- Create: `tests/Feature/RolePermissionsTest.php`

**Interfaces:**
- Produces: `App\Support\Role` enum with cases `Operator='operator'`, `Supervisor='supervisor'`, `Manager='manager'`, `Administrator='administrator'`; `Role::label(): string`; `Role::permissions(): array<int,string>`.
- Produces: `User::roleOn(Team $team): ?Role` (owner ⇒ Administrator), `User::hasAnyRoleOn(Team $team, Role ...$roles): bool`.
- Policies: `ControlRoomPolicy@viewAny/view/create/update/delete/manage`; `AlertPolicy@create/update/delete`; `DispatchDecisionPolicy@create/delete`; `OperatorSessionPolicy@create/update/delete`; `StaleSessionProposalPolicy@resolve`.

- [ ] **Step 1: Failing tests** (`RolePermissionsTest`): data provider over roles × actions:
  - operator: can view assigned room, create alert/decision/session, cannot delete room, cannot update room.
  - supervisor: same as operator plus delete alert, end session; cannot create/delete room.
  - manager: view all rooms (even unassigned), cannot create alert; cannot create room.
  - administrator: create/update/delete room; cannot create alert or decision.
  - outsider (other team): 404 on show (scope) and 403 on store routes via a room they cannot see → 404.
  Use `$team->users()->attach($user, ['role' => 'operator'])` and `ControlRoom` assignment from Task 4 is not yet available — for Task 3, write the policies to treat *all* team members as assigned; Task 4 tightens it and updates these tests.

```php
public static function roleMatrix(): array
{
    return [
        'operator can create alert' => ['operator', 'post', 'control-rooms.alerts.store', 302],
        'operator cannot delete room' => ['operator', 'delete', 'control-rooms.destroy', 403],
        'supervisor can end session' => ['supervisor', 'patch', 'operator-sessions.update', 302],
        'manager cannot create alert' => ['manager', 'post', 'control-rooms.alerts.store', 403],
        'manager can view room' => ['manager', 'get', 'control-rooms.show', 200],
        'administrator can update room' => ['administrator', 'put', 'control-rooms.update', 302],
        'administrator cannot create decision' => ['administrator', 'post', 'control-rooms.dispatch-decisions.store', 403],
    ];
}
```
  Build the request payloads per route inside the test (alert: severity/title/triggered_at; decision: workflow_type/decided_at; room update: name/is_active).

- [ ] **Step 2: Run; FAIL** (403s not raised; `Role` missing).
- [ ] **Step 3: Implement** the enum, Jetstream roles (`operator`, `supervisor`, `manager`, `administrator` with descriptions from the security spec), the migration `UPDATE team_user SET role='administrator' WHERE role='admin'; ... 'editor' → 'operator'`, the `User` helpers, the policies (`manager`/`administrator` see every room of the team; `operator`/`supervisor` see rooms they belong to — until Task 4, “belong to” = team membership), and the `authorize()` calls. Register nothing: Laravel auto-discovers `App\Policies\{Model}Policy`.
- [ ] **Step 4: Run RolePermissionsTest, ControlRoomTest, HasTeamScopeTest, StaleSessionProposal tests; PASS.** Full suite green.
- [ ] **Step 5: Commit** `feat: enforce operator/supervisor/manager/administrator roles with policies`.

---

### Task 4: Control-room assignment (site scope)

**Files:**
- Create: `database/migrations/2026_10_15_000002_create_control_room_user_table.php` (`control_room_id`, `user_id`, timestamps, unique pair)
- Modify: `app/Models/ControlRoom.php` (`users(): BelongsToMany`, `scopeAccessibleBy(Builder $q, User $user)`, `isAccessibleBy(User $user): bool`)
- Modify: `app/Models/User.php` (`controlRooms(): BelongsToMany`)
- Modify: `app/Policies/ControlRoomPolicy.php` and the child policies (operator/supervisor require `isAccessibleBy`)
- Modify: `app/Http/Controllers/ControlRoomController.php` (`index` uses `ControlRoom::accessibleBy($user)`; `store` attaches the creator; new `assign`/`unassign` actions for administrators)
- Modify: `routes/web.php` (`POST control-rooms/{controlRoom}/members`, `DELETE control-rooms/{controlRoom}/members/{user}`)
- Modify: `resources/views/control-rooms/show.blade.php` (members card, admin-only form)
- Modify: `tests/Feature/RolePermissionsTest.php` (operator unassigned ⇒ 403 on show; manager unassigned ⇒ 200)
- Create: `tests/Feature/ControlRoomAssignmentTest.php`

**Interfaces:**
- Produces: `ControlRoom::isAccessibleBy(User $user): bool` — true for team managers/administrators, else true only when the user is in `control_room_user`.
- Produces: `ControlRoom::scopeAccessibleBy(Builder, User)`.

- [ ] **Step 1: Failing tests**: assigned operator sees room on index and show; unassigned operator gets 403 on show and does not see it on index; manager sees it unassigned; administrator can POST members (user must belong to team, else 422) and DELETE members; operator cannot POST members (403).
- [ ] **Step 2: Run; FAIL.**
- [ ] **Step 3: Implement** as listed. `scopeAccessibleBy`: `if ($user->hasAnyRoleOn($team, Role::Manager, Role::Administrator)) return $q; return $q->whereHas('users', fn ($u) => $u->whereKey($user->id));`
- [ ] **Step 4: Run; PASS.** Full suite green.
- [ ] **Step 5: Commit** `feat: scope operators and supervisors to assigned control rooms`.

---

### Task 5: Append-only audit log

**Files:**
- Create: `database/migrations/2026_10_15_000003_create_audit_log_table.php`
- Create: `app/Models/AuditEntry.php` (table `audit_log`; no `updated_at`; guarded against update/delete by overriding `performUpdate`/`delete` to throw `LogicException`)
- Create: `app/Services/Audits.php` (`record(string $action, Model $subject, array $changes = [], ?string $reason = null, ?ControlRoom $controlRoom = null, ?User $actor = null, bool $automatic = false): AuditEntry`; `diff(Model $model): array<string,array{old:mixed,new:mixed}>`)
- Create: `app/Models/Concerns/Auditable.php` (model events created/updated/deleted → `Audits::record` with field diffs; `auditControlRoom(): ?ControlRoom`)
- Modify: `app/Models/ControlRoom.php`, `Alert.php`, `DispatchDecision.php`, `OperatorSession.php`, `StaleSessionProposal.php` (use `Auditable`)
- Modify: `app/Console/Commands/ScanStaleSessions.php` (pass `automatic: true` — via `Audits::asSystem()` context)
- Create: `app/Http/Controllers/AuditLogController.php` + `resources/views/audit/index.blade.php` (filters: control room, action, entity, date range; CSV export)
- Modify: `routes/web.php`, `resources/views/layouts/app.blade.php` (nav link, visible to supervisor/manager/administrator via `@can('viewAny', AuditEntry::class)`)
- Create: `app/Policies/AuditEntryPolicy.php` (`viewAny`: supervisor/manager/administrator; `export`: same)
- Create: `tests/Feature/AuditLogTest.php`, `tests/Unit/AuditsTest.php`

Columns: `id bigint`, `occurred_at`, `user_id nullable`, `user_role nullable`, `team_id`, `control_room_id nullable`, `shift_id nullable` (unsigned bigint, no FK yet), `entity_type`, `entity_id`, `action` (string: created|updated|deleted|override|approve|reject|generate|import|login|ack|permission_denied), `changes json nullable`, `reason text nullable`, `automatic bool`, `approved_by nullable`, `ip nullable`, `created_at`. Indexes on `(team_id, occurred_at)`, `(entity_type, entity_id)`, `(control_room_id, occurred_at)`.

- [ ] **Step 1: Failing tests**: creating an alert writes one `audit_log` row with `action=created`, `entity_type=App\Models\Alert`, `changes` containing `title`; clearing it writes `updated` with `cleared_at` old null → new timestamp; the stale-session command writes rows with `automatic=true` and `user_id=null`; `AuditEntry::find(..)->update()` throws; operator GET `/audit` ⇒ 403, supervisor ⇒ 200 and sees the row; `?export=csv` returns `text/csv` with a header row and writes a `generate` audit entry.
- [ ] **Step 2: Run; FAIL.**
- [ ] **Step 3: Implement.** The trait resolves the actor from `Auth::user()`; when none, `automatic=true`. `changes` = `['field' => ['old' => .., 'new' => ..]]` for dirty attributes excluding `updated_at`.
- [ ] **Step 4: Run; PASS.** Full suite green.
- [ ] **Step 5: Commit** `feat: append-only audit log for operational records`.

---

### Task 6: Dot.Mines connection model (replaces `mines_site_ref`)

**Files:**
- Create: `database/migrations/2026_10_15_000004_create_mines_connections_table.php` (`control_room_id` unique FK, `base_url`, `mines_team_id` unsigned int nullable, `api_token` text encrypted, `webhook_secret` text nullable encrypted, `provider_cadence_seconds` int default 900, `timezone` string default 'UTC', `status` string default 'unverified' (unverified|verified|credential_invalid|disabled), `last_success_at`, `last_failure_at`, `last_error` text nullable, `consecutive_failures` int default 0, `sync_cursor` unsigned bigint nullable, timestamps)
- Create: `database/migrations/2026_10_15_000005_drop_mines_site_ref_from_control_rooms.php`
- Create: `app/Models/MinesConnection.php` (casts: `api_token` encrypted, `webhook_secret` encrypted; `$hidden` both; `Auditable` with a redaction list so token values never reach `audit_log`)
- Modify: `app/Models/ControlRoom.php` (`minesConnection(): HasOne`; remove `mines_site_ref` from fillable), `database/factories/ControlRoomFactory.php`, `database/seeders/MiningDispatchSeeder.php`
- Create: `database/factories/MinesConnectionFactory.php`
- Create: `app/Http/Controllers/MinesConnectionController.php` (`edit`, `update`) + `resources/views/control-rooms/connection.blade.php`
- Modify: `app/Http/Controllers/ControlRoomController.php` (drop `mines_site_ref` validation), `resources/views/control-rooms/{create,edit,show,index}.blade.php`
- Modify: `routes/web.php` (`GET/PUT control-rooms/{controlRoom}/connection`, administrator only via `ControlRoomPolicy@manage`)
- Create: `tests/Feature/MinesConnectionTest.php`
- Modify: `.env.example` (add `DOT_MINES_URL` already exists in ecosystem config — nothing else; document that tokens live in the DB, encrypted)

**Interfaces:**
- Produces: `MinesConnection` with `baseUrl(): string` (trailing slash stripped, `/api/v1` appended by the client later), `isUsable(): bool` (status not disabled/credential_invalid and token present).

- [ ] **Step 1: Failing tests**: administrator can PUT connection with `base_url`, `api_token`, `provider_cadence_seconds`, `timezone`; token stored encrypted (raw DB value ≠ plaintext; model returns plaintext); response never echoes the token; audit row for the update has `api_token` redacted to `[redacted]`; operator PUT ⇒ 403; `control_rooms` no longer has `mines_site_ref`; leaving `api_token` blank on update keeps the existing token.
- [ ] **Step 2: Run; FAIL.**
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run; PASS.** Full suite green.
- [ ] **Step 5: Commit** `feat: Dot.Mines connection per control room with encrypted credentials`.

---

### Task 7: Shell on Vite, single navigation

**Files:**
- Modify: `package.json` (remove `@tailwindcss/vite`), run `npm install`
- Modify: `resources/css/app.css` (move the shell's CSS variables and `.sidebar/.topbar/.dot-*` rules here; keep `@tailwind` directives; add `@layer base { body { … } }`)
- Create: `resources/js/app.js` content: `import './bootstrap';` is not present — keep `app.js` as `// Livewire 3 ships Alpine; nothing else is bundled yet.`
- Modify: `tailwind.config.js` (fonts Inter/Syne/JetBrains Mono; keep forms/typography)
- Modify: `resources/views/layouts/app.blade.php` (remove the Tailwind CDN `<script>`s, the unpkg Alpine `<script>`, the inline `<style>`, and `@livewire('navigation-menu')`; add `@vite(['resources/css/app.css','resources/js/app.js'])`; nav sections: *Operate* (Dashboard, Control Rooms), *Control* (Audit Log — `@can`), *AI Agents* (Agents), footer links Notifications, Profile; team switcher link to `profile.show` kept)
- Create: `tests/Feature/AppShellTest.php`

- [ ] **Step 1: Failing test**: authenticated GET `/dashboard` HTML contains no `cdn.tailwindcss.com` and no `unpkg.com/alpinejs`, contains exactly one `<nav` of class `sidebar-nav`, and does not render the Jetstream navigation menu markup (`x-data="{ open: false }"` from `navigation-menu.blade.php`).
- [ ] **Step 2: Run; FAIL.**
- [ ] **Step 3: Implement**, then `npm run build` (must succeed; commit `public/build` only if the repo already tracks it — it does: `public/build/manifest.json` exists; check `.gitignore` first and follow it).
- [ ] **Step 4: Run; PASS.** Full suite green. Start the dev server (`.claude/launch.json` entry `central` on port 8018 — add it) and verify in the browser: dashboard, control rooms index/show/create, audit log; dark and light toggle; no console errors.
- [ ] **Step 5: Commit** `refactor: build the control-room shell with Vite; drop CDN assets and duplicate navigation`.

---

### Task 8: Baseline PHPStan, wiki and matrix update

**Files:**
- Create: `phpstan-baseline.neon` via `vendor/bin/phpstan analyse --generate-baseline`; include it from `phpstan.neon.dist`.
- Modify: `wiki.md` (change-log entry 0.13.0: control-room foundation; roles; audit; connection; shell on Vite; link to `docs/dot-central/`)
- Modify: `docs/dot-central/05-feature-matrix.md` (F-01, F-02, F-04, F-05, F-24 → Done with test names; F-03 → Partial: model + admin UI, client in Increment 2)

- [ ] **Step 1:** generate baseline; `vendor/bin/phpstan` exits 0.
- [ ] **Step 2:** update docs.
- [ ] **Step 3:** full suite, pint, commit `chore: phpstan baseline; wiki and matrix for Increment 1`.
