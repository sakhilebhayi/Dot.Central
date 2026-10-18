<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupFace;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * I-34 (15-lineup-corrections-design.md §5.9): lineup_faces (shift_id, seq) becomes unique.
 * Production may hold duplicate numbers from before the numbering lock, so the migration
 * renumbers them first (lowest id keeps its number), records each change, and only then
 * creates the index. It must be safe on any data, a no-op without duplicates, and idempotent.
 */
class LineupFaceSeqUniqueMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'database/migrations/2026_10_18_000023_add_unique_shift_seq_to_lineup_faces.php';

    private const INDEX = 'lineup_faces_shift_id_seq_unique';

    private ControlRoom $room;

    private User $owner;

    /** @var array<string, Shift> */
    private array $shifts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($this->owner->currentTeam)->create();
        app(RoomProvisioner::class)->provision($this->room);
    }

    // ---- fixtures ---------------------------------------------------------------------------------------------

    private function shift(string $key, string $date): Shift
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();

        return $this->shifts[$key] = Shift::factory()->for($this->room)->for($type, 'shiftType')
            ->create(['shift_date' => $date, 'starts_at' => $date.' 06:00:00', 'ends_at' => $date.' 18:00:00']);
    }

    /** One face row with exactly the given number, as the pre-lock code could have written it. */
    private function face(Shift $shift, int $seq, string $material, string $status = 'ACTIVE'): int
    {
        return DB::table('lineup_faces')->insertGetId([
            'control_room_id' => $this->room->id, 'shift_id' => $shift->id, 'seq' => $seq, 'kind' => 'LOADING',
            'material' => $material, 'cut' => 'C1', 'blocks' => json_encode(['B1']), 'status' => $status,
            'location_name' => 'Pit '.$seq, 'active_from' => '2026-10-15 06:00:00',
            'created_at' => '2026-10-15 05:00:00', 'updated_at' => '2026-10-15 05:30:00',
        ]);
    }

    /** The state production could be in: the index is not there yet. */
    private function previousState(): void
    {
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION, '--force' => true]);
        $this->assertFalse(Schema::hasIndex('lineup_faces', self::INDEX));
    }

    /**
     * Shift A: numbers by id 1,2,2,1,3,2 (groups of 2 and 3, interleaved); shift B: 1,1 (a pair); shift C: 1,2,3 (clean).
     *
     * @return array{a: list<int>, b: list<int>, c: list<int>}
     */
    private function duplicatedFixture(): array
    {
        $a = $this->shift('a', '2026-10-15');
        $b = $this->shift('b', '2026-10-16');
        $c = $this->shift('c', '2026-10-17');
        $ids = ['a' => [], 'b' => [], 'c' => []];
        foreach ([1, 2, 2, 1, 3, 2] as $i => $seq) {
            $ids['a'][] = $this->face($a, $seq, 'A'.($i + 1), $i === 1 ? 'CLOSED' : 'ACTIVE');
        }
        foreach ([1, 1] as $i => $seq) {
            $ids['b'][] = $this->face($b, $seq, 'B'.($i + 1));
        }
        foreach ([1, 2, 3] as $i => $seq) {
            $ids['c'][] = $this->face($c, $seq, 'C'.($i + 1));
        }

        return $ids;
    }

    /** @return array<int, int> id => seq */
    private function seqs(Shift $shift): array
    {
        return DB::table('lineup_faces')->where('shift_id', $shift->id)->orderBy('id')->pluck('seq', 'id')->map(fn ($s) => (int) $s)->all();
    }

    /** @return Collection<int, AuditEntry> */
    private function renumberAudits(): Collection
    {
        return AuditEntry::query()->where('entity_type', LineupFace::class)->where('action', 'updated')->where('reason', 'like', '%I-34%')->orderBy('entity_id')->get();
    }

    // ---- the migration ----------------------------------------------------------------------------------------

    public function test_duplicates_are_renumbered_lowest_id_keeps_its_number_and_the_rest_go_above_the_maximum_in_id_order(): void
    {
        $this->previousState();
        $ids = $this->duplicatedFixture();

        Artisan::call('migrate', ['--force' => true]);

        // Shift A: ids 1,2,3,4,5,6 had 1,2,2,1,3,2. Maximum 3, so the later duplicates take 4,5,6 in id order.
        $this->assertSame([$ids['a'][0] => 1, $ids['a'][1] => 2, $ids['a'][2] => 4, $ids['a'][3] => 5, $ids['a'][4] => 3, $ids['a'][5] => 6], $this->seqs($this->shifts['a']));
        // Shift B: the pair; the second takes 2.
        $this->assertSame([$ids['b'][0] => 1, $ids['b'][1] => 2], $this->seqs($this->shifts['b']));
        // Shift C had no duplicates and is untouched.
        $this->assertSame([$ids['c'][0] => 1, $ids['c'][1] => 2, $ids['c'][2] => 3], $this->seqs($this->shifts['c']));
        foreach ($this->shifts as $shift) {
            $numbers = array_values($this->seqs($shift));
            $this->assertSame($numbers, array_values(array_unique($numbers)));
        }
    }

    public function test_only_the_number_of_a_renumbered_face_changes_and_no_other_row_or_column_is_touched(): void
    {
        $this->previousState();
        $ids = $this->duplicatedFixture();
        $before = DB::table('lineup_faces')->orderBy('id')->get()->keyBy('id')->map(fn ($r) => (array) $r)->all();
        $other = [
            'allocations' => DB::table('lineup_allocations')->count(),
            'shifts' => json_encode(DB::table('shifts')->orderBy('id')->get()),
        ];

        Artisan::call('migrate', ['--force' => true]);

        $after = DB::table('lineup_faces')->orderBy('id')->get()->keyBy('id')->map(fn ($r) => (array) $r)->all();
        $renumbered = [$ids['a'][2], $ids['a'][3], $ids['a'][5], $ids['b'][1]];
        $this->assertSame(array_keys($before), array_keys($after));
        foreach ($before as $id => $row) {
            $expected = $row;
            if (in_array($id, $renumbered, true)) {
                $this->assertNotSame($row['seq'], $after[$id]['seq'], "face $id should be renumbered");
                unset($expected['seq'], $after[$id]['seq']);
            }
            $this->assertEquals($expected, $after[$id], "face $id: nothing but the number may change");
        }
        $this->assertSame($other['allocations'], DB::table('lineup_allocations')->count());
        $this->assertSame($other['shifts'], json_encode(DB::table('shifts')->orderBy('id')->get()));
    }

    public function test_every_change_is_written_to_the_audit_trail_and_the_log(): void
    {
        $this->previousState();
        $ids = $this->duplicatedFixture();
        $auditsBefore = AuditEntry::count();
        Log::spy();

        Artisan::call('migrate', ['--force' => true]);

        $audits = $this->renumberAudits();
        $this->assertCount(4, $audits);
        $this->assertSame(4, AuditEntry::count() - $auditsBefore, 'one row per change and nothing else');
        $expected = [$ids['a'][2] => [2, 4], $ids['a'][3] => [1, 5], $ids['a'][5] => [2, 6], $ids['b'][1] => [1, 2]];
        foreach ($audits as $entry) {
            [$old, $new] = $expected[$entry->entity_id];
            $this->assertSame(['seq' => ['old' => $old, 'new' => $new]], $entry->changes);
            $this->assertSame($this->room->id, $entry->control_room_id);
            $this->assertSame($this->room->team_id, $entry->team_id);
            $this->assertSame($entry->shift_id, (int) DB::table('lineup_faces')->where('id', $entry->entity_id)->value('shift_id'));
            $this->assertTrue($entry->automatic);
            $this->assertNull($entry->user_id);
            $this->assertStringContainsString("Face $old", (string) $entry->reason);
            $this->assertStringContainsString("Face $new", (string) $entry->reason);
        }
        foreach ($expected as $faceId => [$old, $new]) {
            Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'I-34')
                && ($context['face_id'] ?? null) === $faceId && ($context['old_seq'] ?? null) === $old && ($context['new_seq'] ?? null) === $new
                && ($context['shift_id'] ?? null) === (int) DB::table('lineup_faces')->where('id', $faceId)->value('shift_id'))->once();
        }
    }

    public function test_a_renumbered_face_keeps_its_id_so_everything_that_points_at_it_still_does_and_its_label_shows_the_new_number(): void
    {
        $this->previousState();
        $ids = $this->duplicatedFixture();
        $unit = Equipment::factory()->for($this->room)->create(['name' => 'EX-01']);
        $moved = $ids['a'][3];
        $stint = LineupAllocation::create(['control_room_id' => $this->room->id, 'shift_id' => $this->shifts['a']->id, 'lineup_face_id' => $moved, 'equipment_id' => $unit->id, 'role' => 'LOADER', 'from_at' => '2026-10-15 07:00:00', 'ended_reason' => 'Moved to Face 1 — Pit 1']);
        $snapshot = ['faces' => [['label' => 'Face 1 — Pit 1 — A4 · C1 / B1']]];
        $handover = ShiftHandover::create(['shift_id' => $this->shifts['a']->id, 'snapshot' => $snapshot, 'issued_at' => '2026-10-15 17:50:00', 'issued_by' => $this->owner->id]);
        $this->assertSame('Face 1 — Pit 1 — A4 · C1 / B1', LineupFace::withoutGlobalScopes()->find($moved)->label());

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($moved, $stint->fresh()->lineup_face_id);
        $this->assertSame('Face 5 — Pit 1 — A4 · C1 / B1', LineupFace::withoutGlobalScopes()->find($moved)->label());
        // History is text written at the time: it keeps the number the face had then.
        $this->assertSame('Moved to Face 1 — Pit 1', $stint->fresh()->ended_reason);
        $this->assertSame($snapshot, $handover->fresh()->snapshot);
    }

    public function test_the_index_exists_and_rejects_a_duplicate_number_afterwards(): void
    {
        $this->previousState();
        $this->duplicatedFixture();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX, 'unique'));
        $this->assertTrue(Schema::hasIndex('lineup_faces', ['shift_id', 'seq'], 'unique'));
        $this->expectException(QueryException::class);
        $this->face($this->shifts['c'], 2, 'duplicate');
    }

    public function test_the_same_number_in_another_shift_is_allowed(): void
    {
        $this->previousState();
        $this->duplicatedFixture();
        Artisan::call('migrate', ['--force' => true]);

        $this->face($this->shifts['c'], 4, 'new');
        $other = $this->shift('d', '2026-10-18');
        $this->face($other, 1, 'first of another shift');

        $this->assertSame(1, DB::table('lineup_faces')->where('shift_id', $other->id)->count());
    }

    public function test_without_duplicates_it_changes_nothing_writes_nothing_and_logs_nothing(): void
    {
        $this->previousState();
        $c = $this->shift('c', '2026-10-17');
        foreach ([1, 2, 3] as $seq) {
            $this->face($c, $seq, 'C'.$seq);
        }
        $before = DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $auditsBefore = AuditEntry::count();
        Log::spy();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertSame($before, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($auditsBefore, AuditEntry::count());
        Log::shouldNotHaveReceived('info');
        $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX));
    }

    public function test_an_empty_table_migrates(): void
    {
        $this->previousState();

        Artisan::call('migrate', ['--force' => true]);

        $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX));
    }

    public function test_running_it_again_is_idempotent(): void
    {
        $this->previousState();
        $this->duplicatedFixture();
        Artisan::call('migrate', ['--force' => true]);
        $afterFirst = DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = AuditEntry::count();

        // Down drops the index only; up again finds nothing to renumber and the index is rebuilt.
        $this->previousState();
        $this->assertSame($afterFirst, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        Artisan::call('migrate', ['--force' => true]);
        $this->assertSame($afterFirst, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($audits, AuditEntry::count());
        $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX));

        // The migration body itself, run directly on a database that already has the index, does nothing and does not fail.
        $migration = require base_path(self::MIGRATION);
        $migration->up();
        $this->assertSame($afterFirst, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($audits, AuditEntry::count());
    }

    public function test_down_drops_the_index_and_nothing_else(): void
    {
        $this->previousState();
        $this->duplicatedFixture();
        Artisan::call('migrate', ['--force' => true]);
        $rows = DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $columns = Schema::getColumnListing('lineup_faces');

        $this->previousState();

        $this->assertSame($rows, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($columns, Schema::getColumnListing('lineup_faces'));
        $this->assertTrue(Schema::hasIndex('lineup_faces', ['shift_id', 'status']), 'the older index stays');
        // Without the index a duplicate can be written again (that is the state before this migration).
        $other = $this->shift('d', '2026-10-18');
        $this->face($other, 1, 'x');
        $this->face($other, 1, 'y');
        $this->assertSame(2, DB::table('lineup_faces')->where('shift_id', $other->id)->count());
    }

    /** Makes the index step fail: another table already owns the index name (index names are schema-wide). */
    private function blockTheIndexName(): void
    {
        DB::statement('create index '.self::INDEX.' on lineup_allocations (id)');
    }

    public function test_a_failure_while_creating_the_index_rolls_the_renumbering_back_and_logs_nothing_as_done(): void
    {
        $this->previousState();
        $this->duplicatedFixture();
        $before = DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $audits = AuditEntry::count();
        $this->blockTheIndexName();
        Log::spy();

        try {
            Artisan::call('migrate', ['--force' => true]);
            $this->fail('the migration should have failed');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        $this->assertSame($audits, AuditEntry::count());
        Log::shouldNotHaveReceived('info');
    }

    public function test_called_directly_a_failure_still_rolls_the_renumbering_back(): void
    {
        $this->previousState();
        $this->duplicatedFixture();
        $before = DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $this->blockTheIndexName();
        Log::spy();
        $migration = require base_path(self::MIGRATION);

        try {
            $migration->up();
            $this->fail('the migration should have failed');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, DB::table('lineup_faces')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
        Log::shouldNotHaveReceived('info');
    }

    /** SQLite: the audit insert for one face is made to fail (a trigger), the others succeed. */
    private function failAuditInsertFor(int $faceId): void
    {
        DB::unprepared("create trigger i34_forced_failure before insert on audit_log when new.entity_id = {$faceId} and new.action = 'updated' begin select raise(abort, 'forced audit failure'); end");
    }

    public function test_a_failing_audit_insert_never_fails_the_migration_the_numbers_and_the_index_still_land(): void
    {
        $this->onlyOnSqlite();
        $this->previousState();
        $ids = $this->duplicatedFixture();
        $failed = $ids['a'][3];
        $this->failAuditInsertFor($failed);
        Log::spy();

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertSame([$ids['a'][0] => 1, $ids['a'][1] => 2, $ids['a'][2] => 4, $ids['a'][3] => 5, $ids['a'][4] => 3, $ids['a'][5] => 6], $this->seqs($this->shifts['a']));
        $this->assertSame([$ids['b'][0] => 1, $ids['b'][1] => 2], $this->seqs($this->shifts['b']));
        $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX, 'unique'));
        $audited = $this->renumberAudits()->pluck('entity_id')->all();
        $this->assertEqualsCanonicalizing([$ids['a'][2], $ids['a'][5], $ids['b'][1]], $audited, 'every other change is audited; the failed one is not');
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []) => str_contains($message, 'I-34')
            && $context['face_id'] === $failed && $context['shift_id'] === $this->shifts['a']->id && $context['old_seq'] === 1 && $context['new_seq'] === 5)->once();
        Log::shouldHaveReceived('warning')->times(1);
        // the change is still logged as done: the log is the other record
        Log::shouldHaveReceived('info')->withArgs(fn (string $message, array $context = []) => ($context['face_id'] ?? null) === $failed)->once();
    }

    public function test_a_failing_audit_insert_does_not_poison_the_transaction_for_the_next_one(): void
    {
        $this->onlyOnSqlite();
        $this->previousState();
        $ids = $this->duplicatedFixture();
        $this->failAuditInsertFor($ids['a'][2]);

        $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));

        $this->assertEqualsCanonicalizing([$ids['a'][3], $ids['a'][5], $ids['b'][1]], $this->renumberAudits()->pluck('entity_id')->all(), 'the audit inserts after the failed one still work');
    }

    private function onlyOnSqlite(): void
    {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Uses SQLite triggers and a SQLite file.');
        }
    }

    // ---- the real artisan path on a SQLite file -----------------------------------------------------------------

    public function test_on_a_sqlite_file_the_real_artisan_migrate_renumbers_creates_the_index_and_is_repeatable(): void
    {
        $this->onlyOnSqlite();
        $file = tempnam(sys_get_temp_dir(), 'i34-').'.sqlite';
        touch($file);
        $this->useScratchSqlite($file);

        try {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->room = ControlRoom::factory()->for(User::factory()->withPersonalTeam()->create()->currentTeam)->create();
            app(RoomProvisioner::class)->provision($this->room);
            $this->previousState();
            $ids = $this->duplicatedFixture();
            $this->assertFileExists($file);

            // production's own command, in its own process, against the file
            $first = $this->runArtisan('migrate', $file);
            $this->assertSame(0, $first->getExitCode(), $first->getOutput().$first->getErrorOutput());
            $this->assertStringContainsString('2026_10_18_000023_add_unique_shift_seq_to_lineup_faces', $first->getOutput());
            $this->assertSame(4, substr_count($first->getErrorOutput(), 'I-34'), $first->getErrorOutput());

            DB::purge('i34_file');
            $this->assertSame([$ids['a'][0] => 1, $ids['a'][1] => 2, $ids['a'][2] => 4, $ids['a'][3] => 5, $ids['a'][4] => 3, $ids['a'][5] => 6], $this->seqs($this->shifts['a']));
            $this->assertSame([$ids['b'][0] => 1, $ids['b'][1] => 2], $this->seqs($this->shifts['b']));
            $this->assertSame([$ids['c'][0] => 1, $ids['c'][1] => 2, $ids['c'][2] => 3], $this->seqs($this->shifts['c']));
            $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX, 'unique'));
            $this->assertCount(4, $this->renumberAudits());
            $this->assertSame('ok', DB::selectOne('pragma integrity_check')->integrity_check);

            // a second deploy: nothing pending
            $second = $this->runArtisan('migrate', $file);
            $this->assertSame(0, $second->getExitCode());
            $this->assertStringContainsString('Nothing to migrate', $second->getOutput());

            // up / down / up
            $down = $this->runArtisan('migrate:rollback --step=1', $file);
            $this->assertSame(0, $down->getExitCode(), $down->getOutput().$down->getErrorOutput());
            DB::purge('i34_file');
            $this->assertFalse(Schema::hasIndex('lineup_faces', self::INDEX));
            $this->assertSame([$ids['a'][0] => 1, $ids['a'][1] => 2, $ids['a'][2] => 4, $ids['a'][3] => 5, $ids['a'][4] => 3, $ids['a'][5] => 6], $this->seqs($this->shifts['a']));
            $up = $this->runArtisan('migrate', $file);
            $this->assertSame(0, $up->getExitCode());
            $this->assertSame(0, substr_count($up->getErrorOutput(), 'I-34'), 'nothing left to renumber');
            DB::purge('i34_file');
            $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX, 'unique'));
            $this->assertCount(4, $this->renumberAudits());
        } finally {
            config(['database.default' => 'sqlite']);
            DB::purge('i34_file');
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($file.$suffix);
            }
        }
    }

    public function test_on_a_sqlite_file_a_failing_audit_insert_does_not_fail_the_real_artisan_migrate(): void
    {
        $this->onlyOnSqlite();
        $file = tempnam(sys_get_temp_dir(), 'i34-').'.sqlite';
        touch($file);
        $this->useScratchSqlite($file);

        try {
            $this->assertSame(0, Artisan::call('migrate', ['--force' => true]));
            $this->room = ControlRoom::factory()->for(User::factory()->withPersonalTeam()->create()->currentTeam)->create();
            app(RoomProvisioner::class)->provision($this->room);
            $this->previousState();
            $ids = $this->duplicatedFixture();
            $this->failAuditInsertFor($ids['a'][3]);

            $run = $this->runArtisan('migrate', $file);

            $this->assertSame(0, $run->getExitCode(), $run->getOutput().$run->getErrorOutput());
            $this->assertStringContainsString('DONE', $run->getOutput());
            $this->assertSame(1, substr_count($run->getErrorOutput(), 'WARNING'), 'one warning for the one failed audit insert');
            $this->assertSame(4, substr_count($run->getErrorOutput(), 'INFO: I-34'), 'all four changes are logged as done, the failed audit one too: '.$run->getErrorOutput());
            DB::purge('i34_file');
            $this->assertSame([$ids['a'][0] => 1, $ids['a'][1] => 2, $ids['a'][2] => 4, $ids['a'][3] => 5, $ids['a'][4] => 3, $ids['a'][5] => 6], $this->seqs($this->shifts['a']));
            $this->assertTrue(Schema::hasIndex('lineup_faces', self::INDEX, 'unique'));
            $this->assertCount(3, $this->renumberAudits());
        } finally {
            config(['database.default' => 'sqlite']);
            DB::purge('i34_file');
            foreach (['', '-wal', '-shm'] as $suffix) {
                @unlink($file.$suffix);
            }
        }
    }

    private function useScratchSqlite(string $file): void
    {
        config([
            'database.connections.i34_file' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => true],
            'database.default' => 'i34_file',
        ]);
        DB::purge('i34_file');
    }

    private function runArtisan(string $command, string $file): Process
    {
        $process = new Process(array_merge([PHP_BINARY, base_path('artisan')], explode(' ', $command), ['--force', '--no-interaction']), base_path(), [
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $file, 'DB_URL' => '', 'APP_ENV' => 'testing', 'LOG_CHANNEL' => 'stderr',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setTimeout(120);
        $process->run();

        return $process;
    }
}
