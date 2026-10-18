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
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * I-34: with the unique index on lineup_faces (shift_id, seq), a number that is taken is
 * rejected by the database. The numbering lock should make that impossible; if it ever
 * happens addFace and splitFace try once more with a fresh number and then refuse in plain words.
 * The clash is forced here by giving the new row a number that is already taken.
 */
class LineupFaceNumberClashTest extends TestCase
{
    use RefreshDatabase;

    private Shift $shift;

    private User $user;

    private LineupService $service;

    private LineupFace $face;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        Http::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($room);
        $this->user = User::factory()->create();
        $owner->currentTeam->users()->attach($this->user, ['role' => 'operator']);
        $this->user->switchTeam($owner->currentTeam);
        $room->users()->attach($this->user);
        $type = ShiftType::where('control_room_id', $room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $loader = Equipment::factory()->for($room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $truck = Equipment::factory()->for($room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        $this->service = app(LineupService::class);
        $this->face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$loader->id]], $this->user, '07:00');
        $this->service->place($this->shift, $this->face, $truck, $this->user, '07:30');
        $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C2', 'blocks' => ['B5']], $this->user, '07:00');
    }

    /** Makes the next $times inserts of a face reach the database with a number that is already taken (a writer that skipped the lock). */
    private function clash(int $times): object
    {
        $state = (object) ['attempts' => 0, 'forced' => 0];
        LineupFace::creating(function (LineupFace $face) use ($state, $times): void {
            $state->attempts++;
            if ($state->forced < $times) {
                $state->forced++;
                $face->seq = 1;
            }
        });

        return $state;
    }

    /** @return list<int> */
    private function numbers(): array
    {
        return DB::table('lineup_faces')->where('shift_id', $this->shift->id)->orderBy('id')->pluck('seq')->map(fn ($s) => (int) $s)->all();
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

    public function test_add_face_retries_once_with_a_fresh_number_when_the_index_rejects_its_number(): void
    {
        Log::spy();
        $state = $this->clash(1);

        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste', 'cut' => 'C3', 'blocks' => ['B7']], $this->user, '08:00');

        $this->assertSame(2, $state->attempts, 'one failed insert, one retry');
        $this->assertSame(3, $face->seq);
        $this->assertSame([1, 2, 3], $this->numbers());
        Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'face number'))->once();
    }

    public function test_add_face_refuses_in_plain_words_when_the_retry_clashes_too_and_writes_nothing(): void
    {
        $state = $this->clash(2);
        $audits = AuditEntry::count();

        $message = $this->refusal(fn () => $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00'));

        $this->assertStringStartsWith('LU-15 ', $message);
        $this->assertStringContainsString('numbered', $message);
        $this->assertStringContainsString('try again', $message);
        $this->assertStringNotContainsString('SQLSTATE', $message);
        $this->assertSame(2, $state->attempts, 'exactly one retry, not a loop');
        $this->assertSame([1, 2], $this->numbers());
        $this->assertSame($audits, AuditEntry::count());
    }

    public function test_split_face_retries_once_with_a_fresh_number_and_the_split_happens_exactly_once(): void
    {
        $state = $this->clash(1);

        $new = $this->service->splitFace($this->face, 'Interburden', $this->user, '09:00');

        $this->assertSame(2, $state->attempts);
        $this->assertSame(3, $new->seq);
        $this->assertSame([1, 2, 3], $this->numbers());
        $this->assertSame('CLOSED', $this->face->fresh()->status);
        $this->assertSame($this->face->id, $new->split_from_face_id);
        $this->assertSame(1, AuditEntry::query()->where('entity_type', LineupFace::class)->where('entity_id', $this->face->id)->where('action', 'updated')->where('reason', 'like', 'Material changed%')->count(), 'the close of the old face is recorded once, not once per attempt');
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $new->id)->whereNull('to_at')->count(), 'the machines carry on');
        $this->assertSame(0, LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $this->face->id)->whereNull('to_at')->count());
    }

    public function test_split_face_refuses_when_the_retry_clashes_too_and_leaves_the_face_exactly_as_it_was(): void
    {
        $before = DB::table('lineup_allocations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $face = DB::table('lineup_faces')->where('id', $this->face->id)->first();
        $state = $this->clash(2);

        $message = $this->refusal(fn () => $this->service->splitFace($this->face, 'Interburden', $this->user, '09:00'));

        $this->assertStringStartsWith('LU-15 ', $message);
        $this->assertStringContainsString('try again', $message);
        $this->assertSame(2, $state->attempts);
        $this->assertSame([1, 2], $this->numbers());
        $this->assertEquals($face, DB::table('lineup_faces')->where('id', $this->face->id)->first());
        $this->assertSame($before, DB::table('lineup_allocations')->orderBy('id')->get()->map(fn ($r) => (array) $r)->all());
    }

    public function test_the_service_still_works_after_a_refusal(): void
    {
        $this->clash(2);
        $this->refusal(fn () => $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00'));

        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');

        $this->assertSame(3, $face->seq);
    }

    public function test_other_errors_are_not_swallowed_or_retried(): void
    {
        $attempts = 0;
        LineupFace::creating(function () use (&$attempts): void {
            $attempts++;
            throw new RuntimeException('disk full');
        });

        try {
            $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');
            $this->fail('Expected the error to pass through.');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(1, $attempts);
    }

    public function test_a_unique_violation_that_is_not_about_the_number_is_not_retried(): void
    {
        $attempts = 0;
        LineupFace::creating(function (LineupFace $face) use (&$attempts): void {
            $attempts++;
            $face->id = $this->face->id;
        });

        $this->expectException(UniqueConstraintViolationException::class);
        try {
            $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');
        } finally {
            $this->assertSame(1, $attempts);
        }
    }

    /** A unique violation as the PDO driver words it. */
    private function violation(string $driverMessage): UniqueConstraintViolationException
    {
        $pdo = new PDOException('driver');
        $pdo->errorInfo = ['23505', 7, $driverMessage];

        return new UniqueConstraintViolationException('pgsql', 'insert into "lineup_faces" ...', [], $pdo);
    }

    public function test_postgres_wording_with_the_constraint_name_is_a_number_clash_and_is_retried(): void
    {
        $attempts = 0;
        LineupFace::creating(function () use (&$attempts): void {
            if (++$attempts === 1) {
                throw $this->violation('ERROR: duplicate key value violates unique constraint "lineup_faces_shift_id_seq_unique"');
            }
        });

        $face = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');

        $this->assertSame(2, $attempts);
        $this->assertSame(3, $face->seq);
    }

    public function test_postgres_wording_of_another_constraint_is_not_a_number_clash(): void
    {
        $attempts = 0;
        LineupFace::creating(function () use (&$attempts): void {
            $attempts++;

            throw $this->violation('ERROR: duplicate key value violates unique constraint "lineup_faces_pkey"');
        });

        try {
            $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');
            $this->fail('Expected the violation to pass through.');
        } catch (UniqueConstraintViolationException) {
            $this->assertSame(1, $attempts);
        }
    }

    public function test_the_numbers_are_still_consecutive_in_the_normal_case(): void
    {
        $a = $this->service->addFace($this->shift, ['location' => 'default:Loading area 3', 'kind' => 'LOADING', 'material' => 'Waste'], $this->user, '08:00');
        $b = $this->service->splitFace($a, 'Rock', $this->user, '09:00');

        $this->assertSame([3, 4], [$a->seq, $b->seq]);
    }
}
