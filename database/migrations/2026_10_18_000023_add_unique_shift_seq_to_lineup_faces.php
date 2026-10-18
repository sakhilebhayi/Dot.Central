<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * I-34 (15-lineup-corrections-design.md §5.9): one number per face per shift.
 *
 * The numbering lock (LineupService::lockShiftFaces) stops new duplicates, but a
 * production database may still hold two faces with one number from before the
 * lock existed. A plain unique index would fail on them and a failed migration
 * puts the site in maintenance, so this migration first renumbers any duplicate
 * and only then creates the index, all in one transaction:
 *
 *  - within a shift, the lowest id keeps the number it has;
 *  - each later face with a number already used takes the next free number above
 *    the shift's highest number, in id order (deterministic);
 *  - nothing but lineup_faces.seq changes, and a face keeps its id, so stints,
 *    corrections and split links are unaffected;
 *  - every change is written to the audit trail (one row, automatic) and to the
 *    log (face id, shift id, old and new number);
 *  - with no duplicates it writes nothing, and running it again finds nothing to do.
 *
 * The face label ("Face 2 — ...") is derived from the number, so a renumbered face
 * shows its new number from now on. Text written earlier (handover snapshots, the
 * "Moved to Face 2" reason of a stint) is history and keeps the old one.
 *
 * Runs in the migrator's own transaction on SQLite and Postgres; the transaction
 * is also opened here so it holds when called directly.
 */
return new class extends Migration
{
    public $withinTransaction = true;

    private const INDEX = 'lineup_faces_shift_id_seq_unique';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->holdTheWriteLock();
            $this->renumberDuplicates();

            if (! Schema::hasIndex('lineup_faces', self::INDEX)) {
                Schema::table('lineup_faces', function (Blueprint $table): void {
                    $table->unique(['shift_id', 'seq'], self::INDEX);
                });
            }
        });
    }

    public function down(): void
    {
        if (Schema::hasIndex('lineup_faces', self::INDEX)) {
            Schema::table('lineup_faces', function (Blueprint $table): void {
                $table->dropUnique(self::INDEX);
            });
        }
    }

    /**
     * No face may be added between finding the duplicates and building the index.
     * SQLite: a write (that changes no row) takes the single write lock before the
     * first read. Postgres: readers carry on, writers wait until the commit.
     */
    private function holdTheWriteLock(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::table('lineup_faces')->where('id', 0)->update(['seq' => DB::raw('seq')]);
        } elseif ($driver === 'pgsql') {
            DB::statement('LOCK TABLE lineup_faces IN SHARE ROW EXCLUSIVE MODE');
        }
    }

    private function renumberDuplicates(): void
    {
        $shiftIds = DB::table('lineup_faces')
            ->select('shift_id')
            ->groupBy('shift_id', 'seq')
            ->havingRaw('count(*) > 1')
            ->pluck('shift_id')
            ->unique()
            ->sort()
            ->values();

        foreach ($shiftIds as $shiftId) {
            $faces = DB::table('lineup_faces')->where('shift_id', $shiftId)->orderBy('id')->get(['id', 'seq', 'control_room_id']);
            $next = (int) $faces->max('seq') + 1;
            $taken = [];

            foreach ($faces as $face) {
                $old = (int) $face->seq;

                if (! isset($taken[$old])) {
                    $taken[$old] = true;

                    continue;
                }

                $new = $next++;
                DB::table('lineup_faces')->where('id', $face->id)->update(['seq' => $new]);
                $this->record((int) $face->id, (int) $shiftId, (int) $face->control_room_id, $old, $new);
            }
        }
    }

    private function record(int $faceId, int $shiftId, int $controlRoomId, int $old, int $new): void
    {
        Log::info('I-34 lineup face renumbered: the number was used twice in the shift', [
            'face_id' => $faceId, 'shift_id' => $shiftId, 'old_seq' => $old, 'new_seq' => $new,
        ]);

        DB::table('audit_log')->insert([
            'occurred_at' => now(),
            'user_id' => null,
            'user_role' => null,
            'team_id' => DB::table('control_rooms')->where('id', $controlRoomId)->value('team_id'),
            'control_room_id' => $controlRoomId,
            'shift_id' => $shiftId,
            'entity_type' => 'App\\Models\\LineupFace',
            'entity_id' => $faceId,
            'action' => 'updated',
            'changes' => json_encode(['seq' => ['old' => $old, 'new' => $new]]),
            'reason' => "Face number made unique so the shift's numbers can be indexed (I-34): was Face {$old}, now Face {$new}. The face is the same face.",
            'automatic' => true,
            'approved_by' => null,
            'ip' => null,
            'created_at' => now(),
        ]);
    }
};
