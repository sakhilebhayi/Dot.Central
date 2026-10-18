<?php

namespace Tests\Feature;

use App\Livewire\FleetBoard;
use App\Livewire\Lineup;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\LineupFace;
use App\Models\LineupStandby;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Services\Shifts\ShiftService;
use App\Support\RoleClass;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_a_linked_pair_with_no_trucks_still_raises_lu06_on_both_faces_and_a_lone_face_keeps_its_texts(): void
    {
        $lu06 = $this->service->checks($this->shift)->where('code', 'LU-06')->values();
        $this->assertSame([$this->seam->id, $this->parting->id], $lu06->pluck('face_id')->all());
        $this->assertSame('Face 1 — Loading area 1 — Seam · C1 / B1 B2 has no trucks', $lu06[0]['title']);

        $this->room->settings()->set('trucks_per_loader', 1);
        $lone = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C2', 'blocks' => ['B9']], $this->user, '07:00');
        $loader2 = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->service->place($this->shift, $lone, $loader2, $this->user, '07:00');
        $this->service->place($this->shift, $lone, $this->truck1, $this->user, '07:00');
        $this->service->place($this->shift, $lone, $this->truck2, $this->user, '07:00');
        $lu07 = $this->service->checks($this->shift)->where('code', 'LU-07')->values();
        $this->assertSame('2 trucks on '.$lone->label().' for 1 loading unit(s)', $lu07[0]['title']);
        $this->assertSame($lone->id, $lu07[0]['face_id']);
    }

    public function test_a_loader_that_is_not_the_shared_one_does_not_link_faces(): void
    {
        $other = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $third = $this->service->addFace($this->shift, ['location' => 'default:Loading area 2', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$other->id]], $this->user, '07:00');

        $this->assertSame([], $this->service->linkedFaces($third)->pluck('id')->all(), 'same cut and block, but different loading units');
        $this->assertSame('', $this->service->linkedLabel($third));
        $this->assertSame('Face 2', $this->service->linkedLabel($this->seam));
    }

    public function test_placing_the_shared_loader_says_which_face_it_also_loads(): void
    {
        $this->actingAs($this->user);
        $loader2 = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('place', $loader2->id, false, $this->seam->id)->assertDontSee('also loads')
            ->call('place', $loader2->id, false, $this->parting->id)
            ->assertSee('EX-02 → Face 2 — Loading area 1 — Parting · C1 / B2 at 12:00 (also loads Face 1: same cut and block).');
    }

    public function test_the_page_offers_to_move_the_loading_unit_when_an_edit_would_break_the_link(): void
    {
        $this->actingAs($this->user);

        $page = Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('openFaceForm', $this->parting->id)
            ->set('faceForm.cut', 'C9')->set('faceForm.blocks', 'B7')
            ->call('saveFace')
            ->assertSee('LU-01 EX-01 also loads Face 1')->assertSee('Move the loading unit here');
        $this->assertSame('C1', $this->parting->fresh()->cut);

        $page->call('saveFace', true)->assertSee('Saved Face 2')->assertDontSee('Move the loading unit here');
        $this->assertSame('C9', $this->parting->fresh()->cut);
        $this->assertSame([$this->parting->id], LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->whereNull('to_at')->pluck('lineup_face_id')->all());
    }

    public function test_the_handover_snapshot_and_the_next_start_shift_carry_the_link(): void
    {
        Carbon::setTestNow('2026-10-15 18:30:00');
        Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->update(['last_heard_at' => '2026-10-15 18:25:00']);
        $closed = app(ShiftService::class)->close($this->shift, null, $this->user);

        $faces = $closed->fresh('handover')->handover->snapshot['lineup']['faces'];
        $this->assertSame(['Face 2'], $faces[0]['linked_with']);
        $this->assertSame(['Face 1'], $faces[1]['linked_with']);
        $this->actingAs($this->user)->get(route('control-rooms.shifts.start', $this->room))->assertOk()
            ->assertSee('Previous faces (2)')->assertSee('Seam · C1 / B1 B2 · same loading unit as Face 2');
    }
    // ---- a loader on linked faces moves as one (slice 2, gap A) -----------------------------------------------

    private function third(string $name = 'Loading area 3', string $cut = 'C2'): LineupFace
    {
        return $this->service->addFace($this->shift, ['location' => 'default:'.$name, 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => $cut, 'blocks' => ['B5']], $this->user, '07:00');
    }

    /** @return list<string> "<face seq>@<from>-<to>" in time order */
    private function history(Equipment $unit): array
    {
        return LineupAllocation::withoutGlobalScopes()->with('face')->where('equipment_id', $unit->id)->orderBy('from_at')->orderBy('lineup_face_id')->get()
            ->map(fn (LineupAllocation $a) => $a->face->seq.'@'.$a->from_at->format('H:i').'-'.($a->to_at?->format('H:i') ?? 'open'))->all();
    }

    private function stint(LineupFace $face, ?Equipment $unit = null): LineupAllocation
    {
        return LineupAllocation::withoutGlobalScopes()->where('lineup_face_id', $face->id)->where('equipment_id', ($unit ?? $this->loader)->id)->orderByDesc('id')->firstOrFail();
    }

    private function movedToThird(): LineupFace
    {
        $third = $this->third();
        $this->service->place($this->shift, $third, $this->loader, $this->user, '10:30', true);

        return $third;
    }

    public function test_a_loader_on_two_linked_faces_moves_to_a_third_face_as_one_move(): void
    {
        $this->movedToThird();

        $this->assertSame(['1@07:00-10:30', '2@07:00-10:30', '3@10:30-open'], $this->history($this->loader));
    }

    public function test_correcting_the_arrival_on_the_new_face_moves_both_departures_with_it(): void
    {
        $third = $this->movedToThird();

        $correction = $this->service->correctTime($this->stint($third), LineupCorrection::ARRIVAL, '10:00', 'The move was clicked late', $this->user);

        $this->assertSame(['1@07:00-10:00', '2@07:00-10:00', '3@10:00-open'], $this->history($this->loader));
        $this->assertSame(1, LineupCorrection::withoutGlobalScopes()->count());
        $this->assertSame([$this->stint($this->seam)->id, $this->stint($this->parting)->id], $correction->movedWithIds());
        $this->assertSame('EX-01 on Face 3 — Loading area 3 — Coal · C2 / B5 at 10:00 (was 10:30); off Face 1 — Loading area 1 — Seam · C1 / B1 B2 and Face 2 — Loading area 1 — Parting · C1 / B2 at the same time.', $this->service->describeCorrection($correction));
        $audit = AuditEntry::where('entity_type', LineupCorrection::class)->where('entity_id', $correction->id)->firstOrFail();
        $this->assertSame('override', $audit->action);
        $this->assertSame(['partner_to_at', 'also_to_at_'.$this->stint($this->parting)->id], array_values(array_filter(array_keys($audit->changes), fn ($k) => str_starts_with($k, 'partner_') || str_starts_with($k, 'also_'))));
    }

    public function test_correcting_either_departure_moves_the_other_departure_and_the_arrival_too(): void
    {
        $third = $this->movedToThird();
        $this->service->correctTime($this->stint($this->parting), LineupCorrection::DEPARTURE, '10:45', 'Left later than clicked', $this->user);
        $this->assertSame(['1@07:00-10:45', '2@07:00-10:45', '3@10:45-open'], $this->history($this->loader), 'later: the third face is reached later');

        $this->service->correctTime($this->stint($this->seam), LineupCorrection::DEPARTURE, '09:15', 'Earlier after all', $this->user);
        $this->assertSame(['1@07:00-09:15', '2@07:00-09:15', '3@09:15-open'], $this->history($this->loader));
        $this->assertSame(2, LineupCorrection::withoutGlobalScopes()->count());
        $this->assertSame($third->id, $this->stint($third)->lineup_face_id);
    }

    /** @return array<string, array{string, string}> */
    public static function routes(): array
    {
        return ['the arrival on the new face' => [LineupCorrection::ARRIVAL, 'third'], 'the departure from the seam' => [LineupCorrection::DEPARTURE, 'seam'], 'the departure from the parting' => [LineupCorrection::DEPARTURE, 'parting']];
    }

    #[DataProvider('routes')]
    public function test_the_reviewers_late_move_of_a_shared_loader_can_be_corrected_by_every_route(string $edge, string $which): void
    {
        $third = $this->movedToThird();
        $row = match ($which) {
            'third' => $this->stint($third), 'seam' => $this->stint($this->seam), default => $this->stint($this->parting),
        };

        $this->service->correctTime($row, $edge, '10:00', 'late', $this->user);

        $this->assertSame(['1@07:00-10:00', '2@07:00-10:00', '3@10:00-open'], $this->history($this->loader));
    }

    public function test_two_separate_removals_at_one_minute_are_not_one_move_unless_something_arrived_then(): void
    {
        $this->service->release($this->stint($this->seam), 'Finished', $this->user, '10:30');
        $this->service->release($this->stint($this->parting), 'Finished', $this->user, '10:30');

        $this->service->correctTime($this->stint($this->seam), LineupCorrection::DEPARTURE, '10:00', 'Clicked late', $this->user);

        $this->assertSame(['1@07:00-10:00', '2@07:00-10:30'], $this->history($this->loader), 'nothing arrived at 10:30, so only the corrected stint moves');
        $this->assertNull(LineupCorrection::withoutGlobalScopes()->firstOrFail()->partner_allocation_id);
    }

    public function test_moving_onto_two_linked_faces_corrects_as_one_move_too(): void
    {
        $third = $this->third();
        $other = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->service->place($this->shift, $third, $other, $this->user, '07:00');
        $this->service->place($this->shift, $this->seam, $other, $this->user, '10:30', true);
        $this->service->place($this->shift, $this->parting, $other, $this->user, '10:30', true);
        $this->assertSame(['3@07:00-10:30', '1@10:30-open', '2@10:30-open'], $this->history($other));

        $this->service->correctTime($this->stint($this->seam, $other), LineupCorrection::ARRIVAL, '10:00', 'Clicked late', $this->user);

        $this->assertSame(['3@07:00-10:00', '1@10:00-open', '2@10:00-open'], $this->history($other));
    }

    public function test_a_standby_that_follows_two_linked_stints_follows_their_correction(): void
    {
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $this->service->setStandby($this->shift, $this->loader, $reason, $this->user, '10:30');
        $this->assertSame(['1@07:00-10:30', '2@07:00-10:30'], $this->history($this->loader));

        $this->service->correctTime($this->stint($this->seam), LineupCorrection::DEPARTURE, '10:00', 'Clicked late', $this->user);

        $this->assertSame(['1@07:00-10:00', '2@07:00-10:00'], $this->history($this->loader));
        $this->assertSame('10:00', LineupStandby::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->firstOrFail()->from_at->format('H:i'));
    }

    public function test_the_refusals_for_a_move_of_linked_stints_stay_for_genuine_conflicts(): void
    {
        $third = $this->movedToThird();

        $this->assertStringContainsString('LU-05 Times out of order: EX-01 only arrived on Face 1 — Loading area 1 — Seam · C1 / B1 B2 at 07:00. Correct that time first, or pick a time after it.', $this->refusal(fn () => $this->service->correctTime($this->stint($third), LineupCorrection::ARRIVAL, '07:00', 'x', $this->user)));
        $this->assertStringContainsString('Nothing to change', $this->refusal(fn () => $this->service->correctTime($this->stint($third), LineupCorrection::ARRIVAL, '10:30', 'x', $this->user)));
        $this->assertSame(['1@07:00-10:30', '2@07:00-10:30', '3@10:30-open'], $this->history($this->loader), 'a refusal changes nothing');
        $this->assertSame(0, LineupCorrection::withoutGlobalScopes()->count());

        $fourth = $this->third('Loading area 4', 'C3');
        $this->service->release($this->stint($third), 'Finished', $this->user, '11:00');
        $this->service->place($this->shift, $fourth, $this->loader, $this->user, '11:30');
        $this->assertStringContainsString('LU-05 Overlaps another entry: EX-01 was on Face 3 — Loading area 3 — Coal · C2 / B5 10:30–11:00', $this->refusal(fn () => $this->service->correctTime($this->stint($fourth), LineupCorrection::ARRIVAL, '10:45', 'x', $this->user)));
    }

    public function test_a_late_move_to_a_fourth_face_cannot_pass_the_arrival_it_follows(): void
    {
        $third = $this->movedToThird();
        $fourth = $this->third('Loading area 4', 'C3');
        $this->service->place($this->shift, $fourth, $this->loader, $this->user, '10:45', true);

        $this->assertStringContainsString('LU-05 Times out of order: EX-01 only arrived on Face 3 — Loading area 3 — Coal · C2 / B5 at 10:30. Correct that time first, or pick a time after it.', $this->refusal(fn () => $this->service->correctTime($this->stint($fourth), LineupCorrection::ARRIVAL, '10:15', 'x', $this->user)));
        $this->service->correctTime($this->stint($third), LineupCorrection::ARRIVAL, '10:00', 'x', $this->user);
        $this->service->correctTime($this->stint($fourth), LineupCorrection::ARRIVAL, '10:15', 'x', $this->user);

        $this->assertSame(['1@07:00-10:00', '2@07:00-10:00', '3@10:00-10:15', '4@10:15-open'], $this->history($this->loader));
    }

    public function test_the_page_corrects_a_linked_move_and_the_report_notes_every_stint_that_moved_with_it(): void
    {
        $third = $this->movedToThird();
        $this->actingAs($this->user);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('startCorrection', $this->stint($third)->id, 'ARRIVAL')
            ->assertSee('A move is one moment: Face 1 — Loading area 1 — Seam · C1 / B1 B2 and Face 2 — Loading area 1 — Parting · C1 / B2 are corrected with it.')
            ->set('correction.time', '10:00')->set('correction.reason', 'The move was clicked late')
            ->call('saveCorrection')
            ->assertSee('EX-01 on Face 3 — Loading area 3 — Coal · C2 / B5 at 10:00 (was 10:30); off Face 1 — Loading area 1 — Seam · C1 / B1 B2 and Face 2 — Loading area 1 — Parting · C1 / B2 at the same time.');

        $report = $this->get(route('reports.index', ['r' => 'lineup', 'shift' => $this->shift->id]))->assertOk()->getContent();
        $this->assertSame(2, substr_count($report, 'moved with a correction (The move was clicked late)'), 'both departures say they moved with the arrival');
        $this->assertSame(1, substr_count($report, 'arrival corrected from 10:30'));
    }

    public function test_a_correction_never_drags_a_standby_over_the_loaders_other_linked_stint(): void
    {
        $other = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $this->service->place($this->shift, $this->seam, $other, $this->user, '08:00');
        $this->service->place($this->shift, $this->parting, $other, $this->user, '08:30');
        $this->service->release($this->stint($this->seam, $other), 'Finished', $this->user, '09:00');
        $this->service->setStandby($this->shift, $other, $reason, $this->user, '09:30');
        $this->assertSame(['1@08:00-09:00', '2@08:30-09:30'], $this->history($other));

        $message = $this->refusal(fn () => $this->service->correctTime($this->stint($this->parting, $other), LineupCorrection::DEPARTURE, '08:45', 'Left earlier', $this->user));

        $this->assertStringContainsString('LU-05 Overlaps another entry: EX-02 was on Face 1 — Loading area 1 — Seam · C1 / B1 B2 08:00–09:00, and the standby next to this move would cover it.', $message);
        $this->assertSame(['1@08:00-09:00', '2@08:30-09:30'], $this->history($other), 'nothing moved');
        $this->assertSame('09:30', LineupStandby::withoutGlobalScopes()->where('equipment_id', $other->id)->firstOrFail()->from_at->format('H:i'));

        $this->service->correctTime($this->stint($this->parting, $other), LineupCorrection::DEPARTURE, '09:00', 'Left with the seam', $this->user);
        $this->assertSame(['1@08:00-09:00', '2@08:30-09:00'], $this->history($other));
        $this->assertSame('09:00', LineupStandby::withoutGlobalScopes()->where('equipment_id', $other->id)->firstOrFail()->from_at->format('H:i'));
    }

    public function test_a_later_arrival_cannot_stretch_the_standby_before_it_over_a_linked_stint(): void
    {
        $other = Equipment::factory()->for($this->room)->create(['name' => 'EX-02', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $this->service->setStandby($this->shift, $other, $reason, $this->user, '07:00');
        $this->service->place($this->shift, $this->seam, $other, $this->user, '08:00');
        $this->service->place($this->shift, $this->parting, $other, $this->user, '08:30');

        $message = $this->refusal(fn () => $this->service->correctTime($this->stint($this->seam, $other), LineupCorrection::ARRIVAL, '09:00', 'Arrived later', $this->user));

        $this->assertStringContainsString('LU-05 Overlaps another entry: EX-02 was on Face 2 — Loading area 1 — Parting · C1 / B2 08:30–now, and the standby next to this move would cover it.', $message);
        $this->assertSame('08:00', LineupStandby::withoutGlobalScopes()->where('equipment_id', $other->id)->firstOrFail()->to_at->format('H:i'));
        $this->assertSame(['1@08:00-open', '2@08:30-open'], $this->history($other));
    }

    public function test_a_placement_and_an_edit_lock_the_machine_then_the_face_before_reading(): void
    {
        $transactions = [];
        Event::listen(TransactionBeginning::class, function () use (&$transactions): void {
            $transactions[] = [];
        });
        DB::listen(function ($query) use (&$transactions): void {
            if ($transactions !== []) {
                $transactions[array_key_last($transactions)][] = $query->sql;
            }
        });
        $empty = $this->third();
        $transactions = [];

        $this->service->place($this->shift, $empty, $this->truck1, $this->user, '09:00');
        $this->service->editFace($empty->fresh(), ['kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C2', 'blocks' => ['B5'], 'notes' => 'wet'], $this->user);
        $this->service->editFace($this->seam->fresh(), ['kind' => 'LOADING', 'material' => 'Seam', 'cut' => 'C1', 'blocks' => ['B1', 'B2'], 'notes' => 'dry'], $this->user);

        $this->assertCount(3, $transactions, 'one transaction each');
        [$placement, $editOfEmptyFace, $editOfLoadedFace] = $transactions;
        $this->assertStringStartsWith('update "lineup_allocations" set "updated_at"', $placement[0], 'the machine first');
        $this->assertStringStartsWith('update "lineup_faces" set "updated_at"', $placement[2], 'then the face (the equipment row is read between)');
        $this->assertStringStartsWith('update "lineup_faces" set "updated_at"', $editOfEmptyFace[0], 'nothing to lock but the face: its write is the first statement');
        $this->assertStringStartsWith('update "lineup_allocations" set "updated_at"', $editOfLoadedFace[0], 'the loading unit first');
        $this->assertStringStartsWith('update "lineup_faces" set "updated_at"', $editOfLoadedFace[2]);
    }
    // ---- review fixes: a work area breaks every link; the panel and the button speak plainly ------------------

    public function test_turning_a_linked_face_into_a_work_area_while_changing_its_cut_is_refused(): void
    {
        $message = $this->refusal(fn () => $this->service->editFace($this->parting, ['kind' => 'AREA', 'activity' => 'Dozing', 'cut' => 'C9', 'blocks' => ['B7']], $this->user));

        $this->assertStringContainsString('LU-01 EX-01 also loads Face 1 — Loading area 1 — Seam · C1 / B1 B2. After this change Face 2 — Loading area 1 — Parting · C1 / B2 is a work area, so the faces cannot share a loading unit. Choose "Move the loading unit here" to take it off Face 1', $message);
        $this->assertSame('LOADING', $this->parting->fresh()->kind, 'nothing changed');
        $this->assertSame([$this->seam->id, $this->parting->id], LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->whereNull('to_at')->orderBy('lineup_face_id')->pluck('lineup_face_id')->all());
    }

    public function test_turning_a_linked_face_into_a_work_area_without_a_cut_change_is_refused_too_and_the_loader_can_be_moved(): void
    {
        $attrs = ['kind' => 'AREA', 'activity' => 'Dozing', 'cut' => 'C1', 'blocks' => ['B2']];
        $this->assertStringContainsString('LU-01 EX-01 also loads Face 1', $this->refusal(fn () => $this->service->editFace($this->parting, $attrs, $this->user)));

        $this->service->editFace($this->parting->fresh(), $attrs, $this->user, move: true, hhmm: '11:00');

        $this->assertSame('AREA', $this->parting->fresh()->kind);
        $this->assertSame('11:00', $this->stint($this->seam)->to_at->format('H:i'), 'taken off the loading face');
        $this->assertSame('', $this->service->linkedLabel($this->seam->fresh()), 'a work area is never linked');
        $this->assertSame('', $this->service->linkedLabel($this->parting->fresh()));
    }

    public function test_a_work_area_is_not_offered_as_linked_even_if_old_data_has_a_loader_on_it(): void
    {
        LineupFace::withoutGlobalScopes()->whereKey($this->parting->id)->update(['kind' => 'AREA', 'material' => null, 'activity' => 'Dozing']);

        $this->assertSame('', $this->service->linkedLabel($this->seam->fresh()));
        $this->assertSame('', $this->service->linkedLabel($this->parting->fresh()));
        $this->assertNotContains('LU-07', $this->service->checks($this->shift)->pluck('code')->all());
    }

    public function test_the_page_refuses_the_same_input_and_offers_the_named_button(): void
    {
        $this->actingAs($this->user);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('openFaceForm', $this->parting->id)
            ->set('faceForm.kind', 'AREA')->set('faceForm.activity', 'Dozing')->set('faceForm.cut', 'C9')->set('faceForm.blocks', 'B7')
            ->call('saveFace')
            ->assertSee('LU-01 EX-01 also loads Face 1')
            ->assertSeeHtml('id="face-move"')
            ->assertSeeHtml('aria-label="Move the loading unit here: keep EX-01 on Face 2 — Loading area 1 — Parting · C1 / B2 and take it off the other face"')
            ->assertDispatched('lineup-focus', id: 'face-move');

        $this->assertSame('LOADING', $this->parting->fresh()->kind);
        $this->assertCount(2, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->loader->id)->whereNull('to_at')->get());
    }

    public function test_the_panel_sentence_names_the_linked_face_when_only_a_same_side_stint_moves_with_it(): void
    {
        $reason = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $this->service->setStandby($this->shift, $this->loader, $reason, $this->user, '10:30');
        $this->actingAs($this->user);

        Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->call('startCorrection', $this->stint($this->seam)->id, 'DEPARTURE')
            ->assertSee('A move is one moment: Face 2 — Loading area 1 — Parting · C1 / B2 is corrected with it.')
            ->assertDontSee('the face it went to');
    }

    public function test_no_blade_uses_an_empty_wire_modifier(): void
    {
        $offenders = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php') && preg_match_all('/\swire:(?:click|change|submit|keydown|keyup|input|blur)(?:\.[A-Za-z]+)*(?=[\s>\/])/', (string) file_get_contents($file->getPathname()), $m)) {
                $offenders[] = $file->getFilename().': '.implode(', ', $m[0]);
            }
        }

        $this->assertSame([], $offenders, 'an empty wire:click evaluates "$wire." and logs an Alpine error on every click; use x-on:click.stop');
    }
}
