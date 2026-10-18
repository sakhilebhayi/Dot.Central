# Line-up Corrections (I-29) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the I-29 line-up features as designed in `docs/dot-central/15-lineup-corrections-design.md`:

- arrival and departure corrections;
- linked faces surfaced (one loading unit on two faces at the same cut and block);
- material split as a time split.

Also close the time holes and the Dot.Memory line-up mapping defects found while designing. "Breakdowns at shift change" is deliberately not built (design D-6).

**Architecture:** Everything is a Central-side annotation of the line-up.

- `LineupService` gains `correctTime`, `describeCorrection`, `downDuring`, `linkedFaces`, `splitFace` and `splitOrigin`. `editFace` gains link and material decisions.
- One additive migration adds `lineup_corrections` and `lineup_faces.split_from_face_id`.
- The Livewire `Lineup` page gains an inline correction panel and decision buttons.
- The handover snapshot, Start Shift, Shift Operations, the fleet board and the line-up report show the result.
- `AuditLoopMapper` mirrors corrections and splits as `action` records.
- Nothing calls Dot.Mines. `ShiftDerivation` is untouched.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 3, PHPUnit, Pint, Larastan (level 5). Production runs SQLite (WAL, DEFERRED); local development runs Postgres.

## Global Constraints

- **Never contact production** (central.infodot.co.za), never deploy, never merge. CI deploys on green `main` (`.github/workflows/deploy.yml`). Work only on branch `feat/lineup-corrections`. Open a PR only when the owner asks.
- **Nothing edits a Dot.Mines fact.** These features never write to:
  - `equipment.mines_*`;
  - `equipment_status_events`, `equipment_metrics` or `production_records`;
  - mirrored alerts;
  - `downtime_classifications`.

  They never call `MinesClient`. Tests assert `Http::assertNothingSent()` where Dot.Mines could be involved.
- **DOWN is never typed.** "Stopped" comes only from `mines_status` in `LineupService::STOPPED` (offline, maintenance) and from availability-counting classifications, never from missing telemetry.
- **No manual production capture (D3):** no quantity, load or percentage field anywhere in this work.
- **Migrations are additive.** Use plain columns, and no foreign keys in `Schema::table` additions (precedent: `2026_10_15_000018_…:23`, `2026_10_15_000019_…:20-21`). They must work on SQLite (production) and Postgres (local).
- **Refusals** are `ValidationException` under the key `lineup`, with the message `"<CODE> <text>"` (`LineupService::refuse`). New codes are LU-14 (material decision) and LU-15 (changed meanwhile). They are refusals only and never go into `RuleEngine::CODES`.
- **Every operator action is audited with a reason.** Mirroring to Dot.Memory happens only through `AuditLoopMapper`, from the audit row.
- No customer, site or person names anywhere (`tests/Feature/ProhibitedReferencesTest.php`). Use generic machine names: EX-01, HT-01, DZ-01.
- No new base folders and no dependency changes. Follow sibling conventions (`withoutGlobalScopes()` in services, PHPDoc array shapes, curly braces always).
- Test-first on every task. Run the named tests with `php artisan test --compact <file>`. Then run `vendor/bin/pint --dirty --format agent` and `vendor/bin/phpstan analyse --memory-limit=1G`, and both must come back clean before each commit.
- **UI:** existing `dot-*` classes only. Every new control gets an accessible name. The keyboard path is walked by hand in Task 10. Run the Impeccable detector on the rendered local page before calling Slice 1 or Slice 3 done.
- **Commits:** one per task. End each message with the executing session's own `Co-Authored-By:` trailer.

## Slices and estimate

| Slice | Tasks | What ships | Estimate |
|---|---|---|---|
| 1 (smallest valuable) | 1–6 | Corrections end to end, place / DST time-rule fixes, Memory line-up fixes | ~12.5 h |
| 2 | 7 | Linked faces surfaced, pooled LU-06 / LU-07, link-break refusal | ~3.5 h |
| 3 | 8–9 | Material split (time split) with the in-place correction choice | ~6 h |
| close | 10 | Docs, full suite, scratch-DB migration checks, keyboard walkthrough, detector | ~1.5 h |

**Total:** 10 tasks, about 23.5 h (≈ 3 working days).

## File map

| File | Tasks |
|---|---|
| `database/migrations/2026_10_18_000021_create_lineup_corrections_and_face_splits.php` (new) | 2 |
| `app/Models/LineupCorrection.php` (new) | 2 |
| `app/Models/LineupFace.php` | 2 |
| `app/Models/LineupAllocation.php` | 2 |
| `app/Services/Lineup/LineupService.php` | 3, 4, 7, 8, 9 |
| `app/Livewire/Lineup.php` | 5, 7, 9 |
| `resources/views/livewire/lineup.blade.php` | 5, 7, 9 |
| `app/Livewire/FleetBoard.php` | 6, 7 |
| `resources/views/livewire/fleet-board.blade.php` | 6 |
| `app/Services/Shifts/ShiftService.php` | 6, 7, 9 |
| `resources/views/shifts/start.blade.php` | 6, 7 |
| `app/Http/Controllers/ShiftController.php` | 7 |
| `resources/views/shifts/show.blade.php` | 7 |
| `app/Http/Controllers/ReportController.php` | 6, 7, 9 |
| `app/Services/Memory/AuditLoopMapper.php` | 1, 6, 9 |
| `app/Services/Memory/LoopSubject.php` | 6, 9 |
| Tests: `tests/Feature/Memory/AuditLoopMapperTest.php` | 1, 6, 9 |
| Tests: `tests/Feature/LineupCorrectionsSchemaTest.php` (new) | 2 |
| Tests: `tests/Feature/LineupServiceTest.php` | 3 |
| Tests: `tests/Feature/LineupCorrectionTest.php` (new) | 4 |
| Tests: `tests/Feature/LineupPageTest.php` | 5, 9 |
| Tests: `tests/Feature/LineupRulesAndHandoverTest.php` | 6, 9 |
| Tests: `tests/Feature/LineupLinkedFacesTest.php` (new) | 7 |
| Tests: `tests/Feature/LineupMaterialSplitTest.php` (new) | 8 |
| Docs: `docs/dot-central/13-shift-lineup-design.md`, `10-outstanding-issues.md`, `05-feature-matrix.md` | 10 |

Baseline (2026-10-18, before any change): `LineupServiceTest`, `LineupPageTest`, `LineupRulesAndHandoverTest`, `AuditLoopMapperTest` and `ProhibitedReferencesTest` give 26 tests, all passing.

---

### Task 1: The Memory line-up confirmation record counts the real line-up

**Files:**
- Modify: `app/Services/Memory/AuditLoopMapper.php`. Rewrite `lineup()` at `:182-199` and add `withDetail()`.
- Test: `tests/Feature/Memory/AuditLoopMapperTest.php`.

**Why:** `lineup()` queries `shift_lineup_id`, a column that does not exist. On SQLite that silently counts 0; on Postgres it throws and the record is lost. `base(...) + ['detail' => …]` also throws away the line-up detail, because PHP's array union keeps the left key.

- [ ] **Step 1: Write the failing test.** Add these imports if missing: `App\Models\LineupAllocation`, `App\Models\LineupFace`, `App\Models\LineupStandby`. Add the method:

```php
    public function test_lineup_confirmation_reports_faces_machines_placed_and_on_standby(): void
    {
        $lineup = ShiftLineup::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'status' => 'CONFIRMED', 'version' => 1, 'confirmed_by' => $this->supervisor->id, 'confirmed_at' => now()]);
        $face = LineupFace::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'seq' => 1, 'kind' => 'LOADING', 'material' => 'Overburden', 'status' => 'ACTIVE', 'active_from' => $this->shift->starts_at]);
        LineupAllocation::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'lineup_face_id' => $face->id, 'equipment_id' => $this->truck->id, 'role' => 'HAUL', 'from_at' => $this->shift->starts_at]);
        $dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'mines_machine_id' => 702]);
        $standby = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        LineupStandby::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $dozer->id, 'downtime_reason_id' => $standby->id, 'from_at' => $this->shift->starts_at]);
        MemoryOutboxRow::query()->delete();

        app(Audits::class)->record('approve', $lineup, ['version' => ['old' => 0, 'new' => 1]], 'Line-up confirmed (version 1)', $this->room, $this->supervisor);

        $detail = $this->payload('decision')['detail'];
        $this->assertSame([1, 1, 1, 1], [$detail['version'], $detail['faces'], $detail['placed'], $detail['standby']]);
        $this->assertSame('Line-up confirmed (version 1)', $detail['reason'], 'the audit context is kept alongside');
    }
```

- [ ] **Step 2: Run it and watch it fail.**

Run: `php artisan test --compact --filter=test_lineup_confirmation_reports_faces_machines_placed_and_on_standby`
Expected: FAIL with `Undefined array key "version"`.

- [ ] **Step 3: Implement.** In `AuditLoopMapper.php`, add `use App\Models\LineupFace;`. Replace `lineup()` and add the helper below `has()`:

```php
    /** @return list<array{stage:string,payload:array<string,mixed>}> */
    private function lineup(AuditEntry $entry, ShiftLineup $l): array
    {
        if ($entry->action !== 'approve') {
            return [];
        }

        $version = (int) ($this->newValue($entry, 'version') ?? $l->version);
        $detail = [
            'version' => $version,
            'faces' => LineupFace::withoutGlobalScopes()->where('shift_id', $l->shift_id)->where('status', 'ACTIVE')->count(),
            'placed' => LineupAllocation::withoutGlobalScopes()->where('shift_id', $l->shift_id)->whereNull('to_at')->distinct()->count('equipment_id'),
            'standby' => LineupStandby::withoutGlobalScopes()->where('shift_id', $l->shift_id)->whereNull('to_at')->count(),
        ];
        $signature = 'dot-central:lineup:confirmed';
        $base = fn (string $key): array => $this->withDetail($this->base($entry, $l, 'lineup:'.$l->id, $signature, $key), $detail);

        return [
            ['stage' => 'decision', 'payload' => $base('decision:v'.$version) + ['recommendation' => 'line-up v'.$version, 'confidence' => 1.0, 'risk' => 0.3, 'autonomy_level' => 'execute', 'requires_approval' => false]],
            ['stage' => 'action', 'payload' => $base('action:v'.$version) + ['action_kind' => 'lineup.confirm', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded']],
        ];
    }

    /**
     * base() already carries a detail key and PHP's array union keeps the
     * left one, so specific detail is merged into it here.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $detail
     * @return array<string, mixed>
     */
    private function withDetail(array $payload, array $detail): array
    {
        $payload['detail'] = (array) ($payload['detail'] ?? []) + $detail;

        return $payload;
    }
```

- [ ] **Step 4: Run the mapper tests.**

Run: `php artisan test --compact tests/Feature/Memory/AuditLoopMapperTest.php`
Expected: all pass, including `test_lineup_confirmation_and_operator_assignment`.

- [ ] **Step 5: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Services/Memory/AuditLoopMapper.php tests/Feature/Memory/AuditLoopMapperTest.php
git commit -m "fix(memory): line-up confirmation record counts the real line-up

lineup() queried a non-existent shift_lineup_id column (0 on SQLite, an
exception on Postgres) and the array union dropped its detail."
```

---

### Task 2: Schema: `lineup_corrections` and `lineup_faces.split_from_face_id`

**Files:**
- Create: `database/migrations/2026_10_18_000021_create_lineup_corrections_and_face_splits.php`.
- Create: `app/Models/LineupCorrection.php`.
- Modify: `app/Models/LineupFace.php` (property doc and `splitFrom()`) and `app/Models/LineupAllocation.php` (`corrections()`).
- Test: `tests/Feature/LineupCorrectionsSchemaTest.php`.

**Interfaces:**
- `LineupCorrection::ARRIVAL = 'ARRIVAL'`, `LineupCorrection::DEPARTURE = 'DEPARTURE'`, `LineupCorrection::EDGES`.
- Relations: `allocation()`, `equipment()`, `controlRoom()`, `correctedBy()`.
- `LineupFace::splitFrom(): BelongsTo` and `LineupAllocation::corrections(): HasMany`.

- [ ] **Step 1: Write the failing test.**

```php
<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** I-29 schema (15-lineup-corrections-design.md §8): additive, SQLite and Postgres safe. */
class LineupCorrectionsSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_corrections_table_and_face_split_column_exist(): void
    {
        foreach (['control_room_id', 'shift_id', 'lineup_allocation_id', 'partner_allocation_id', 'equipment_id', 'edge', 'old_at', 'new_at', 'reason', 'corrected_by'] as $column) {
            $this->assertTrue(Schema::hasColumn('lineup_corrections', $column), $column);
        }
        $this->assertTrue(Schema::hasColumn('lineup_faces', 'split_from_face_id'));
    }

    public function test_a_correction_belongs_to_its_stint_machine_and_room_and_a_face_knows_what_it_split_from(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        app(RoomProvisioner::class)->provision($room);
        $type = ShiftType::where('control_room_id', $room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00']);
        $unit = Equipment::factory()->for($room)->create(['name' => 'HT-01']);
        $old = LineupFace::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'seq' => 1, 'kind' => 'LOADING', 'material' => 'Overburden', 'status' => 'CLOSED', 'active_from' => '2026-10-15 06:00:00', 'closed_at' => '2026-10-15 12:00:00']);
        $new = LineupFace::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'seq' => 2, 'kind' => 'LOADING', 'material' => 'Interburden', 'status' => 'ACTIVE', 'active_from' => '2026-10-15 12:00:00', 'split_from_face_id' => $old->id]);
        $stint = LineupAllocation::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'lineup_face_id' => $new->id, 'equipment_id' => $unit->id, 'role' => 'HAUL', 'from_at' => '2026-10-15 12:00:00']);

        $correction = LineupCorrection::create(['control_room_id' => $room->id, 'shift_id' => $shift->id, 'lineup_allocation_id' => $stint->id, 'equipment_id' => $unit->id, 'edge' => LineupCorrection::ARRIVAL, 'old_at' => '2026-10-15 12:30:00', 'new_at' => '2026-10-15 12:00:00', 'reason' => 'Clicked late', 'corrected_by' => $owner->id]);

        $this->assertTrue($correction->allocation->is($stint));
        $this->assertSame('HT-01', $correction->equipment->name);
        $this->assertTrue($correction->controlRoom->is($room));
        $this->assertSame(30, (int) $correction->old_at->diffInMinutes($correction->new_at, true));
        $this->assertTrue($new->splitFrom->is($old));
        $this->assertSame([$correction->id], $stint->corrections()->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Run it.**

Run: `php artisan test --compact tests/Feature/LineupCorrectionsSchemaTest.php`
Expected: FAIL, `Class "App\Models\LineupCorrection" not found` (the first test fails on the missing table).

- [ ] **Step 3: Create the migration** with `php artisan make:migration create_lineup_corrections_and_face_splits --no-interaction`, then rename the file to `2026_10_18_000021_create_lineup_corrections_and_face_splits.php` so it sorts after `2026_10_16_000020`.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I-29 (15-lineup-corrections-design.md): corrections of when a machine
 * arrived on or left a face, and the face a split face carries on from.
 * Central-side annotations of the line-up; nothing from Dot.Mines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lineup_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('control_room_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lineup_allocation_id')->constrained('lineup_allocations')->cascadeOnDelete();
            $table->unsignedBigInteger('partner_allocation_id')->nullable();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->string('edge', 16);
            $table->timestamp('old_at');
            $table->timestamp('new_at');
            $table->string('reason', 255);
            $table->foreignId('corrected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['shift_id', 'lineup_allocation_id']);
        });

        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->unsignedBigInteger('split_from_face_id')->nullable()->after('closed_by');
            $table->index('split_from_face_id');
        });
    }

    public function down(): void
    {
        Schema::table('lineup_faces', function (Blueprint $table) {
            $table->dropIndex(['split_from_face_id']);
            $table->dropColumn('split_from_face_id');
        });
        Schema::dropIfExists('lineup_corrections');
    }
};
```

- [ ] **Step 4: Create the model** `app/Models/LineupCorrection.php`. It is not `Auditable`: the service writes the reasoned `override` row.

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasControlRoomTeamScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction of when a machine arrived on (ARRIVAL) or left (DEPARTURE)
 * a face. The service writes the audit row with the reason itself.
 *
 * @property int $control_room_id
 * @property int $shift_id
 * @property int $lineup_allocation_id
 * @property int|null $partner_allocation_id
 * @property int $equipment_id
 * @property string $edge
 * @property Carbon $old_at
 * @property Carbon $new_at
 * @property string $reason
 * @property int|null $corrected_by
 */
class LineupCorrection extends Model
{
    use HasControlRoomTeamScope;

    public const ARRIVAL = 'ARRIVAL';

    public const DEPARTURE = 'DEPARTURE';

    public const EDGES = [self::ARRIVAL, self::DEPARTURE];

    protected $guarded = ['id'];

    protected $casts = ['old_at' => 'datetime', 'new_at' => 'datetime'];

    /** @return BelongsTo<ControlRoom, $this> */
    public function controlRoom(): BelongsTo
    {
        return $this->belongsTo(ControlRoom::class);
    }

    /** @return BelongsTo<LineupAllocation, $this> */
    public function allocation(): BelongsTo
    {
        return $this->belongsTo(LineupAllocation::class, 'lineup_allocation_id');
    }

    /** @return BelongsTo<Equipment, $this> */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class);
    }

    /** @return BelongsTo<User, $this> */
    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }
}
```

