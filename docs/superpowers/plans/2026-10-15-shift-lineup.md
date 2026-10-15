# Shift Set-up and Line-up Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the owner's workflow — start shift → line-up → operator per machine → everything follows — on the derived data, per `docs/dot-central/13-shift-lineup-design.md`.

**Architecture:** Line-up tables hang off `shifts`; DOWN is derived; role class and operators are room configuration; Livewire page for the line-up; rules LU-xx in the existing engine/ledger.

**Tech Stack:** Laravel 13, Livewire 3, PHPUnit 12, Pint, Larastan.

## Global Constraints
- No customer / site / person names anywhere (ProhibitedReferencesTest).
- No new base folders, no dependency changes.
- Every change tested; Pint + PHPStan clean; Impeccable detector on new pages.
- Nothing edits a Dot.Mines fact; DOWN is never typed.

---

### Task G: Operators register, equipment role class, mine-area mirror
Files: migration `000016_create_operators_mine_areas_and_role_class`, models `Operator`, `MineArea`, `OperatorPolicy`, `Equipment` (role_class, heuristic in `App\Support\RoleClass`), `MinesSynchroniser` (apply mine areas + default role class), `ControlRoomAdminController` (operators + equipment roles tabs), `resources/views/admin/index.blade.php`, tests `OperatorsAndRoleClassTest`.
- [ ] Failing tests → migration/models/admin → pass → commit.

### Task H: Line-up domain and services
Files: migration `000017_create_lineup_tables`, models `ShiftLineup`, `LineupFace`, `LineupAllocation`, `LineupStandby`, `ShiftOperatorAssignment`, service `App\Services\Lineup\LineupService` (state, pool, addFace, editFace, closeFace, place, release, setStandby, assignOperator, clearOperator, copyFromPrevious, fillOperators, confirm, checks), `LineupPolicy` via `ShiftPolicy::lineup`, tests `LineupServiceTest`.
- [ ] Failing tests → implement → pass → commit.

### Task I: Line-up page (Livewire) and navigation
Files: `App\Livewire\Lineup`, view `livewire/lineup.blade.php`, route `shifts.lineup`, `ShiftController::store` redirects to it, `_strip` gains Line-up, nav link, tests `LineupPageTest`.
- [ ] Failing tests → implement → pass → detector → commit.

### Task J: Rules LU-06/07/09/10/11/13, handover, reports, overview
Files: `RuleEngine` (+ CODES), `ShiftService::snapshot` (faces, operators, standby_now), `ReportController` (`lineup` report), `shifts/show`, `livewire/overview`, `shifts/start` (outstanding items), tests `LineupRulesAndHandoverTest`.
- [ ] Failing tests → implement → pass → commit.

### Task K: Availability on the fleet board
Files: `FleetBoard` (+ line-up state, operator, issues, filters), view, tests in `DerivedPagesTest`.
- [ ] Failing tests → implement → pass → detector → commit.

### Task L: Live verification and docs
- [ ] Live: sync, start shift, copy/confirm line-up, availability board; screenshots.
- [ ] Docs: 05 matrix (F-29 → Done, F-32 operators, F-33 availability), 06 roadmap, 10 issues (I-28, I-29), 04 spec (operators not in API), wiki 0.16.0, memory.
