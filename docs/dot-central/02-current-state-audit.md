# Dot.Central — Current-State Audit

**Repository:** `Dot.Central`, branch `main`, clean tree, last commit 2026-08-10. **Audited:** 2026-10-15, read-only, against the code (not the README). `wiki.md` v0.12.0 was found accurate on every point checked.

## 1. Stack (verified from `composer.lock`, `php -v`, config)

| Layer | Actual | Note |
|---|---|---|
| Framework | Laravel **13.17** on PHP **8.5.9** | README badge "Laravel 12 / PHP 8.4" is stale |
| UI | Livewire 3.8, Jetstream 5.5 (livewire stack), Fortify, Alpine | Authenticated shell loads Tailwind **Play CDN** and Alpine from unpkg, not Vite; `resources/js/app.js` is empty |
| Auth | Sanctum 4.3, Jetstream teams | |
| Realtime | Reverb 1.10 installed | **not configured**: no `config/broadcasting.php`, no channels, no Echo |
| Queue / cache / session | database drivers | nothing is ever queued |
| DB | PostgreSQL (`dot_central_pilot` locally); tests on SQLite in-memory | |
| QA | PHPUnit 12, Pint, Larastan level 5, Laravel Boost | |

## 2. What exists, by domain

### 2.1 AI command centre (out of scope for the control-room work, kept intact)
Agents CRUD with skills and knowledge documents, conversations with streamed Claude replies (mock reply when no key), usage logs, notification bell. 35 routes total, Livewire only for chat and the bell; CRUD is plain controllers + Blade. One dead link: "Add Skill" on the dashboard.

### 2.2 Mining-dispatch scaffold (the seed of the control room)
- Tables: `control_rooms` (team_id, name, slug, `mines_site_ref` free string, is_active), `dispatch_decisions` (workflow_type enum assign/reroute/hold/stagger, per-room sequence, decided_at, decided_by, summary), `alerts` (severity info/warning/critical, title, description, triggered_at, cleared_at), `operator_sessions` (user, shift_label, started_at, ended_at), `stale_session_proposals`.
- All data is **typed in by a human** on the control-room show page. No fleet, machine, telemetry, location or production data of any kind.
- One scheduled command (`central:scan-stale-sessions`, hourly) raises a warning alert after 4 h of silence and a human-review proposal after 8 h. Database notification to team members, synchronous.
- `mines_site_ref` has **no referent**: Dot.Mines has no "site" entity (its machines are scoped by team only, per the wiki's own rescoping note).

### 2.3 Auth, tenancy, SSO
- Tenant scoping is real and tested: Eloquent global scopes (`HasTeamScope`, `HasControlRoomTeamScope`, `HasUserScope`) plus `abort_unless(belongsToTeam)` in controllers. Scopes only apply when a web user is authenticated; **console and queue contexts are unscoped**.
- Jetstream roles `admin`/`editor` are defined but **never checked** anywhere in app code. Every team member can create/delete rooms, decisions, alerts, end sessions and resolve proposals. No policies, no gates, no custom middleware.
- Ecosystem SSO: `GET /auth/ecosystem?token=` consumes a short-lived Sanctum token issued by the InfoDot hub (ability `ecosystem:read`), which only works when hub and platform share the same `users`/`personal_access_tokens` tables. Locally the three apps use **separate databases**, so SSO is not exercisable here. Untested; token travels in the query string.
- No service-to-service authentication exists in Dot.Central or, per the wiki, anywhere in the ecosystem.

### 2.4 Integration with Dot.Mines
**None.** No HTTP client, config key, DTO, sync job, webhook route or event consumer. Only the ecosystem registry entry (`config/ecosystem.php` → `DOT_MINES_URL`) and prose.

### 2.5 Tests and tooling (run on 2026-10-15)
- `php artisan test`: **108 tests, 101 passed, 7 skipped (Jetstream feature flags), 0 failed**.
- `pint --test`: passes.
- `phpstan` level 5: **53 errors**, all Larastan relation-inference noise; no baseline.
- Untested: the SSO controller; update/destroy paths of decision/alert/session controllers beyond the happy path.

### 2.6 Front-end
Custom dark shell (fixed sidebar, topbar with team chip, notification bell, dark/light toggle) using inline CSS variables and `.dot-*` utility classes; fonts Syne/Inter/JetBrains Mono. The stock Jetstream `navigation-menu` is still rendered beneath the custom chrome. `tailwind.config.js` is the stock Jetstream one and is not what the shell uses. Heavy inline styles in page views. No shared component library beyond Jetstream's.

## 3. Prohibited references
No hits for the excluded customer or its sites. **One real South African mine name is used as demo data** in `database/seeders/MiningDispatchSeeder.php`, `resources/views/control-rooms/create.blade.php` (placeholder) and `tests/Feature/ControlRoomTest.php`. Seeded alert titles also carry Dot.Brain's narrative ("moisture-flagged shift — reroute clustering"). **Action: replace with neutral fixture names.**

## 4. Feature classification

| Feature | Classification |
|---|---|
| Agent CRUD, skills, knowledge, streamed chat, usage logs, notification bell | **Fully implemented and verified** (tests; chat verified only against faked API) |
| Skill management UI | **Missing** (dead link) |
| Control rooms CRUD + KPI counts | **Fully implemented** (manual data only) |
| Dispatch decisions / alerts / operator sessions | **Partially implemented**: manual forms, no source data, no acknowledge/assign/escalate workflow, no dedup, no audit trail |
| Stale-session scan + human gate | **Implemented and tested**; depends on a cron that is not verified in deployment |
| In-app notifications | **Implemented**; not real-time |
| Jetstream roles | **Present but non-functional** (never enforced) |
| Reverb / broadcasting | **Present but non-functional** |
| Queues / jobs / events | **Missing** |
| Ecosystem SSO | **Present, unverified**; requires shared DB |
| Dot.Mines data consumption | **Missing — blocked** on (a) site↔team mapping and (b) a service-auth mechanism |
| Shifts, handover, exceptions/approvals, audit log, incidents, tasks, reports, data quality, integration health, map | **Missing entirely** |

## 5. Technical risks the next work must address or avoid

1. `add_team_id_to_agents_table` adds a NOT NULL FK without default; fails on a Postgres DB with existing agents.
2. Authenticated shell uses CDN Tailwind/Alpine; `package.json` mixes Tailwind v3 and the v4 Vite plugin; `app.js` empty. Any real-time front end (Echo) requires consolidating on Vite first.
3. Reverb env present but no broadcasting config — do not assume real-time exists.
4. Global scopes depend on `Auth::user()->currentTeam`; any Dot.Mines sync job must set the team explicitly or it will read/write across tenants.
5. No role enforcement; every team member is effectively admin.
6. `mines_site_ref` is a dangling concept.
7. PHPStan red with no baseline; relation generics missing.
8. Duplicate navigation (Jetstream menu under custom shell), unverified visually.

## 6. Conventions to follow (from `CLAUDE.md`, Laravel Boost)
Confirm package versions before using APIs; follow sibling-file structure; no new base folders or dependencies without approval; `php artisan make:*` with `--no-interaction`; named routes; Eloquent API Resources with versioning for new APIs; strict types, constructor promotion, TitleCase enums, PHPDoc array shapes; Livewire state server-side with validation/authorisation in actions; PHPUnit feature tests with factories for every change, never delete tests; `pint --dirty` before finishing; spec → plan under `docs/superpowers/` before feature builds; update `wiki.md` change log with every substantive change.