- [ ] **Step 5: Extend the siblings.**
  - In `LineupFace.php`, add `@property int|null $split_from_face_id` to the docblock, and add this method after `mineArea()`:

    ```php
        /** @return BelongsTo<LineupFace, $this> */
        public function splitFrom(): BelongsTo
        {
            return $this->belongsTo(LineupFace::class, 'split_from_face_id');
        }
    ```

  - In `LineupAllocation.php`, add `use Illuminate\Database\Eloquent\Relations\HasMany;` and this method after `equipment()`:

    ```php
        /** @return HasMany<LineupCorrection, $this> */
        public function corrections(): HasMany
        {
            return $this->hasMany(LineupCorrection::class);
        }
    ```

- [ ] **Step 6: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupCorrectionsSchemaTest.php tests/Feature/LineupServiceTest.php`
Expected: all pass.

- [ ] **Step 7: Check up and down on scratch databases (local only).**

```bash
php artisan config:clear
touch /tmp/i29-check.sqlite
DB_CONNECTION=sqlite DB_URL= DB_DATABASE=/tmp/i29-check.sqlite php artisan migrate --force --no-interaction
DB_CONNECTION=sqlite DB_URL= DB_DATABASE=/tmp/i29-check.sqlite php artisan migrate:rollback --step=1 --force --no-interaction
DB_CONNECTION=sqlite DB_URL= DB_DATABASE=/tmp/i29-check.sqlite php artisan migrate --force --no-interaction
rm /tmp/i29-check.sqlite
createdb dot_central_i29_check
DB_DATABASE=dot_central_i29_check php artisan migrate --force --no-interaction
DB_DATABASE=dot_central_i29_check php artisan migrate:rollback --step=1 --force --no-interaction
dropdb dot_central_i29_check
```

Expected: `2026_10_18_000021_create_lineup_corrections_and_face_splits ... DONE` on each migrate and each rollback. If `createdb` is not on PATH, use the Postgres.app binary path. Never point at production.

- [ ] **Step 8: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add database/migrations/2026_10_18_000021_create_lineup_corrections_and_face_splits.php app/Models/LineupCorrection.php app/Models/LineupFace.php app/Models/LineupAllocation.php tests/Feature/LineupCorrectionsSchemaTest.php
git commit -m "feat(lineup): corrections table and face split link (I-29 schema)"
```

---

### Task 3: Time rules: overlaps, moves before arrival, times the clocks skipped

**Files:**
- Modify: `app/Services/Lineup/LineupService.php`: `changeAt()` (`:75-106`), `place()` (`:313-361`), and the new private helpers `refuseOverlap()` and `hm()`.
- Test: `tests/Feature/LineupServiceTest.php`.

**Interfaces:**
- `private function hm(Shift $shift, ?Carbon $at): string` returns HH:MM in the room's clock, or `'now'` for null.
- `private function refuseOverlap(Shift $shift, Equipment $unit, LineupFace $face, string $role, Carbon $at): void`.

- [ ] **Step 1: Write the failing tests** in `LineupServiceTest`:

```php
    public function test_a_placement_that_overlaps_an_earlier_stint_of_the_same_machine_is_refused(): void
    {
        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $late = $this->service->place($this->shift, $face, $this->truck1, $this->user, '10:30');
        $this->service->release($late, 'Clicked late', $this->user);

        $message = $this->refusal(fn () => $this->service->place($this->shift, $face, $this->truck1, $this->user, '10:00'));
        $this->assertStringContainsString('LU-05 Overlaps an earlier entry: HT-01 was on Face 1 — Loading area 1 — Overburden until 12:00', $message);
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->count(), 'nothing was written');

        $this->service->place($this->shift, $face, $this->truck1, $this->user, '12:00');
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck1->id)->count(), 'from the end of the last stint is fine');
    }

    public function test_the_shared_loading_unit_of_a_linked_face_may_overlap_its_stint_on_the_partner_face(): void
    {
        $seam = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Seam', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->user, '08:00');
        $stint = LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->firstOrFail();
        $this->service->release($stint, 'Moved to the parting', $this->user, '11:00');
        $parting = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Parting', 'cut' => 'C1', 'blocks' => ['B1']], $this->user, '09:00');

        $shared = $this->service->place($this->shift, $parting, $this->loader, $this->user, '09:00');
        $this->assertSame('2026-10-15 09:00:00', $shared->from_at->toDateTimeString());
        $this->assertTrue($seam->sameWorkplaceAs($parting));
    }

    public function test_a_move_recorded_before_the_machine_arrived_where_it_is_is_refused_instead_of_deleting_that_stint(): void
    {
        $face1 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $face2 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '07:00');
        $stint = $this->service->place($this->shift, $face1, $this->truck1, $this->user, '11:00');

        $message = $this->refusal(fn () => $this->service->place($this->shift, $face2, $this->truck1, $this->user, '10:50', true));
        $this->assertStringContainsString('LU-05 Time before the machine arrived: HT-01 only arrived on Face 1 — Loading area 1 — Overburden at 11:00', $message);
        $this->assertNotNull(LineupAllocation::withoutGlobalScopes()->find($stint->id), 'the stint is kept');
    }

    public function test_a_time_the_clocks_skipped_is_refused_and_the_repeated_hour_takes_its_first_occurrence(): void
    {
        $this->room->minesConnection->forceFill(['timezone' => 'Europe/London'])->save();
        $this->shift->forceFill(['status' => 'CLOSED'])->save();

        Carbon::setTestNow('2026-03-29 04:30:00');
        $spring = $this->openShift('NIGHT', '2026-03-28', '2026-03-28 18:00:00', '2026-03-29 05:00:00');
        $message = $this->refusal(fn () => $this->service->changeAt($spring, '01:30'));
        $this->assertStringContainsString('LU-05 01:30 did not happen on 29 Mar in Europe/London: the clocks went forward.', $message);
        $this->assertSame('2026-03-29 01:00:00', $this->service->changeAt($spring, '02:00')->toDateTimeString());

        $spring->forceFill(['status' => 'CLOSED'])->save();
        Carbon::setTestNow('2026-10-25 05:30:00');
        $autumn = $this->openShift('NIGHT', '2026-10-24', '2026-10-24 17:00:00', '2026-10-25 06:00:00');
        $this->assertSame('2026-10-25 00:30:00', $this->service->changeAt($autumn, '01:30')->toDateTimeString(), 'the first 01:30 (BST)');
    }
```

- [ ] **Step 2: Run them.**

Run: `php artisan test --compact tests/Feature/LineupServiceTest.php`
Expected: 4 failures. Three say `Expected a refusal.` (the overlap, the move before arrival, and the 01:30 clock change). The linked-face test passes already, and that is correct.

- [ ] **Step 3: Implement `changeAt`.** Replace the block from `$timezone = …` to `$at = $at->utc();` (`:90-96`) with:

```php
        $timezone = $shift->controlRoom->timezone();
        $startLocal = $shift->starts_at->copy()->setTimezone($timezone);
        $at = $startLocal->copy()->setTime((int) $m[1], (int) $m[2]);
        if ($at->lessThan($startLocal)) {
            $at->addDay();
        }
        $typed = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
        if ($at->format('H:i') !== $typed) {
            $this->refuse('LU-05', $typed.' did not happen on '.$at->format('j M').' in '.$timezone.': the clocks went forward. Enter the time on the clock after the change.');
        }
        $at = $at->utc();
```

- [ ] **Step 4: Implement `place()`.** Between the LU-01 refusal and `if (! $shared) {`, replace the existing `$planning = $this->isPlanning($shift);` line with:

```php
        $planning = $this->isPlanning($shift);
        if (! $planning) {
            $this->refuseOverlap($shift, $unit, $face, $role, $at);
            $early = $shared ? null : $elsewhere->first(fn (LineupAllocation $a) => $at->lessThan($a->from_at));
            if ($early !== null) {
                $this->refuse('LU-05', 'Time before the machine arrived: '.$unit->name.' only arrived on '.$early->face?->label().' at '.$this->hm($shift, $early->from_at).'. Record the move at that time or later, or first correct when it arrived (select its time on that face).');
            }
        }
```

Then add these helpers in the `// ---- helpers` section:

```php
    /** HH:MM on the control room's clock ("now" for an open end). */
    private function hm(Shift $shift, ?Carbon $at): string
    {
        return $at === null ? 'now' : $at->copy()->setTimezone($shift->controlRoom->timezone())->format('H:i');
    }

    /**
     * A machine cannot start somewhere at $at while an earlier, ended stint
     * has it elsewhere after $at; a loading unit may overlap only on a face
     * at the same cut and block (a linked face).
     */
    private function refuseOverlap(Shift $shift, Equipment $unit, LineupFace $face, string $role, Carbon $at): void
    {
        $clash = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $unit->id)
            ->whereNotNull('to_at')->where('to_at', '>', $at)->orderBy('from_at')->get()
            ->first(fn (LineupAllocation $a) => ! ($role === 'LOADER' && $a->role === 'LOADER' && $a->face !== null && $a->face->sameWorkplaceAs($face)));

        if ($clash !== null) {
            $until = $this->hm($shift, $clash->to_at);
            $this->refuse('LU-05', 'Overlaps an earlier entry: '.$unit->name.' was on '.$clash->face?->label().' until '.$until.'. Record the change at '.$until.' or later, or first correct when it left that face (select its time under "Moved off this face").');
        }
    }
```

- [ ] **Step 5: Run the line-up suites.**

Run: `php artisan test --compact tests/Feature/LineupServiceTest.php tests/Feature/LineupPageTest.php tests/Feature/LineupRulesAndHandoverTest.php`
Expected: all pass.

- [ ] **Step 6: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Services/Lineup/LineupService.php tests/Feature/LineupServiceTest.php
git commit -m "fix(lineup): refuse overlapping stints, moves before arrival, skipped clock times"
```

---

### Task 4: `LineupService::correctTime`

**Files:**
- Modify: `app/Services/Lineup/LineupService.php`. Add a new section `// ---- corrections (I-29)` after `// ---- standby`. Add imports `App\Models\LineupCorrection` and `Illuminate\Support\Facades\DB`.
- Test: `tests/Feature/LineupCorrectionTest.php` (new).

**Interfaces:**

```php
public function correctTime(LineupAllocation $allocation, string $edge, string $hhmm, string $reason, User $user, ?string $expected = null): ?LineupCorrection
public function describeCorrection(LineupCorrection $correction): string
/** @return array{cause: string, since: Carbon|null}|null */
public function downDuring(Equipment $unit, Carbon $from, Carbon $to): ?array
```

`correctTime` returns null when the time is unchanged. `$expected` is the ISO time the screen showed.

- [ ] **Step 1: Write the failing test file.**

