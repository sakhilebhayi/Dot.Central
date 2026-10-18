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
use App\Services\Shifts\ShiftService;
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
}