```php
<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftOperatorAssignment;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Services\Shifts\ShiftDerivation;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** I-29 arrival and departure corrections (15-lineup-corrections-design.md §3). */
class LineupCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck;

    private LineupService $service;

    private LineupFace $face1;

    private LineupFace $face2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Http::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $this->shift = $this->openShift('DAY', '2026-10-15', '2026-10-15 06:00:00', '2026-10-15 18:00:00');
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service = app(LineupService::class);
        $this->face1 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $this->face2 = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '07:00');
    }

    private function openShift(string $code, string $date, string $start, string $end): Shift
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', $code)->firstOrFail();

        return Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'OPEN']);
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['lineup'][0];
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_move_clicked_late_is_corrected_and_the_face_it_came_from_moves_with_it(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        $operator = Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'One']);
        $assignment = ShiftOperatorAssignment::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shift->id, 'equipment_id' => $this->truck->id, 'operator_id' => $operator->id, 'from_at' => '2026-10-15 08:00:00', 'assigned_by' => $this->user->id]);
        $this->truck->forceFill(['mines_operator_id' => null])->save();
        $derived = fn (): array => app(ShiftDerivation::class)->unitSummaries($this->shift)->map(fn (array $u) => collect($u)->except('equipment')->all())->all();
        $before = $derived();

        $correction = $this->service->correctTime($moved, LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->user, $moved->from_at->toIso8601String());

        $this->assertSame('2026-10-15 10:00:00', $moved->fresh()->from_at->toDateTimeString());
        $this->assertSame('2026-10-15 10:00:00', $first->fresh()->to_at->toDateTimeString(), 'the stint it came from ends at the same moment');
        $this->assertSame([LineupCorrection::ARRIVAL, '2026-10-15 10:30:00', '2026-10-15 10:00:00', $first->id], [$correction->edge, $correction->old_at->toDateTimeString(), $correction->new_at->toDateTimeString(), $correction->partner_allocation_id]);
        $this->assertSame('HT-01 on Face 2 — Loading area 2 — Overburden at 10:00 (was 10:30); off Face 1 — Loading area 1 — Overburden at the same time.', $this->service->describeCorrection($correction));

        $audit = AuditEntry::where('entity_type', LineupCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame(['override', $this->shift->id], [$audit->action, $audit->shift_id]);
        $this->assertStringContainsString('corrected from 10:30 to 10:00: The move was clicked late', (string) $audit->reason);
        $this->assertSame(['2026-10-15T10:30:00+00:00', '2026-10-15T10:00:00+00:00'], [$audit->changes['from_at']['old'], $audit->changes['from_at']['new']]);
        $this->assertSame('2026-10-15T10:00:00+00:00', $audit->changes['partner_to_at']['new']);

        $this->assertSame('2026-10-15 08:00:00', $assignment->fresh()->from_at->toDateTimeString(), 'operator rows are not touched');
        $this->assertSame($before, $derived(), 'derived hours, loads and stoppages do not read the line-up');
        Http::assertNothingSent();
    }

    public function test_a_departure_is_corrected_and_the_face_it_went_to_moves_with_it(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);

        $correction = $this->service->correctTime($first->fresh(), LineupCorrection::DEPARTURE, '10:45', 'Left later than clicked', $this->user);

        $this->assertSame('2026-10-15 10:45:00', $first->fresh()->to_at->toDateTimeString());
        $this->assertSame('2026-10-15 10:45:00', $moved->fresh()->from_at->toDateTimeString());
        $this->assertSame('HT-01 off Face 1 — Loading area 1 — Overburden at 10:45 (was 10:30); on Face 2 — Loading area 2 — Overburden at the same time.', $this->service->describeCorrection($correction));
        $this->assertNull($this->service->correctTime($first->fresh(), LineupCorrection::DEPARTURE, '10:45', 'Again', $this->user), 'the same time changes nothing');
        $this->assertSame(1, LineupCorrection::withoutGlobalScopes()->count());
    }

    public function test_impossible_corrections_are_refused_with_the_reason(): void
    {
        $first = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '08:00');
        $moved = $this->service->place($this->shift, $this->face2, $this->truck, $this->user, '10:30', true);
        $correct = fn (LineupAllocation $a, string $edge, string $time, string $reason = 'Clicked late') => $this->service->correctTime($a->fresh(), $edge, $time, $reason, $this->user);

        $this->assertStringContainsString('LU-05 Say why the time is corrected', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '10:00', ' ')));
        $this->assertStringContainsString('HT-01 is still on Face 2', $this->refusal(fn () => $correct($moved, LineupCorrection::DEPARTURE, '11:00')));
        $this->assertStringContainsString('Times out of order', $this->refusal(fn () => $correct($first, LineupCorrection::DEPARTURE, '07:30')));
        $this->assertStringContainsString('Outside the life of the face: Face 1 — Loading area 1 — Overburden ran 07:00–now', $this->refusal(fn () => $correct($first, LineupCorrection::ARRIVAL, '06:30')));
        $this->assertStringContainsString('has not happened yet', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '13:00')));
        $this->assertStringContainsString('is outside', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '05:00')));

        $truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $early = $this->service->place($this->shift, $this->face1, $truck2, $this->user, '08:00');
        $this->service->release($early, 'Sent to the workshop', $this->user, '09:00');
        $later = $this->service->place($this->shift, $this->face2, $truck2, $this->user, '10:30');
        $this->assertStringContainsString('LU-05 Overlaps another entry: HT-02 was on Face 1 — Loading area 1 — Overburden 08:00–09:00', $this->refusal(fn () => $correct($later, LineupCorrection::ARRIVAL, '08:30')));

        EquipmentStatusEvent::create(['equipment_id' => $truck2->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 09:30:00', 'source' => 'sync']);
        EquipmentStatusEvent::create(['equipment_id' => $truck2->id, 'field' => 'mines_status', 'from_value' => 'maintenance', 'to_value' => 'active', 'observed_at' => '2026-10-15 10:00:00', 'source' => 'sync']);
        $this->assertStringContainsString('LU-02 HT-02 was down then: Dot.Mines: maintenance since 09:30', $this->refusal(fn () => $correct($later, LineupCorrection::ARRIVAL, '09:15')));
        $this->assertSame('2026-10-15 10:30:00', $later->fresh()->from_at->toDateTimeString(), 'refusals change nothing');

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $this->assertStringContainsString('SH-04', $this->refusal(fn () => $correct($moved, LineupCorrection::ARRIVAL, '10:00')));
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $nightFace = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $planned = $this->service->place($night, $nightFace, $this->truck, $this->user);
        $this->assertStringContainsString('Nothing to correct while planning', $this->refusal(fn () => $correct($planned, LineupCorrection::ARRIVAL, '19:00')));
        Http::assertNothingSent();
    }

    public function test_standby_recorded_while_the_machine_was_really_working_is_trimmed(): void
    {
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $standby = $this->service->setStandby($this->shift, $this->truck, $reason, $this->user, '08:00');
        $placed = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '10:30');
        $this->assertSame('2026-10-15 10:30:00', $standby->fresh()->to_at->toDateTimeString());

        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '09:00', 'Started loading at nine', $this->user);
        $this->assertSame('2026-10-15 09:00:00', $standby->fresh()->to_at->toDateTimeString(), 'standby ends when the machine really started');

        $this->service->correctTime($placed->fresh(), LineupCorrection::ARRIVAL, '07:30', 'Started even earlier', $this->user);
        $this->assertNull(LineupStandby::withoutGlobalScopes()->find($standby->id), 'a standby wholly inside the worked time is removed');
    }

    public function test_a_second_supervisor_working_from_a_stale_screen_is_told_the_time_changed(): void
    {
        $placed = $this->service->place($this->shift, $this->face1, $this->truck, $this->user, '10:30');
        $seen = $placed->from_at->toIso8601String();
        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '10:00', 'Clicked late', $this->user, $seen);

        $message = $this->refusal(fn () => $this->service->correctTime($placed->fresh(), LineupCorrection::ARRIVAL, '09:50', 'Also clicked late', $this->user, $seen));
        $this->assertStringContainsString('LU-15 Someone changed this time meanwhile: HT-01 is now recorded on Face 1 — Loading area 1 — Overburden at 10:00', $message);
        $this->assertSame('2026-10-15 10:00:00', $placed->fresh()->from_at->toDateTimeString());
    }

    public function test_a_night_shift_correction_lands_on_the_right_side_of_midnight(): void
    {
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        Carbon::setTestNow('2026-10-16 03:00:00');
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $face = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user, '18:00');
        $placed = $this->service->place($night, $face, $this->truck, $this->user, '01:30');

        $this->service->correctTime($placed, LineupCorrection::ARRIVAL, '23:40', 'Clicked after midnight', $this->user);

        $this->assertSame('2026-10-15 23:40:00', $placed->fresh()->from_at->toDateTimeString());
    }
}
```

- [ ] **Step 2: Run it.**

Run: `php artisan test --compact tests/Feature/LineupCorrectionTest.php`
Expected: FAIL, `Call to undefined method App\Services\Lineup\LineupService::correctTime()`.

- [ ] **Step 3: Implement.** Add the imports, then this section after `endStandby()`:

```php
    // ---- corrections (I-29) ----------------------------------------------------------------------------------

    /**
     * Correct when a machine arrived on (ARRIVAL) or left (DEPARTURE) a face:
     * a move clicked late, or a release recorded at the wrong time. A move is
     * one moment, so the stint it came from (or went to) moves with it. Only
     * Central's own line-up rows change; Dot.Mines is never called. Returns
     * null when the time is unchanged. $expected is the ISO time the screen
     * showed (LU-15 when someone changed it meanwhile).
     */
    public function correctTime(LineupAllocation $allocation, string $edge, string $hhmm, string $reason, User $user, ?string $expected = null): ?LineupCorrection
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($allocation->shift_id);
        $this->guardWritable($shift);
        if (! in_array($edge, LineupCorrection::EDGES, true)) {
            $this->refuse('LU-05', 'Say whether the arrival or the departure is corrected.');
        }
        if ($this->isPlanning($shift)) {
            $this->refuse('LU-05', 'Nothing to correct while planning: before the shift starts every machine is on its face from shift start.');
        }
        if (trim($reason) === '') {
            $this->refuse('LU-05', 'Say why the time is corrected, for example "the move was clicked late".');
        }
        if (trim($hhmm) === '') {
            $this->refuse('LU-05', 'Enter the time it really happened (HH:MM).');
        }
        $at = $this->changeAt($shift, $hhmm);
        $arrival = $edge === LineupCorrection::ARRIVAL;
        $field = $arrival ? 'from_at' : 'to_at';
        $partnerField = $arrival ? 'to_at' : 'from_at';
        $face = LineupFace::withoutGlobalScopes()->findOrFail($allocation->lineup_face_id);
        $unit = Equipment::withoutGlobalScopes()->findOrFail($allocation->equipment_id);
        $who = $unit->name;
        $old = $arrival ? $allocation->from_at : $allocation->to_at;

        if ($expected !== null && ($old === null || ! $old->equalTo(Carbon::parse($expected)))) {
            $this->refuse('LU-15', 'Someone changed this time meanwhile: '.$who.' is now recorded '.($arrival ? 'on ' : 'off ').$face->label().' at '.$this->hm($shift, $old).'. Check it and correct again if it is still wrong.');
        }
        if ($old === null) {
            $this->refuse('LU-05', $who.' is still on '.$face->label().'. Take it off with "Remove" at the time it left.');
        }
        if ($at->equalTo($old)) {
            return null;
        }

        $from = $arrival ? $at : $allocation->from_at;
        $to = $arrival ? $allocation->to_at : $at;
        if ($to !== null && $to->lessThanOrEqualTo($from)) {
            $this->refuse('LU-05', 'Times out of order: '.$who.($arrival
                ? ' left '.$face->label().' at '.$this->hm($shift, $to).'. Pick a time before that.'
                : ' arrived on '.$face->label().' at '.$this->hm($shift, $from).'. Pick a time after that.'));
        }
        $this->refuseOutsideLife($shift, $face, $from, $to);

        $partner = LineupAllocation::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $allocation->equipment_id)
            ->where('id', '!=', $allocation->id)->where($partnerField, $old)->first();
        $partnerFace = null;
        $pFrom = $from;
        $pTo = $to;
        if ($partner !== null) {
            $partnerFace = LineupFace::withoutGlobalScopes()->findOrFail($partner->lineup_face_id);
            $pFrom = $arrival ? $partner->from_at : $at;
            $pTo = $arrival ? $at : $partner->to_at;
            if ($pTo !== null && $pTo->lessThanOrEqualTo($pFrom)) {
                $this->refuse('LU-05', 'Times out of order: '.$who.($arrival
                    ? ' only arrived on '.$partnerFace->label().' at '.$this->hm($shift, $pFrom).'. Correct that time first, or pick a time after it.'
                    : ' left '.$partnerFace->label().' at '.$this->hm($shift, $pTo).'. Correct that time first, or pick a time before it.'));
            }
            $this->refuseOutsideLife($shift, $partnerFace, $pFrom, $pTo);
        }

        $end = $shift->ends_at;
        $overlaps = fn (Carbon $aFrom, ?Carbon $aTo, Carbon $bFrom, ?Carbon $bTo): bool => $aFrom->lessThan($bTo ?? $end) && $bFrom->lessThan($aTo ?? $end);
        $linkedOk = fn (LineupAllocation $x, LineupFace $f, string $role): bool => $x->role === 'LOADER' && $role === 'LOADER' && $x->face !== null && $x->face->sameWorkplaceAs($f);
        $clash = LineupAllocation::withoutGlobalScopes()->with('face')->where('shift_id', $shift->id)->where('equipment_id', $allocation->equipment_id)
            ->whereNotIn('id', array_values(array_filter([$allocation->id, $partner?->id])))->orderBy('from_at')->get()
            ->first(fn (LineupAllocation $x): bool => ($overlaps($x->from_at, $x->to_at, $from, $to) && ! $linkedOk($x, $face, $allocation->role))
                || ($partner !== null && $partnerFace !== null && $overlaps($x->from_at, $x->to_at, $pFrom, $pTo) && ! $linkedOk($x, $partnerFace, $partner->role)));
        if ($clash !== null) {
            $this->refuse('LU-05', 'Overlaps another entry: '.$who.' was on '.$clash->face?->label().' '.$this->hm($shift, $clash->from_at).'–'.$this->hm($shift, $clash->to_at).'. Correct that entry first, or pick a time that does not overlap it.');
        }

        /** @var array{0: Carbon, 1: Carbon}|null $gained the span the machine now spends on a face that it did not before */
        $gained = $arrival
            ? ($at->lessThan($old) ? [$at, $old] : ($partner !== null ? [$old, $at] : null))
            : ($at->greaterThan($old) ? [$old, $at] : ($partner !== null ? [$at, $old] : null));
        if ($gained !== null && ($down = $this->downDuring($unit, $gained[0], $gained[1])) !== null) {
            $this->refuse('LU-02', $who.' was down then: '.$down['cause'].($down['since'] !== null ? ' since '.$this->hm($shift, $down['since']) : '').'. If the stoppage is wrong, correct it where it is recorded: Dot.Mines for its status, the Downtime page for a classification.');
        }

        // The first statements are compare-and-set writes: on SQLite (WAL, DEFERRED) they take the write lock and see the latest
        // commit, so a lost race updates 0 rows and rolls back as LU-15; on Postgres the conditional UPDATE is atomic.
        return DB::transaction(function () use ($shift, $allocation, $partner, $field, $partnerField, $old, $at, $arrival, $gained, $unit, $user, $reason, $face): LineupCorrection {
            if (LineupAllocation::withoutGlobalScopes()->whereKey($allocation->id)->where($field, $old)->update([$field => $at]) !== 1) {
                $this->refuse('LU-15', 'Someone changed this entry meanwhile. Check it and correct again if it is still wrong.');
            }
            if ($partner !== null && LineupAllocation::withoutGlobalScopes()->whereKey($partner->id)->where($partnerField, $old)->update([$partnerField => $at]) !== 1) {
                $this->refuse('LU-15', 'Someone changed the entry it '.($arrival ? 'came from' : 'went to').' meanwhile. Check it and correct again if it is still wrong.');
            }
            if ($gained !== null) {
                $this->trimStandby($shift, $unit, $gained[0], $gained[1], $user);
            }

            $correction = LineupCorrection::create([
                'control_room_id' => $shift->control_room_id, 'shift_id' => $shift->id, 'lineup_allocation_id' => $allocation->id, 'partner_allocation_id' => $partner?->id,
                'equipment_id' => $allocation->equipment_id, 'edge' => $arrival ? LineupCorrection::ARRIVAL : LineupCorrection::DEPARTURE,
                'old_at' => $old, 'new_at' => $at, 'reason' => trim($reason), 'corrected_by' => $user->id,
            ]);
            $changes = [$field => ['old' => $old->toIso8601String(), 'new' => $at->toIso8601String()]];
            if ($partner !== null) {
                $changes['partner_'.$partnerField] = ['old' => $old->toIso8601String(), 'new' => $at->toIso8601String()];
            }
            $text = ($arrival ? 'Arrival on ' : 'Departure from ').$face->label().' corrected from '.$this->hm($shift, $old).' to '.$this->hm($shift, $at).': '.trim($reason);
            $this->audits->record('override', $correction, $changes, $text, $shift->controlRoom, $user, shiftId: $shift->id);
            $this->lineup($shift)->save();

            return $correction;
        });
    }

    /** "HT-01 on Face 2 — … at 10:00 (was 10:30); off Face 1 — … at the same time." */
    public function describeCorrection(LineupCorrection $correction): string
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($correction->shift_id);
        $allocation = LineupAllocation::withoutGlobalScopes()->with('face')->find($correction->lineup_allocation_id);
        $partner = $correction->partner_allocation_id !== null ? LineupAllocation::withoutGlobalScopes()->with('face')->find($correction->partner_allocation_id) : null;
        $name = Equipment::withoutGlobalScopes()->whereKey($correction->equipment_id)->value('name') ?? 'The machine';
        $arrival = $correction->edge === LineupCorrection::ARRIVAL;
        $text = $name.' '.($arrival ? 'on ' : 'off ').($allocation?->face?->label() ?? 'its face').' at '.$this->hm($shift, $correction->new_at).' (was '.$this->hm($shift, $correction->old_at).')';

        return $text.($partner?->face !== null ? '; '.($arrival ? 'off ' : 'on ').$partner->face->label().' at the same time.' : '.');
    }

    /**
     * DOWN at any moment of [from, to): at its start, or a Dot.Mines stopped
     * status or an availability-counting classification that begins inside
     * it. Explicit values only; missing telemetry is never "stopped".
     *
     * @return array{cause: string, since: Carbon|null}|null
     */
    public function downDuring(Equipment $unit, Carbon $from, Carbon $to): ?array
    {
        if (($down = $this->downAt($unit, $from)) !== null) {
            return $down;
        }

        $event = EquipmentStatusEvent::where('equipment_id', $unit->id)->where('field', 'mines_status')->where('observed_at', '>', $from)->where('observed_at', '<', $to)
            ->whereIn('to_value', self::STOPPED)->orderBy('observed_at')->first();
        if ($event !== null) {
            return ['cause' => 'Dot.Mines: '.$event->to_value, 'since' => $event->observed_at];
        }

        $classification = DowntimeClassification::withoutGlobalScopes()->with('reason')->where('equipment_id', $unit->id)
            ->where('started_at', '>', $from)->where('started_at', '<', $to)->orderBy('started_at')->get()
            ->first(fn (DowntimeClassification $c) => $c->reason?->counts_against_availability === true);

        return $classification !== null ? ['cause' => (string) $classification->reason?->name, 'since' => $classification->started_at] : null;
    }

    private function refuseOutsideLife(Shift $shift, LineupFace $face, Carbon $from, ?Carbon $to): void
    {
        $endsAfterClose = $face->isClosed() && $face->closed_at !== null && ($to === null || $to->greaterThan($face->closed_at));
        if ($from->lessThan($face->active_from) || $endsAfterClose) {
            $this->refuse('LU-05', 'Outside the life of the face: '.$face->label().' ran '.$this->hm($shift, $face->active_from).'–'.($face->isClosed() ? $this->hm($shift, $face->closed_at) : 'now').'. Pick a time while the face was working.');
        }
    }

    /** Standby recorded while the machine was really working is ended, removed or started later (Central's own rows, audited). */
    private function trimStandby(Shift $shift, Equipment $unit, Carbon $from, Carbon $to, User $user): void
    {
        $rows = LineupStandby::withoutGlobalScopes()->where('shift_id', $shift->id)->where('equipment_id', $unit->id)
            ->where('from_at', '<', $to)->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>', $from))->get();

        foreach ($rows as $standby) {
            if ($standby->from_at->lessThan($from)) {
                $standby->fill(['to_at' => $from, 'ended_by' => $user->id])->save();
            } elseif ($standby->to_at !== null && $standby->to_at->lessThanOrEqualTo($to)) {
                $standby->delete();
            } else {
                $standby->fill(['from_at' => $to])->save();
            }
        }
    }
```

- [ ] **Step 4: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupCorrectionTest.php tests/Feature/LineupServiceTest.php`
Expected: all pass (6 + 14).

- [ ] **Step 5: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Services/Lineup/LineupService.php tests/Feature/LineupCorrectionTest.php
git commit -m "feat(lineup): correct when a machine arrived on or left a face (I-29)

The partner stint moves with it; standby in the worked span is trimmed;
DOWN, overlaps, face life and stale screens are refused; one reasoned
override audit row; Dot.Mines is never called."
```

---

### Task 5: The correction panel on the Line-up page

**Files:**
- Modify: `app/Livewire/Lineup.php`: the `$correction` property; `startCorrection`, `cancelCorrection` and `saveCorrection`; `place()` handles "already on this face"; `render()` adds `correcting` and `corrected`.
- Modify: `resources/views/livewire/lineup.blade.php`.
- Test: `tests/Feature/LineupPageTest.php`.

- [ ] **Step 1: Write the failing tests** in `LineupPageTest` (add `use App\Models\LineupCorrection;`):

```php
    public function test_a_late_move_is_corrected_from_its_since_time_and_refusals_stay_in_the_panel(): void
    {
        $this->actingAs($this->operator);
        $service = app(LineupService::class);
        $face1 = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $face2 = $service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        $service->place($this->shift, $face1, $this->truck, $this->operator, '08:00');
        $moved = $service->place($this->shift, $face2, $this->truck, $this->operator, '10:30', true);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSeeHtml('aria-label="Correct when HT-01 arrived on Face 2 — Loading area 2 — Overburden (recorded 2026-10-15 10:30)"')
            ->assertSeeHtml('aria-label="Correct when HT-01 left Face 1 — Loading area 1 — Overburden (recorded 2026-10-15 10:30)"')
            ->call('startCorrection', $moved->id, LineupCorrection::ARRIVAL)
            ->assertSee('When did HT-01 arrive on Face 2 — Loading area 2 — Overburden?')
            ->assertSee('Recorded 2026-10-15 10:30. A move is one moment: the face it came from is corrected with it.')
            ->assertSet('correction.time', '10:30')
            ->set('correction.time', '10:00')
            ->call('saveCorrection')
            ->assertSee('Say why the time is corrected')
            ->assertSee('When did HT-01 arrive on Face 2')
            ->set('correction.reason', 'The move was clicked late')
            ->call('saveCorrection')
            ->assertSee('HT-01 on Face 2 — Loading area 2 — Overburden at 10:00 (was 10:30); off Face 1 — Loading area 1 — Overburden at the same time.')
            ->assertDontSee('When did HT-01 arrive')
            ->assertSeeHtml('>corrected</span>');

        $this->assertSame('2026-10-15 10:00:00', $moved->fresh()->from_at->toDateTimeString());
    }

    public function test_clicking_a_machine_already_on_the_face_with_an_earlier_time_opens_the_correction(): void
    {
        $this->actingAs($this->operator);
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $service->place($this->shift, $face, $this->truck, $this->operator, '10:30');

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('place', $this->truck->id, false, $face->id)
            ->assertSee('HT-01 is already on this face (since 10:30; select that time on its row to correct it).')
            ->set('changeAt', '09:45')
            ->call('place', $this->truck->id, false, $face->id)
            ->assertSee('When did HT-01 arrive on Face 1 — Loading area 1 — Overburden?')
            ->assertSet('correction.time', '09:45');
    }

    public function test_managers_see_times_as_text_and_cannot_open_a_correction(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $stint = $service->place($this->shift, $face, $this->truck, $this->operator, '10:30');

        Livewire::actingAs($this->manager)->test(Lineup::class, ['shift' => $this->shift])
            ->assertSee('2026-10-15 10:30')->assertDontSeeHtml('wire:click="startCorrection')
            ->call('startCorrection', $stint->id, LineupCorrection::ARRIVAL)
            ->assertSee('SH-04')->assertSet('correction', []);
    }
```

- [ ] **Step 2: Run them.**

Run: `php artisan test --compact tests/Feature/LineupPageTest.php`
Expected: 3 failures (`Unable to find component method [startCorrection]`, or the missing aria-label).

- [ ] **Step 3: Implement the component.** In `app/Livewire/Lineup.php`, add `use App\Models\LineupCorrection;` and the property after `$closeReason`:

```php
    /** @var array{allocation?: int, edge?: string, expected?: string, time?: string, reason?: string} */
    public array $correction = [];
```

Add the methods after `endStandby()`:

```php
    // ---- corrections (I-29) ---------------------------------------------------------------------------------

    public function startCorrection(int $allocationId, string $edge, ?string $time = null): void
    {
        $this->clearMessages();
        if (! $this->editable()) {
            $this->refusal = 'SH-04 '.$this->shift->label().' is '.$this->shift->status.' or your role cannot change the line-up.';

            return;
        }
        $allocation = LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail($allocationId);
        $old = $edge === LineupCorrection::ARRIVAL ? $allocation->from_at : $allocation->to_at;
        if ($old === null) {
            $this->refusal = 'LU-05 '.$allocation->equipment?->name.' is still on this face. Take it off with "Remove" at the time it left.';

            return;
        }
        $this->correction = [
            'allocation' => $allocation->id, 'edge' => $edge, 'expected' => $old->toIso8601String(),
            'time' => $time ?? $old->copy()->setTimezone($this->shift->controlRoom->timezone())->format('H:i'), 'reason' => '',
        ];
    }

    public function cancelCorrection(): void
    {
        $this->correction = [];
        $this->clearMessages();
    }

    public function saveCorrection(): void
    {
        $this->run(function (LineupService $service): void {
            $allocation = LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->find((int) ($this->correction['allocation'] ?? 0));
            if ($allocation === null) {
                $this->correction = [];
                $this->refusal = 'LU-15 That line-up entry no longer exists: someone removed it meanwhile.';

                return;
            }
            $result = $service->correctTime($allocation, (string) ($this->correction['edge'] ?? ''), (string) ($this->correction['time'] ?? ''), (string) ($this->correction['reason'] ?? ''), auth()->user(), ((string) ($this->correction['expected'] ?? '')) ?: null);
            $this->correction = [];
            $this->notice = $result === null ? 'Nothing changed: that is already the recorded time.' : $service->describeCorrection($result);
        });
    }

    /** Clicking a machine that is already on the face: offer the correction when "Changes recorded at" is earlier than its arrival. */
    private function alreadyHere(LineupService $service, LineupAllocation $here, Equipment $unit): void
    {
        $planning = $service->isPlanning($this->shift);
        if (! $planning && $this->changeAt !== '' && $service->changeAt($this->shift, $this->changeAt)->lessThan($here->from_at)) {
            $this->startCorrection($here->id, LineupCorrection::ARRIVAL, $this->changeAt);

            return;
        }
        $since = $here->from_at->copy()->setTimezone($this->shift->controlRoom->timezone())->format('H:i');
        $this->notice = $unit->name.' is already on this face'.($planning ? '.' : ' (since '.$since.'; select that time on its row to correct it).');
    }
```

In `place()`, right after `$unit = …findOrFail($unitId);`, insert:

```php
            $here = LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->where('lineup_face_id', $face->id)->where('equipment_id', $unit->id)->whereNull('to_at')->first();
            if ($here !== null) {
                $this->alreadyHere($service, $here, $unit);

                return;
            }
```

In `render()`, add these to the view data:

```php
            'correcting' => ($this->correction['allocation'] ?? null) !== null ? LineupAllocation::withoutGlobalScopes()->with(['face', 'equipment'])->find($this->correction['allocation']) : null,
            'corrected' => LineupCorrection::withoutGlobalScopes()->where('shift_id', $this->shift->id)->orderBy('id')->get()
                ->groupBy(fn (LineupCorrection $c) => $c->lineup_allocation_id.':'.$c->edge)
                ->map(fn ($rows) => $rows->map(fn (LineupCorrection $c) => 'was '.$c->old_at->copy()->setTimezone($room->timezone())->format('H:i').': '.$c->reason)->implode('; '))
                ->all(),
```

- [ ] **Step 4: Implement the view** (`resources/views/livewire/lineup.blade.php`).

  1. Change line 24 from `@if($refusal)` to `@if($refusal && ! $correcting)`. Inside the panel, the refusal shows in the dialog instead.

  2. After the `@endif` that closes the "Changes recorded at" / planning / read-only block (line 47), insert the panel:

```blade
    @if($correcting)
        @php($arrival = ($correction['edge'] ?? '') === 'ARRIVAL')
        @php($cName = $correcting->equipment?->name ?? 'The machine')
        <form wire:submit="saveCorrection" wire:keydown.escape="cancelCorrection" class="dot-panel" role="dialog" aria-labelledby="corr-title" aria-describedby="corr-help" style="margin-bottom:1.25rem;display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:0.75rem;">
            <h2 id="corr-title" class="dot-panel__title" style="grid-column:1/-1;">{{ $arrival ? 'When did '.$cName.' arrive on '.$correcting->face?->label().'?' : 'When did '.$cName.' leave '.$correcting->face?->label().'?' }}</h2>
            <p id="corr-help" class="dot-readout__context" style="grid-column:1/-1;margin:0;">Recorded {{ Format::stamp($arrival ? $correcting->from_at : $correcting->to_at, $timezone) }}. A move is one moment: the face it {{ $arrival ? 'came from' : 'went to' }} is corrected with it. Times are the control room's clock ({{ $timezone }}).</p>
            @if($refusal)
                <div class="dot-msg dot-msg--critical" role="alert" style="grid-column:1/-1;"><div class="dot-msg__title">Refused</div><div style="font-size:var(--t-sm);margin-top:0.3rem;">{{ $refusal }}</div></div>
            @endif
            <div><label class="jet-label" for="corr-time">Time (HH:MM)</label><input id="corr-time" wire:model="correction.time" class="dot-input" inputmode="numeric" placeholder="HH:MM" required x-init="$nextTick(() => $el.focus())" /></div>
            <div><label class="jet-label" for="corr-reason">Reason</label><input id="corr-reason" wire:model="correction.reason" class="dot-input" placeholder="e.g. the move was clicked late" required /></div>
            <div style="grid-column:1/-1;display:flex;gap:0.5rem;"><button type="submit" class="dot-btn dot-btn-primary">Correct the time</button><button type="button" class="dot-btn dot-btn-ghost" wire:click="cancelCorrection">Cancel</button></div>
        </form>
    @endif
```

  3. Replace the Since cell (line 127, `<td class="dot-ledger__key">{{ $a->from_at->equalTo(...) ... }}</td>`) with:

```blade
                                    @php($name = $p['equipment']->name ?? '?')
                                    @php($since = $a->from_at->equalTo($shift->starts_at) ? 'shift start' : Format::stamp($a->from_at, $timezone))
                                    <td class="dot-ledger__key">
                                        @if($editable && ! $planning)
                                            <button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="startCorrection({{ $a->id }}, 'ARRIVAL')" aria-label="Correct when {{ $name }} arrived on {{ $face->label() }} (recorded {{ Format::stamp($a->from_at, $timezone) }})" title="Correct when {{ $name }} arrived on this face">{{ $since }}</button>
                                        @else
                                            {{ $since }}
                                        @endif
                                        @if(isset($corrected[$a->id.':ARRIVAL']))<span class="dot-badge dot-badge--info" title="{{ $corrected[$a->id.':ARRIVAL'] }}">corrected</span>@endif
                                    </td>
```

  4. Replace the "Moved off this face" line (line 136) with:

```blade
                    @if($ended->isNotEmpty())
                        <div style="padding:0.5rem 1.25rem;font-size:var(--t-xs);color:var(--text-faint);">Moved off this face:
                            @foreach($ended as $e)
                                @php($eName = $poolByUnit[$e->equipment_id]['equipment']->name ?? '?')
                                <span style="margin-right:0.6rem;">{{ $eName }} {{ Format::stamp($e->from_at, $timezone) }}–@if($editable && ! $planning)<button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="startCorrection({{ $e->id }}, 'DEPARTURE')" aria-label="Correct when {{ $eName }} left {{ $face->label() }} (recorded {{ Format::stamp($e->to_at, $timezone) }})" title="Correct when {{ $eName }} left this face">{{ Format::stamp($e->to_at, $timezone) }}</button>@else{{ Format::stamp($e->to_at, $timezone) }}@endif @if(isset($corrected[$e->id.':DEPARTURE']))<span class="dot-badge dot-badge--info" title="{{ $corrected[$e->id.':DEPARTURE'] }}">corrected</span>@endif</span>
                            @endforeach
                        </div>
                    @endif
```

- [ ] **Step 5: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupPageTest.php tests/Feature/LineupCorrectionTest.php`
Expected: all pass.

- [ ] **Step 6: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Livewire/Lineup.php resources/views/livewire/lineup.blade.php tests/Feature/LineupPageTest.php
git commit -m "feat(lineup): correct arrival and departure times from the face card"
```

---

### Task 6: Corrections on the fleet board, report and handover, and in Dot.Memory

**Files:**
- Modify: `app/Livewire/FleetBoard.php` (`'since'`) and `resources/views/livewire/fleet-board.blade.php` (line 54).
- Modify: `app/Http/Controllers/ReportController.php` (`lineupReport()` notes).
- Modify: `app/Services/Shifts/ShiftService.php` (`lineupSnapshot()` gains `corrections`) and `resources/views/shifts/start.blade.php` (handover card).
- Modify: `app/Services/Memory/AuditLoopMapper.php` (the `correction()` case) and `app/Services/Memory/LoopSubject.php`.
- Test: `tests/Feature/LineupRulesAndHandoverTest.php` and `tests/Feature/Memory/AuditLoopMapperTest.php`.

- [ ] **Step 1: Write the failing tests.** In `LineupRulesAndHandoverTest`, add `use App\Livewire\FleetBoard; use App\Models\LineupCorrection; use Livewire\Livewire;` if missing, and:

```php
    public function test_corrections_are_on_the_fleet_board_the_report_the_handover_and_the_next_start(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $stint = $service->place($this->shift, $face, $this->truck, $this->operator, '10:30');
        $service->correctTime($stint, LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->operator);
        $this->actingAs($this->operator);

        Livewire::test(FleetBoard::class, ['controlRoom' => $this->room])->assertSee('Face 1 — Loading area 1 — Coal · since 10:00');
        $this->get(route('reports.index', ['r' => 'lineup', 'shift' => $this->shift->id]))->assertOk()->assertSee('arrival corrected from 10:30: The move was clicked late');

        Carbon::setTestNow('2026-10-15 18:30:00');
        Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->update(['last_heard_at' => '2026-10-15 18:25:00']);
        $closed = app(ShiftService::class)->close($this->shift, null, $this->operator);
        $corrections = $closed->fresh('handover')->handover->snapshot['lineup']['corrections'];
        $this->assertSame('HT-01 on Face 1 — Loading area 1 — Coal at 10:00 (was 10:30).', $corrections[0]['text']);
        $this->assertSame('The move was clicked late', $corrections[0]['reason']);

        $this->get(route('control-rooms.shifts.start', $this->room))->assertOk()
            ->assertSee('Line-up times corrected (1)')->assertSee('HT-01 on Face 1 — Loading area 1 — Coal at 10:00 (was 10:30).');
    }
```

In `AuditLoopMapperTest`, add `use App\Models\LineupCorrection; use App\Services\Lineup\LineupService;` and:

```php
    public function test_a_line_up_time_correction_is_an_action_on_the_machine(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        $stint = $service->place($this->shift, $face, $this->truck, $this->operator, '10:30');
        MemoryOutboxRow::query()->delete();

        $service->correctTime($stint, LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->operator);

        $action = $this->payload('action');
        $this->assertSame(['lineup.correct_time', 'dot-central', 'succeeded', 'dot-central:lineup:time-corrected'], [$action['action_kind'], $action['executor_platform'], $action['execution_status'], $action['signature']]);
        $this->assertSame(['equipment', '701', 'dot-central:shift:'.$this->shift->id], [$action['subject_type'], $action['subject_id'], $action['loop_id']]);
        $this->assertSame([LineupCorrection::ARRIVAL, 30], [$action['detail']['edge'], $action['detail']['minutes']]);
        $this->assertStringContainsString('corrected from 10:30 to 10:00: The move was clicked late', (string) $action['detail']['reason']);
        $this->assertSame(1, MemoryOutboxRow::where('stage', 'action')->count());
    }
```

- [ ] **Step 2: Run them.**

Run: `php artisan test --compact tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/Memory/AuditLoopMapperTest.php`
Expected: 2 failures (`since 10:00` not found, and `expected a 'action' row`).

- [ ] **Step 3: Fleet board.** In `FleetBoard.php`, add this after `'face' => …`:

```php
                    'since' => $p !== null && $p['placement'] !== null ? $p['placement']->from_at : null,
```

In `fleet-board.blade.php` line 54, change the `'PLACED' => $row['face'],` arm to:

```blade
'PLACED' => $row['face'].($row['since'] ? ' · since '.$row['since']->copy()->setTimezone($timezone)->format('H:i') : ''),
```

- [ ] **Step 4: Report.** In `ReportController::lineupReport()`, add `use App\Models\LineupCorrection;`. Before the faces loop, add:

```php
        $tz = $shift->controlRoom->timezone();
        $corrections = LineupCorrection::withoutGlobalScopes()->where('shift_id', $shift->id)->orderBy('id')->get();
        $noteFor = function (LineupAllocation $a) use ($corrections, $tz): ?string {
            $own = $corrections->where('lineup_allocation_id', $a->id)->map(fn (LineupCorrection $c) => ($c->edge === LineupCorrection::ARRIVAL ? 'arrival' : 'departure').' corrected from '.$c->old_at->copy()->setTimezone($tz)->format('H:i').': '.$c->reason);
            $moved = $corrections->where('partner_allocation_id', $a->id)->map(fn (LineupCorrection $c) => 'moved with a correction ('.$c->reason.')');
            $notes = collect([$a->ended_reason])->merge($own)->merge($moved)->filter();

            return $notes->isEmpty() ? null : $notes->implode('; ');
        };
```

Then change `'note' => $a->ended_reason` to `'note' => $noteFor($a)`.

- [ ] **Step 5: Handover snapshot and Start Shift.**
  - In `ShiftService.php`, add `use App\Models\LineupCorrection;`. In `lineupSnapshot()`'s returned array, add after `'down_now'`:

    ```php
                'corrections' => LineupCorrection::withoutGlobalScopes()->with('correctedBy')->where('shift_id', $shift->id)->orderBy('id')->get()
                    ->map(fn (LineupCorrection $c) => ['text' => $this->lineup->describeCorrection($c), 'reason' => $c->reason, 'by' => $c->correctedBy?->name, 'at' => $c->created_at?->toIso8601String()])->values()->all(),
    ```

  - In `resources/views/shifts/start.blade.php`, after the "Without telemetry at shift end" paragraph (line 67) and inside the `@if($previous && $previous->handover)` block, add:

    ```blade
                        @if(($snap['lineup']['corrections'] ?? []) !== [])
                            <h3 class="dot-readout__label">Line-up times corrected ({{ count($snap['lineup']['corrections']) }})</h3>
                            <ul style="margin:0 0 0.8rem;padding-left:1rem;font-size:var(--t-sm);color:var(--text-quiet);">
                                @foreach($snap['lineup']['corrections'] as $c)<li>{{ $c['text'] }} — {{ $c['reason'] }}{{ $c['by'] ? ' ('.$c['by'].')' : '' }}</li>@endforeach
                            </ul>
                        @endif
    ```

- [ ] **Step 6: Memory.**
  - In `LoopSubject::for()`, add `use App\Models\LineupCorrection;` and extend the equipment arm:

    ```php
                $model instanceof Alert, $model instanceof OperationalException, $model instanceof DowntimeClassification, $model instanceof ShiftOperatorAssignment, $model instanceof LineupCorrection => $model->equipment()->withoutGlobalScopes()->first(),
    ```

  - In `AuditLoopMapper`, add `use App\Models\LineupCorrection;`, add the arm `$subject instanceof LineupCorrection => $this->correction($entry, $subject),` before `default`, and add:

    ```php
        /** @return list<array{stage:string,payload:array<string,mixed>}> */
        private function correction(AuditEntry $entry, LineupCorrection $c): array
        {
            if ($entry->action !== 'override') {
                return [];
            }

            $payload = $this->withDetail($this->base($entry, $c, 'shift:'.$c->shift_id, 'dot-central:lineup:time-corrected', 'correction:'.$c->id), [
                'edge' => $c->edge, 'old_at' => $c->old_at->toIso8601String(), 'new_at' => $c->new_at->toIso8601String(),
                'minutes' => (int) $c->old_at->diffInMinutes($c->new_at, true), 'allocation_id' => $c->lineup_allocation_id, 'partner_allocation_id' => $c->partner_allocation_id,
            ]);

            return [['stage' => 'action', 'payload' => $payload + ['action_kind' => 'lineup.correct_time', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded']]];
        }
    ```

- [ ] **Step 7: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/Memory/AuditLoopMapperTest.php tests/Feature/LineupPageTest.php tests/Feature/OverviewAlertsReportsAdminTest.php`
Expected: all pass.

- [ ] **Step 8: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Livewire/FleetBoard.php resources/views/livewire/fleet-board.blade.php app/Http/Controllers/ReportController.php app/Services/Shifts/ShiftService.php resources/views/shifts/start.blade.php app/Services/Memory/AuditLoopMapper.php app/Services/Memory/LoopSubject.php tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/Memory/AuditLoopMapperTest.php
git commit -m "feat(lineup): corrections on the handover, report, fleet board and Memory loop"
```

**End of Slice 1.** Run the Impeccable detector and the keyboard walkthrough from Task 10, Steps 3–4, on the local Line-up page before starting Slice 2.

---

### Task 7: Linked faces surfaced

**Files:**
- Modify: `app/Services/Lineup/LineupService.php`:
  - new `linkedFaces()` and private `linkGroups()`;
  - `checks()` face loop (`:648-664`);
  - `editFace()` gains `bool $move = false, ?string $hhmm = null` and the link-break refusal;
  - new private `normaliseCut()` / `normaliseBlocks()`, used by `addFace` and `editFace`.
- Modify: `app/Livewire/Lineup.php` (`saveFace(bool $move = false)`, the place notice, `linked` in render) and `resources/views/livewire/lineup.blade.php` (face header, fleet button aria-label, the "Move the loading unit here" fix).
- Modify: `app/Livewire/FleetBoard.php` (every placement), `app/Http/Controllers/ShiftController.php` (`facesTable()` and `outstanding()`), `resources/views/shifts/show.blade.php`, `resources/views/shifts/start.blade.php`, `app/Services/Shifts/ShiftService.php` (snapshot `linked_with`) and `app/Http/Controllers/ReportController.php` (label suffix).
- Test: `tests/Feature/LineupLinkedFacesTest.php` (new).

**Interfaces:**

```php
/** @return Collection<int, LineupFace> */
public function linkedFaces(LineupFace $face): Collection
public function editFace(LineupFace $face, array $attrs, User $user, bool $move = false, ?string $hhmm = null): LineupFace
```

- [ ] **Step 1: Write the failing test file.**

```php
<?php

namespace Tests\Feature;

use App\Livewire\FleetBoard;
use App\Livewire\Lineup;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** I-29 linked faces: one loading unit on two faces at the same cut and block (15-lineup-corrections-design.md §4). */
class LineupLinkedFacesTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck1;

    private Equipment $truck2;

    private LineupService $service;

    private LineupFace $seam;

    private LineupFace $parting;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->truck1 = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->service = app(LineupService::class);
        $this->seam = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Seam', 'cut' => 'C1', 'blocks' => ['B1', 'B2'], 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $this->parting = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Parting', 'cut' => 'C1', 'blocks' => ['B2'], 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['lineup'][0];
        }
        $this->fail('Expected a refusal.');
    }

    public function test_one_loading_unit_on_two_faces_at_the_same_cut_and_block_is_linked_and_lu06_looks_at_both(): void
    {
        $this->assertSame([$this->parting->id], $this->service->linkedFaces($this->seam)->pluck('id')->all());
        $this->assertSame([$this->seam->id], $this->service->linkedFaces($this->parting)->pluck('id')->all());
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->whereNull('to_at')->count());

        $this->service->place($this->shift, $this->seam, $this->truck1, $this->user);
        $this->assertNotContains('LU-06', $this->service->checks($this->shift)->pluck('code')->all(), 'the parting is served by the seam\'s trucks');
    }

    public function test_trucks_on_linked_faces_count_against_the_one_loading_unit(): void
    {
        $this->room->settings()->set('trucks_per_loader', 1);
        $this->service->place($this->shift, $this->seam, $this->truck1, $this->user);
        $this->service->place($this->shift, $this->parting, $this->truck2, $this->user);

        $lu07 = $this->service->checks($this->shift)->where('code', 'LU-07')->values();
        $this->assertCount(1, $lu07);
        $this->assertSame('2 trucks on Face 1 + Face 2 for 1 loading unit(s)', $lu07[0]['title']);
        $this->assertSame($this->seam->id, $lu07[0]['face_id']);
    }

    public function test_changing_the_cut_of_a_linked_face_is_refused_unless_the_loading_unit_moves(): void
    {
        $attrs = ['material' => 'Parting', 'cut' => 'C9', 'blocks' => ['B7']];
        $this->assertStringContainsString('LU-01 EX-01 also loads Face 1 — Loading area 1 — Seam · C1 / B1 B2. After this change the faces are no longer at the same cut and block', $this->refusal(fn () => $this->service->editFace($this->parting, $attrs, $this->user)));
        $this->assertSame('C1', $this->parting->fresh()->cut, 'nothing changed');

        $this->service->editFace($this->parting->fresh(), $attrs, $this->user, move: true, hhmm: '11:00');

        $this->assertSame('C9', $this->parting->fresh()->cut);
        $open = LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->whereNull('to_at')->pluck('lineup_face_id')->all();
        $this->assertSame([$this->parting->id], $open);
        $ended = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $this->seam->id)->where('equipment_id', $this->loader->id)->firstOrFail();
        $this->assertSame('2026-10-15 11:00:00', $ended->to_at->toDateTimeString());
        $this->assertStringContainsString('changed cut / blocks', (string) $ended->ended_reason);
    }

    public function test_the_page_the_fleet_board_the_shift_page_and_the_report_show_the_link(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSee('same loading unit as Face 2')->assertSee('same loading unit as Face 1')
            ->assertSeeHtml('aria-label="EX-01 (placed on Face 1 and Face 2) — put on the target face"');
        Livewire::test(FleetBoard::class, ['controlRoom' => $this->room])
            ->assertSee('Face 1 — Loading area 1 — Seam · C1 / B1 B2 + Face 2 — Loading area 1 — Parting · C1 / B2');
        $this->get(route('shifts.show', $this->shift))->assertOk()->assertSee('EX-01 (also Face 2)');
        $this->get(route('reports.index', ['r' => 'lineup', 'shift' => $this->shift->id]))->assertOk()->assertSee('· same loading unit as Face 2');
    }
}
```

- [ ] **Step 2: Run it.**

Run: `php artisan test --compact tests/Feature/LineupLinkedFacesTest.php`
Expected: FAIL, `Call to undefined method …::linkedFaces()`.

- [ ] **Step 3: Service.** Add `linkedFaces()` after `faces()`:

```php
    /**
     * Faces worked by the same loading unit at the same cut and block at the
     * same time (a seam and its parting): other ACTIVE faces of the shift that
     * share an open LOADER stint with this face and pass sameWorkplaceAs().
     * A face that took over after a split is never linked to its predecessor
     * (that one is CLOSED).
     *
     * @return Collection<int, LineupFace>
     */
    public function linkedFaces(LineupFace $face): Collection
    {
        $loaders = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $face->id)->where('role', 'LOADER')->whereNull('to_at')->pluck('equipment_id')->all();
        if ($loaders === []) {
            return collect();
        }
        $faceIds = LineupAllocation::withoutGlobalScopes()->where('shift_id', $face->shift_id)->where('role', 'LOADER')->whereNull('to_at')
            ->whereIn('equipment_id', $loaders)->where('lineup_face_id', '!=', $face->id)->pluck('lineup_face_id')->unique()->all();

        return LineupFace::withoutGlobalScopes()->whereIn('id', $faceIds)->where('status', 'ACTIVE')->orderBy('seq')->get()
            ->filter(fn (LineupFace $other) => $other->sameWorkplaceAs($face))->values();
    }
```

In `checks()`, replace the `foreach ($this->faces($shift) as $face) { … }` block (`:648-664`) with:

```php
        foreach ($this->linkGroups($this->faces($shift)->where('kind', 'LOADING')->values(), $open) as $group) {
            $groupRows = collect($group)->flatMap(fn (LineupFace $f) => $open->get($f->id, collect()));
            $groupLoaders = $groupRows->where('role', 'LOADER')->pluck('equipment_id')->unique()->count();
            $groupTrucks = $groupRows->where('role', 'HAUL')->count();

            foreach ($group as $face) {
                $rows = $open->get($face->id, collect());
                $loaders = $rows->where('role', 'LOADER')->count();
                $trucks = $rows->where('role', 'HAUL')->count();
                if ($loaders > 0 && $trucks === 0 && $groupTrucks === 0) {
                    $out->push($this->check('LU-06', 'WARNING', 'SUSPICIOUS', $face->label().' has no trucks', 'Its loading unit has no trucks allocated.', 'Click trucks in the fleet panel to put them on this face, or close the face.', null, $face->id));
                } elseif ($trucks > 0 && $loaders === 0) {
                    $out->push($this->check('LU-06', 'WARNING', 'SUSPICIOUS', $face->label().' has '.$trucks.' truck(s) but no loading unit', 'Trucks are allocated to a face without a machine to load them.', 'Edit the face and pick its loading unit, or move the trucks.', null, $face->id));
                }
            }

            if ($groupLoaders > 0 && $groupTrucks > $ceiling * $groupLoaders) {
                $first = $group[0];
                $where = count($group) > 1 ? collect($group)->map(fn (LineupFace $f) => 'Face '.$f->seq)->implode(' + ') : $first->label();
                $out->push($this->check('LU-07', 'WARNING', 'SUSPICIOUS', $groupTrucks.' trucks on '.$where.' for '.$groupLoaders.' loading unit(s)', 'The room ceiling is '.$ceiling.' trucks per loading unit.', 'Move trucks to another face, or raise the ceiling under Administration if the mine runs more.', null, $first->id));
            }
        }
```

Add the helpers in `// ---- helpers`:

```php
    /**
     * Loading faces grouped by a shared loading unit at the same cut and block
     * (union-find); a face on its own is a group of one. Groups and faces keep
     * the faces' seq order.
     *
     * @param  Collection<int, LineupFace>  $faces  0-indexed, in seq order
     * @param  Collection<int|string, Collection<int, LineupAllocation>>  $openByFace
     * @return list<list<LineupFace>>
     */
    private function linkGroups(Collection $faces, Collection $openByFace): array
    {
        $parent = $faces->mapWithKeys(fn (LineupFace $f) => [$f->id => $f->id])->all();
        $find = function (int $id) use (&$parent, &$find): int {
            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };
        $loadersOf = fn (LineupFace $f): array => $openByFace->get($f->id, collect())->where('role', 'LOADER')->pluck('equipment_id')->all();

        foreach ($faces as $i => $a) {
            foreach ($faces->slice($i + 1) as $b) {
                if ($a->sameWorkplaceAs($b) && array_intersect($loadersOf($a), $loadersOf($b)) !== []) {
                    $parent[$find($a->id)] = $find($b->id);
                }
            }
        }

        $groups = [];
        foreach ($faces as $face) {
            $groups[$find($face->id)][] = $face;
        }

        return array_values($groups);
    }

    private function normaliseCut(?string $cut): ?string
    {
        return $cut !== null && trim($cut) !== '' ? strtoupper(trim($cut)) : null;
    }

    /**
     * @param  list<string>|null  $blocks
     * @return list<string>|null
     */
    private function normaliseBlocks(?array $blocks): ?array
    {
        $out = array_values(array_unique(array_map('strtoupper', array_filter(array_map('trim', $blocks ?? [])))));

        return $out === [] ? null : $out;
    }
```

In `addFace()`, replace the inline `'cut' => …` and `'blocks' => …` expressions with `'cut' => $this->normaliseCut($attrs['cut'] ?? null), 'blocks' => $this->normaliseBlocks($attrs['blocks'] ?? null),`.

Replace `editFace()` with:

```php
    /** @param  array{location?: string|null, kind?: string, material?: string|null, activity?: string|null, cut?: string|null, blocks?: list<string>|null, notes?: string|null}  $attrs */
    public function editFace(LineupFace $face, array $attrs, User $user, bool $move = false, ?string $hhmm = null): LineupFace
    {
        $shift = $face->shift;
        $this->guardWritable($shift);
        if ($face->isClosed()) {
            $this->refuse('LU-05', $face->label().' is closed. Add a new face instead.');
        }
        $this->validateFace($attrs + ['kind' => $face->kind]);

        $kind = $attrs['kind'] ?? $face->kind;
        if ($kind === 'AREA' && $face->kind === 'LOADING' && $face->allocations()->whereNull('to_at')->where('role', 'HAUL')->exists()) {
            $this->refuse('LU-03', 'Trucks are still on '.$face->label().'. Take them off before turning it into a work area.');
        }

        $cut = $this->normaliseCut($attrs['cut'] ?? null);
        $blocks = $this->normaliseBlocks($attrs['blocks'] ?? null);
        $probe = new LineupFace(['cut' => $cut, 'blocks' => $blocks]);
        $strays = $this->linkedFaces($face)->reject(fn (LineupFace $other) => $kind === 'LOADING' && $probe->sameWorkplaceAs($other))->values();
        $mine = $face->allocations()->where('role', 'LOADER')->whereNull('to_at')->pluck('equipment_id')->all();
        $shared = $strays->isEmpty() ? collect() : LineupAllocation::withoutGlobalScopes()->with('equipment')->whereIn('lineup_face_id', $strays->pluck('id')->all())
            ->where('role', 'LOADER')->whereNull('to_at')->whereIn('equipment_id', $mine)->get();
        if ($shared->isNotEmpty() && ! $move) {
            $labels = $strays->map(fn (LineupFace $f) => $f->label())->implode(' and ');
            $this->refuse('LU-01', $shared->map(fn (LineupAllocation $a) => $a->equipment?->name)->unique()->implode(' and ').' also loads '.$labels.'. After this change the faces are no longer at the same cut and block, so it cannot work both. Choose "Move the loading unit here" to take it off '.$labels.', or keep the cut and blocks.');
        }

        $planning = $this->isPlanning($shift);
        $at = $shared->isNotEmpty() ? $this->changeAt($shift, $hhmm) : null;
        foreach ($shared as $allocation) {
            if ($at !== null && ! $planning && $at->lessThan($allocation->from_at)) {
                $this->refuse('LU-05', 'Time before the machine arrived: '.$allocation->equipment?->name.' only arrived on '.$strays->firstWhere('id', $allocation->lineup_face_id)?->label().' at '.$this->hm($shift, $allocation->from_at).'.');
            }
        }

        $location = isset($attrs['location']) ? $this->locations->resolve($shift->controlRoom, $attrs['location']) : [];

        return DB::transaction(function () use ($face, $shared, $at, $user, $planning, $location, $kind, $attrs, $cut, $blocks): LineupFace {
            foreach ($shared as $allocation) {
                $this->endAllocation($allocation, $at ?? now(), 'Moved: '.$face->label().' changed cut / blocks', $user, $planning);
            }
            $face->fill($location + [
                'kind' => $kind,
                'material' => $kind === 'LOADING' ? ($attrs['material'] ?? null) : null,
                'activity' => $kind === 'AREA' ? ($attrs['activity'] ?? null) : null,
                'cut' => $cut,
                'blocks' => $blocks,
                'notes' => isset($attrs['notes']) && trim($attrs['notes']) !== '' ? trim($attrs['notes']) : null,
            ])->save();

            return $face;
        });
    }
```

- [ ] **Step 4: Livewire and view.**

  1. Change `saveFace()` to `saveFace(bool $move = false): void`. Use `use ($move)` in the closure, and wrap the edit call:

     ```php
                     try {
                         $service->editFace($face, $attrs, auth()->user(), move: $move, hhmm: $this->changeAt ?: null);
                     } catch (ValidationException $e) {
                         if (str_starts_with($e->errors()['lineup'][0] ?? '', 'LU-01')) {
                             $this->fix = ['face_move' => true];
                         }
                         throw $e;
                     }
     ```

  2. In `place()`, replace the notice line with:

     ```php
                 $linked = $allocation->role === 'LOADER' ? $service->linkedFaces($face) : collect();
                 $this->notice = $unit->name.' → '.$face->label().($allocation->from_at->equalTo($this->shift->starts_at) ? '' : ' at '.$allocation->from_at->setTimezone($this->shift->controlRoom->timezone())->format('H:i'))
                     .($linked->isNotEmpty() ? ' (also loads '.$linked->map(fn (LineupFace $l) => 'Face '.$l->seq)->implode(' and ').': same cut and block)' : '').'.';
     ```

  3. In `render()`, add:

     ```php
                 'linked' => $faces->mapWithKeys(fn (LineupFace $f) => [$f->id => $service->linkedFaces($f)->map(fn (LineupFace $l) => 'Face '.$l->seq)->implode(' and ')])->filter()->all(),
     ```

  4. Blade, face header (line 97): before `{{ $target === $face->id ? ' · machines you click go here' : '' }}`, insert `@if(($linked[$face->id] ?? '') !== '') · same loading unit as {{ $linked[$face->id] }}@endif`.

  5. Blade, fleet button (line 205): replace the `aria-label="…"` with:

     ```blade
     aria-label="{{ $p['equipment']->name }} ({{ $p['state'] === 'PLACED' ? 'placed on '.$p['placements']->map(fn ($a) => 'Face '.$a->face->seq)->implode(' and ') : strtolower($p['state']) }}) — put on the target face"
     ```

  6. Blade, refusal block: after the `@elseif(($fix['operator'] ?? null) !== null) … ` branch, add:

     ```blade
                 @elseif(($fix['face_move'] ?? false) === true)
                     <div style="margin-top:0.5rem;"><button type="button" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="saveFace(true)">Move the loading unit here</button></div>
     ```

- [ ] **Step 5: The other surfaces.**
  - **`FleetBoard.php`:** `'face' => $p !== null ? ($p['placements']->sortBy(fn ($a) => $a->face?->seq)->map(fn ($a) => $a->face?->label())->filter()->implode(' + ') ?: null) : null,`
  - **`ShiftController::facesTable()`:** add `'linked' => $this->lineup->linkedFaces($face)->map(fn (LineupFace $l) => 'Face '.$l->seq)->all()` to each row (add `use App\Models\LineupFace;`).
  - **`ShiftController::outstanding()`:** add `'linked_with' => $this->lineup->linkedFaces($face)->map(fn (LineupFace $l) => 'Face '.$l->seq)->all()` to each face.
  - **`shifts/show.blade.php` line 24:** change the loading-units cell to `<td>{{ implode(' + ', $row['loaders']) ?: '—' }}@if($row['linked'] !== []) (also {{ implode(', ', $row['linked']) }})@endif</td>`.
  - **`shifts/start.blade.php` line 91:** change `$f['label'].' ('` to `$f['label'].(($f['linked_with'] ?? []) !== [] ? ' · same loading unit as '.implode(' and ', $f['linked_with']) : '').' ('`.
  - **`ShiftService::lineupSnapshot()`:** add `'linked_with' => $this->lineup->linkedFaces($face)->map(fn ($l) => 'Face '.$l->seq)->all()` to each face entry.
  - **`ReportController::lineupReport()`:** inside the faces loop, compute `$link = $this->lineup->linkedFaces($face)->map(fn ($l) => 'Face '.$l->seq)->implode(' and ');` and change the face column to `$face->label().($link !== '' ? ' · same loading unit as '.$link : '').($face->isClosed() ? ' (closed)' : '')`.

- [ ] **Step 6: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupLinkedFacesTest.php tests/Feature/LineupServiceTest.php tests/Feature/LineupPageTest.php tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/RuleEngineTest.php`
Expected: all pass. The singleton LU-06 / LU-07 texts and keys are unchanged.

- [ ] **Step 7: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add -A app resources tests/Feature/LineupLinkedFacesTest.php
git commit -m "feat(lineup): surface linked faces; pool LU-06/LU-07; refuse link-breaking edits"
```

---

### Task 8: Material split service and the in-place correction decision

**Files:**
- Modify: `app/Services/Lineup/LineupService.php`:
  - `editFace()` gains `bool $materialCorrection = false` and the LU-14 decision;
  - new `splitFace()` and `splitOrigin()`.
- Test: `tests/Feature/LineupMaterialSplitTest.php` (new).

**Interfaces:**

```php
public function editFace(LineupFace $face, array $attrs, User $user, bool $move = false, ?string $hhmm = null, bool $materialCorrection = false): LineupFace
public function splitFace(LineupFace $face, string $material, User $user, ?string $hhmm = null, ?string $reason = null): LineupFace
public function splitOrigin(LineupFace $face): ?string   // "Face 1 (Overburden until 10:00)"
```

- [ ] **Step 1: Write the failing test file.**

```php
<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** I-29 material split as a time split; no quantity is split (D3) (15-lineup-corrections-design.md §5). */
class LineupMaterialSplitTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $user;

    private Equipment $loader;

    private Equipment $truck1;

    private Equipment $truck2;

    private LineupService $service;

    private LineupFace $face;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Http::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $this->room->users()->attach($this->user);
        $this->shift = $this->openShift('DAY', '2026-10-15', '2026-10-15 06:00:00', '2026-10-15 18:00:00');
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck1 = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->truck2 = Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service = app(LineupService::class);
        $this->face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->user, '07:00');
        $this->service->place($this->shift, $this->face, $this->truck1, $this->user, '07:30');
        $this->service->place($this->shift, $this->face, $this->truck2, $this->user, '08:00');
    }

    private function openShift(string $code, string $date, string $start, string $end): Shift
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', $code)->firstOrFail();

        return Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => $date, 'starts_at' => $start, 'ends_at' => $end, 'status' => 'OPEN']);
    }

    private function refusal(callable $fn): string
    {
        try {
            $fn();
        } catch (ValidationException $e) {
            return $e->errors()['lineup'][0];
        }
        $this->fail('Expected a refusal.');
    }

    public function test_a_material_change_on_a_working_face_needs_a_decision_and_a_correction_edits_in_place(): void
    {
        $attrs = ['material' => 'Interburden', 'cut' => 'C1', 'blocks' => ['B1']];
        $message = $this->refusal(fn () => $this->service->editFace($this->face, $attrs, $this->user));
        $this->assertStringContainsString('LU-14 Face 1 — Loading area 1 — Overburden · C1 / B1 has been loading Overburden since 07:00.', $message);

        $this->service->editFace($this->face->fresh(), $attrs, $this->user, materialCorrection: true);

        $this->assertSame('Interburden', $this->face->fresh()->material);
        $audit = AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'override')->firstOrFail();
        $this->assertSame('Material corrected from Overburden to Interburden (wrong from the start)', $audit->reason);
        $this->assertSame(0, AuditEntry::where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'updated')->count(), 'one reasoned row, no reason-less duplicate');

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $planned = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $this->service->editFace($planned, ['material' => 'Softs'], $this->user);
        $this->assertSame('Softs', $planned->fresh()->material, 'while planning there is nothing to decide');
    }

    public function test_a_face_that_changed_material_at_a_time_is_split_and_the_machines_carry_on(): void
    {
        $truck2Stint = LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck2->id)->firstOrFail();
        $this->service->release($truck2Stint, 'Refuelling', $this->user, '11:00');

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00', 'Hit the interburden');

        $old = $this->face->fresh();
        $this->assertSame(['CLOSED', '2026-10-15 10:00:00', 'Material changed to Interburden: Hit the interburden'], [$old->status, $old->closed_at->toDateTimeString(), $old->close_reason]);
        $this->assertSame([2, 'Interburden', 'C1', ['B1'], 'Loading area 1', '2026-10-15 10:00:00', $old->id], [$new->seq, $new->material, $new->cut, $new->blocks, $new->location_name, $new->active_from->toDateTimeString(), $new->split_from_face_id]);
        $this->assertSame('Face 1 (Overburden until 10:00)', $this->service->splitOrigin($new));

        $open = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->whereNull('to_at')->orderBy('equipment_id')->get();
        $this->assertSame([$this->loader->id, $this->truck1->id], $open->pluck('equipment_id')->all());
        $this->assertTrue($open->every(fn (LineupAllocation $a) => $a->from_at->toDateTimeString() === '2026-10-15 10:00:00'));
        $this->assertSame(3, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $old->id)->where('to_at', '2026-10-15 10:00:00')->where('ended_reason', 'Face changed material')->count());

        $remainder = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->where('equipment_id', $this->truck2->id)->firstOrFail();
        $this->assertSame(['2026-10-15 10:00:00', '2026-10-15 11:00:00', 'Refuelling'], [$remainder->from_at->toDateTimeString(), $remainder->to_at->toDateTimeString(), $remainder->ended_reason]);
        $this->assertSame([], $this->service->linkedFaces($new)->all());
        Http::assertNothingSent();
    }

    public function test_splits_that_cannot_be_right_are_refused(): void
    {
        $this->assertStringContainsString('LU-05 Pick the new material', $this->refusal(fn () => $this->service->splitFace($this->face, ' overburden ', $this->user, '10:00')));
        $this->assertStringContainsString('only started at 07:00. Pick a later time.', $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '06:30')));
        $area = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'AREA', 'activity' => 'Dozing'], $this->user, '07:00');
        $this->assertStringContainsString('Only a loading face changes material', $this->refusal(fn () => $this->service->splitFace($area, 'Interburden', $this->user, '10:00')));

        $stale = LineupFace::withoutGlobalScopes()->findOrFail($this->face->id);
        $this->service->splitFace($this->face, 'Interburden', $this->user, '10:00');
        $this->assertStringContainsString('LU-15', $this->refusal(fn () => $this->service->splitFace($stale, 'Softs', $this->user, '10:30')));
        $this->assertStringContainsString('is closed', $this->refusal(fn () => $this->service->splitFace($this->face->fresh(), 'Softs', $this->user, '10:30')));

        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = $this->openShift('NIGHT', '2026-10-15', '2026-10-15 18:00:00', '2026-10-16 06:00:00');
        $planned = $this->service->addFace($night, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->user);
        $this->assertStringContainsString('Before the shift starts, edit the face instead', $this->refusal(fn () => $this->service->splitFace($planned, 'Softs', $this->user)));
    }
}
```

- [ ] **Step 2: Run it.**

Run: `php artisan test --compact tests/Feature/LineupMaterialSplitTest.php`
Expected: FAIL. The first test says `Expected a refusal.`; the others fail on the undefined `splitFace()`.

- [ ] **Step 3: Implement `editFace`.** Change the signature to add `bool $materialCorrection = false`. After the LU-03 trucks check, add:

```php
        $planning = $this->isPlanning($shift);
        $newMaterial = $kind === 'LOADING' ? trim((string) ($attrs['material'] ?? '')) : '';
        $materialChanged = $face->kind === 'LOADING' && $kind === 'LOADING' && $face->material !== null && mb_strtolower($newMaterial) !== mb_strtolower($face->material);
        if ($materialChanged && ! $materialCorrection && ! $planning && $this->changeAt($shift, $hhmm)->greaterThan($face->active_from)) {
            $this->refuse('LU-14', $face->label().' has been loading '.$face->material.' since '.$this->hm($shift, $face->active_from).'. Say whether the material was wrong from the start (correct it on this face) or the face changed material at a time (a new face carries on from then; the line-up keeps '.$face->material.' until that time).');
        }
```

Then remove the later `$planning = $this->isPlanning($shift);` line (it is now defined above). Inside the transaction, replace `])->save();` on the face with:

```php
            ]);
            if ($materialChanged && $materialCorrection) {
                $old = (string) $face->getOriginal('material');
                $changes = collect($face->getDirty())->except(['updated_at'])->map(fn ($new, $key) => ['old' => $face->getOriginal($key), 'new' => $new])->all();
                $face->saveQuietly();
                $this->audits->record('override', $face, $changes, 'Material corrected from '.$old.' to '.$face->material.' (wrong from the start)', $face->shift->controlRoom, $user, shiftId: $face->shift_id);
            } else {
                $face->save();
            }
```

Add `$materialChanged, $materialCorrection` to the closure's `use (...)` list.

- [ ] **Step 4: Implement `splitFace` and `splitOrigin`** after `closeFace()`:

```php
    /**
     * The face changed material at a time: it closes then and a new face
     * carries on with the same machines, so the line-up keeps the old
     * material until that time. Labels and times only: no quantity is split
     * (D3). Stints that began after the time move to the new face; stints
     * that ended after it are cut there and their remainder moves.
     */
    public function splitFace(LineupFace $face, string $material, User $user, ?string $hhmm = null, ?string $reason = null): LineupFace
    {
        $shift = Shift::withoutGlobalScopes()->findOrFail($face->shift_id);
        $this->guardWritable($shift);
        if ($face->isClosed()) {
            $this->refuse('LU-05', $face->label().' is closed. Add a new face instead.');
        }
        if ($face->kind !== 'LOADING') {
            $this->refuse('LU-05', 'Only a loading face changes material; for a work area, edit its activity.');
        }
        $material = trim($material);
        if ($material === '' || mb_strtolower($material) === mb_strtolower((string) $face->material)) {
            $this->refuse('LU-05', 'Pick the new material: the new face needs a different material.');
        }
        if ($this->isPlanning($shift)) {
            $this->refuse('LU-05', 'Before the shift starts, edit the face instead: nothing has happened on it yet.');
        }
        $at = $this->changeAt($shift, $hhmm);
        if ($at->lessThanOrEqualTo($face->active_from)) {
            $this->refuse('LU-05', $face->label().' only started at '.$this->hm($shift, $face->active_from).'. Pick a later time.');
        }
        $closeReason = 'Material changed to '.$material.($reason !== null && trim($reason) !== '' ? ': '.trim($reason) : '');

        return DB::transaction(function () use ($shift, $face, $material, $user, $at, $closeReason): LineupFace {
            $closed = LineupFace::withoutGlobalScopes()->whereKey($face->id)->where('status', 'ACTIVE')
                ->update(['status' => 'CLOSED', 'closed_at' => $at, 'close_reason' => $closeReason, 'closed_by' => $user->id]);
            if ($closed !== 1) {
                $this->refuse('LU-15', $face->label().' was changed by someone else meanwhile. Check it and try again.');
            }
            $this->audits->record('updated', $face, ['status' => ['old' => 'ACTIVE', 'new' => 'CLOSED'], 'closed_at' => ['old' => null, 'new' => $at->toIso8601String()]], $closeReason.' at '.$this->hm($shift, $at), $shift->controlRoom, $user, shiftId: $shift->id);

            $new = LineupFace::create([
                'control_room_id' => $face->control_room_id, 'shift_id' => $face->shift_id,
                'seq' => (int) LineupFace::withoutGlobalScopes()->where('shift_id', $face->shift_id)->max('seq') + 1,
                'kind' => 'LOADING', 'mine_area_id' => $face->mine_area_id,
                'location_source' => $face->location_source, 'location_ref' => $face->location_ref, 'location_name' => $face->location_name,
                'material' => $material, 'activity' => null, 'cut' => $face->cut, 'blocks' => $face->blocks, 'notes' => $face->notes,
                'status' => 'ACTIVE', 'active_from' => $at, 'split_from_face_id' => $face->id,
            ]);

            $stints = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $face->id)
                ->where(fn ($q) => $q->whereNull('to_at')->orWhere('to_at', '>', $at))->orderBy('from_at')->get();
            foreach ($stints as $stint) {
                if ($stint->from_at->greaterThanOrEqualTo($at)) {
                    $stint->fill(['lineup_face_id' => $new->id])->save();

                    continue;
                }
                [$end, $endedReason, $endedBy] = [$stint->to_at, $stint->ended_reason, $stint->ended_by];
                $stint->fill(['to_at' => $at, 'ended_reason' => 'Face changed material', 'ended_by' => $user->id])->save();
                LineupAllocation::create([
                    'control_room_id' => $stint->control_room_id, 'shift_id' => $stint->shift_id, 'lineup_face_id' => $new->id, 'equipment_id' => $stint->equipment_id,
                    'role' => $stint->role, 'from_at' => $at, 'to_at' => $end, 'ended_reason' => $end !== null ? $endedReason : null, 'ended_by' => $end !== null ? $endedBy : null,
                    'created_by' => $user->id,
                ]);
            }
            $this->lineup($shift)->save();

            return $new;
        });
    }

    /** "Face 1 (Overburden until 10:00)" for a face that carries on after a material change, else null. */
    public function splitOrigin(LineupFace $face): ?string
    {
        $previous = $face->split_from_face_id !== null ? LineupFace::withoutGlobalScopes()->find($face->split_from_face_id) : null;
        $shift = Shift::withoutGlobalScopes()->find($face->shift_id);
        if ($previous === null || $shift === null) {
            return null;
        }

        return 'Face '.$previous->seq.' ('.$previous->material.' until '.$this->hm($shift, $previous->closed_at).')';
    }
```

- [ ] **Step 5: Run the tests.**

Run: `php artisan test --compact tests/Feature/LineupMaterialSplitTest.php tests/Feature/LineupLinkedFacesTest.php tests/Feature/LineupServiceTest.php`
Expected: all pass.

- [ ] **Step 6: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add app/Services/Lineup/LineupService.php tests/Feature/LineupMaterialSplitTest.php
git commit -m "feat(lineup): material split as a time split; in-place correction with reason (I-29)"
```

---

### Task 9: Material split on the page, the handover, the report and in Dot.Memory

**Files:**
- Modify: `app/Livewire/Lineup.php`: `saveFace(bool $move = false, bool $materialCorrection = false)`, the new `splitFromForm()`, and `splitFrom` in render.
- Modify: `resources/views/livewire/lineup.blade.php` (decision buttons and the face header).
- Modify: `app/Services/Shifts/ShiftService.php` (snapshot `split_from`), `app/Http/Controllers/ReportController.php` (label suffix), `app/Services/Memory/AuditLoopMapper.php` (`faceSplit()`) and `app/Services/Memory/LoopSubject.php` (`LineupFace` as a shift subject).
- Test: `tests/Feature/LineupPageTest.php`, `tests/Feature/LineupRulesAndHandoverTest.php` and `tests/Feature/Memory/AuditLoopMapperTest.php`.

- [ ] **Step 1: Write the failing tests.** In `LineupPageTest` (add `use App\Models\LineupFace;`):

```php
    public function test_a_material_change_on_a_working_face_asks_and_a_split_carries_the_machines_on(): void
    {
        $this->actingAs($this->operator);
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $service->place($this->shift, $face, $this->truck, $this->operator, '07:30');

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('openFaceForm', $face->id)->set('faceForm.material', 'Interburden')->set('faceForm.cut', 'C2')->call('saveFace')
            ->assertSee('LU-14')->assertSee('The material was wrong — correct it')->assertSee('It changed at 12:00 — new face from then')
            ->call('splitFromForm')->assertSee('Change only the material for a split')
            ->set('faceForm.cut', 'C1')->call('saveFace')->call('splitFromForm')
            ->assertSee('Face 1 — Loading area 1 — Overburden · C1 / B1 closed at 12:00; Face 2 — Loading area 1 — Interburden · C1 / B1 carries on with the same 2 machines from 12:00.')
            ->assertSee('carries on from Face 1 (Overburden until 12:00)')
            ->assertSee('Material changed to Interburden');

        $new = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->where('seq', 2)->firstOrFail();
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->whereNull('to_at')->count());
    }
```

In `LineupRulesAndHandoverTest`:

```php
    public function test_the_handover_shows_linked_faces_and_where_a_split_face_came_from(): void
    {
        $service = app(LineupService::class);
        $seam = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Parting', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->operator, '07:00');
        $service->place($this->shift, $seam, $this->truck, $this->operator, '07:30');
        $service->splitFace($seam, 'Dirty coal', $this->operator, '10:00');

        Carbon::setTestNow('2026-10-15 18:30:00');
        Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->update(['last_heard_at' => '2026-10-15 18:25:00']);
        $faces = collect(app(ShiftService::class)->close($this->shift, null, $this->operator)->fresh('handover')->handover->snapshot['lineup']['faces'])->keyBy('label');

        $this->assertSame('Face 1 (Coal until 10:00)', $faces['Face 3 — Loading area 1 — Dirty coal · C1 / B1']['split_from']);
        $this->assertSame(['Face 3'], $faces['Face 2 — Loading area 1 — Parting · C1 / B1']['linked_with']);
        $this->assertSame(['Face 2'], $faces['Face 3 — Loading area 1 — Dirty coal · C1 / B1']['linked_with']);
    }
```

In `AuditLoopMapperTest`:

```php
    public function test_a_material_split_is_an_action_on_the_shift(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $this->operator, '07:00');
        MemoryOutboxRow::query()->delete();

        $service->splitFace($face, 'Interburden', $this->operator, '10:00');

        $action = $this->payload('action');
        $this->assertSame(['lineup.split_face', 'dot-central:lineup:face-split', 'shift', (string) $this->shift->id], [$action['action_kind'], $action['signature'], $action['subject_type'], $action['subject_id']]);
        $this->assertSame([$face->id, 'Overburden', 'Interburden'], [$action['detail']['split_from_face_id'], $action['detail']['material_before'], $action['detail']['material_after']]);
        $this->assertSame(1, MemoryOutboxRow::where('stage', 'action')->count(), 'ordinary face rows map to nothing');
    }
```

- [ ] **Step 2: Run them.**

Run: `php artisan test --compact tests/Feature/LineupPageTest.php tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/Memory/AuditLoopMapperTest.php`
Expected: 3 failures (`Unable to find component method [splitFromForm]`, `Undefined array key "split_from"`, and `expected a 'action' row`).

- [ ] **Step 3: Livewire.**
  1. Change the signature to `saveFace(bool $move = false, bool $materialCorrection = false)`, use `use ($move, $materialCorrection)`, and pass `materialCorrection: $materialCorrection` to `editFace`.
  2. In the catch block, add the LU-14 branch:

     ```php
                         } elseif (str_starts_with($e->errors()['lineup'][0] ?? '', 'LU-14')) {
                             $this->fix = ['material_at' => $this->changeAt !== '' ? $this->changeAt : $service->refTime($this->shift)->setTimezone($this->shift->controlRoom->timezone())->format('H:i')];
     ```

  3. Add the method:

     ```php
         /** "It changed at HH:MM — new face from then": only the material may differ from the face as it is. */
         public function splitFromForm(): void
         {
             $this->run(function (LineupService $service): void {
                 $face = LineupFace::withoutGlobalScopes()->where('shift_id', $this->shift->id)->findOrFail((int) $this->editingFaceId);
                 $form = $this->faceForm;
                 $blocks = array_values(array_filter(preg_split('/[\s,]+/', strtoupper(trim((string) $form['blocks']))) ?: []));
                 $location = $face->location_source !== null ? $face->location_source.':'.($face->location_ref ?? $face->location_name) : '';
                 $same = $form['kind'] === $face->kind && strtoupper(trim((string) $form['cut'])) === (string) $face->cut && $blocks === ($face->blocks ?? [])
                     && trim((string) $form['notes']) === (string) $face->notes && (string) $form['location'] === $location;
                 if (! $same) {
                     $this->refusal = 'LU-05 Change only the material for a split. The new face takes over the location, cut, blocks, notes and machines as they are now. Undo the other changes, split, then edit the new face.';

                     return;
                 }
                 $label = $face->label();
                 $new = $service->splitFace($face, (string) $form['material'], auth()->user(), $this->changeAt ?: null);
                 $at = $new->active_from->copy()->setTimezone($this->shift->controlRoom->timezone())->format('H:i');
                 $carried = LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->whereNull('to_at')->count();
                 $this->notice = $label.' closed at '.$at.'; '.$new->label().' carries on with the same '.$carried.' machines from '.$at.'.';
                 $this->target = $new->id;
                 $this->cancelFaceForm();
             });
         }
     ```

  4. In render, add: `'splitFrom' => $faces->mapWithKeys(fn (LineupFace $f) => [$f->id => $service->splitOrigin($f)])->filter()->all(),`

- [ ] **Step 4: View.**
  - In the refusal block, after the `face_move` branch, add:

    ```blade
                @elseif(($fix['material_at'] ?? null) !== null)
                    <div style="margin-top:0.5rem;display:flex;gap:0.5rem;flex-wrap:wrap;">
                        <button type="button" class="dot-btn dot-btn-ghost dot-btn-sm" wire:click="saveFace(false, true)">The material was wrong — correct it</button>
                        <button type="button" class="dot-btn dot-btn-primary dot-btn-sm" wire:click="splitFromForm">It changed at {{ $fix['material_at'] }} — new face from then</button>
                    </div>
    ```

  - In the face header (line 97), after the linked-face fragment, add: `@if(isset($splitFrom[$face->id])) · carries on from {{ $splitFrom[$face->id] }}@endif`.

- [ ] **Step 5: Snapshot, report, Memory.**
  - **`ShiftService::lineupSnapshot()`:** add `'split_from' => $this->lineup->splitOrigin($face)` to each face entry.
  - **`ReportController::lineupReport()`:** append `.(($origin = $this->lineup->splitOrigin($face)) !== null ? ' · carries on from '.$origin : '')` to the face column.
  - **`LoopSubject::for()`:** before the room fallback, add `use App\Models\LineupFace;` and:

    ```php
            if ($model instanceof LineupFace) {
                $shift = $model->shift()->withoutGlobalScopes()->first();

                return ['type' => 'shift', 'id' => (string) $model->shift_id, 'label' => $shift?->label()];
            }
    ```

  - **`AuditLoopMapper`:** add the arm `$subject instanceof LineupFace => $this->faceSplit($entry, $subject),` before `default`, and:

    ```php
        /** @return list<array{stage:string,payload:array<string,mixed>}> */
        private function faceSplit(AuditEntry $entry, LineupFace $f): array
        {
            if ($entry->action !== 'created' || $f->split_from_face_id === null) {
                return [];
            }

            $previous = LineupFace::withoutGlobalScopes()->find($f->split_from_face_id);
            $payload = $this->withDetail($this->base($entry, $f, 'shift:'.$f->shift_id, 'dot-central:lineup:face-split', 'face-split:'.$f->id), [
                'face_id' => $f->id, 'split_from_face_id' => $f->split_from_face_id, 'material_before' => $previous?->material, 'material_after' => $f->material, 'at' => $f->active_from->toIso8601String(),
            ]);

            return [['stage' => 'action', 'payload' => $payload + ['action_kind' => 'lineup.split_face', 'executor_platform' => 'dot-central', 'approval_status' => 'not_required', 'execution_status' => 'succeeded']]];
        }
    ```

- [ ] **Step 6: Run the line-up and Memory suites.**

Run: `php artisan test --compact tests/Feature/LineupPageTest.php tests/Feature/LineupRulesAndHandoverTest.php tests/Feature/Memory tests/Feature/LineupMaterialSplitTest.php tests/Feature/LineupLinkedFacesTest.php tests/Feature/LineupCorrectionTest.php`
Expected: all pass.

- [ ] **Step 7: Pint, PHPStan, commit.**

```bash
vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse --memory-limit=1G
git add -A app resources tests
git commit -m "feat(lineup): material split on the page, handover, report and Memory loop"
```

---

### Task 10: Docs, full verification, keyboard walkthrough

**Files:**
- Modify: `docs/dot-central/13-shift-lineup-design.md`, `docs/dot-central/10-outstanding-issues.md` and `docs/dot-central/05-feature-matrix.md`.

- [ ] **Step 1: Docs.**
  - **`13` §6, last bullet.** Replace it with: "Arrival / departure corrections, linked faces and the material split (as a time split) are built: `15-lineup-corrections-design.md`."
  - **`13` §11.** Replace "Arrival / departure corrections, material splits, linked faces (I-29)." with: "Material quantities per face are not derivable and not captured (D3); breakdowns at shift change are replaced by derived DOWN (15 §0, D-6)."
  - **`10` I-29 row.** Set Status to "Built on branch feat/lineup-corrections (design 15); closes when merged". Set Impact to "Late moves are corrected with the partner stint; one loader on two faces at the same cut and block is shown and checked as one; a face that changes material is split at a time; breakdowns at shift change are not built (derived DOWN)". Set Next step to "Owner merges after review".
  - **`05` F-29 row.** Append: "I-29 (design 15): arrival / departure corrections, linked faces surfaced, material time split; tests `LineupCorrectionTest`, `LineupLinkedFacesTest`, `LineupMaterialSplitTest`".

- [ ] **Step 2: Full suite and static checks.**

```bash
php artisan test --compact
vendor/bin/pint --test
vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: everything passes, including `ProhibitedReferencesTest`. Pint reports no changes and PHPStan reports no errors.

- [ ] **Step 3: Keyboard walkthrough on the local app only.** Start it with the project's run configuration or `composer run dev`, against the local pilot database, with no production URL. As the operator, on an open shift:
  1. Place a truck with "Changes recorded at" 10:30, then Tab to its Since time and press Enter. Focus should be in Time.
  2. Type 10:00, press Tab, type a reason, press Enter. The status notice should read the correction sentence.
  3. Open the panel again and press Escape. The panel should close.
  4. Edit a working face's material and press Enter. Tab to "It changed at …" and press Enter.
  5. Put the same loader on two faces at C1 / B1, and check the face headers and the fleet board.

  Record any focus loss as a fix before continuing.

- [ ] **Step 4: Run the Impeccable detector** on the rendered local Line-up page (the design-language memory check). Fix findings that come from this work. Detector artifacts already recorded under I-25 are not new findings.

- [ ] **Step 5: Commit and push the branch. No PR, no merge, no deploy.**

```bash
git add docs/dot-central/13-shift-lineup-design.md docs/dot-central/10-outstanding-issues.md docs/dot-central/05-feature-matrix.md
git commit -m "docs: I-29 built (design 15); 13, 10 and 05 updated"
git push origin feat/lineup-corrections
```

---

## Self-review against the design

| Design section | Covered by |
|---|---|
| §0 D-1, D-2: correction as a Central annotation, by operators and supervisors with a reason | Tasks 4, 5 (`lineup` ability via `editable()` and `run()`; reason refused when blank) |
| §0 D-3: current shifts only | Task 4 (`guardWritable` SH-04; refusal test on a CLOSED shift) |
| §0 D-4: linked faces as an indicator, pooled checks, link-break refusal | Task 7 |
| §0 D-5: time split only, no quantities | Tasks 8, 9 (no quantity field anywhere; Global Constraints) |
| §0 D-6: breakdowns at shift change not built | Nothing builds it; Task 10 records it in 10 and 13 |
| §0 D-7: time-rule fixes | Task 3 (overlap, move before arrival, DST) |
| §0 D-8: Memory mirroring and the mapper fix | Tasks 1, 6, 9 |
| §3.1 sentences, aria names, keyboard path, read-only | Task 5 (tests assert the exact title, help line, aria-labels and notice; manager test); walked by hand in Task 10 Step 3 |
| §3.3 refusal table | Task 4 `test_impossible_corrections_are_refused_with_the_reason`, the stale test, and Task 3 DST |
| §3.4 what does and does not change | Task 4, test 1 (derived summaries equal before and after, operator rows unchanged, `Http::assertNothingSent()`) |
| §3.5 audit and Memory | Task 4 (override row with both edges), Task 6 (action record, equipment subject) |
| §3.6 handover, fleet board, report | Task 6 |
| §3.7 data model | Task 2 |
| §3.8 edge cases | Night shift (Task 4), DST (Task 3), stale and CAS (Task 4), closed (Task 4), Dot.Mines unassignment (Task 4 test 1), linked-loader overlap (Task 3), standby (Task 4) |
| §4 linked faces: surfaces, checks, refusal, edges | Task 7 tests 1–4; the handover snapshot in Task 9's handover test |
| §5 split: decision, correct-in-place audit, split mechanics, refusals, stale, page, handover, Memory | Tasks 8, 9 |
| §6 shared fixes | Task 3 |
| §7 new codes LU-14 / LU-15 as refusals only | Tasks 4, 8; not added to `RuleEngine::CODES` |
| §8 migration on SQLite and Postgres | Task 2 Step 7 |
| §9 found while reading | Task 1 (line-up record). The same union defect in `exception()`, `downtime()` and `assignment()` is out of scope and reported to the owner, not fixed here. |
| §10 documents | Task 10 Step 1 |

**Type and name consistency:** `correctTime(LineupAllocation, string $edge, string $hhmm, string $reason, User, ?string $expected)` is used the same way in Tasks 4, 5, 6 and 9. `LineupCorrection::ARRIVAL` and `LineupCorrection::DEPARTURE` are used everywhere; the Blade uses the literal strings `'ARRIVAL'` and `'DEPARTURE'`, which equal the constants. The `editFace` parameter order is `(face, attrs, user, move, hhmm, materialCorrection)`, and every new caller uses named arguments. `linkedFaces`, `splitFace`, `splitOrigin` and `describeCorrection` keep the same names in every task. The `hm()` helper is introduced in Task 3 before its first use in Task 4.

**Placeholders:** none. Every step names its files, code, commands and expected result.

**Not verified while writing this plan:**
- The migration has not yet run on Postgres (Task 2 Step 7 does it).
- The production room's time zone value: production was not contacted, and the DST rule covers any zone.
- The Impeccable detector and the keyboard walkthrough (Task 10).
- Operator access to `reports.index`, assumed from the existing report tests; Task 6 Step 7 runs `OverviewAlertsReportsAdminTest`.
