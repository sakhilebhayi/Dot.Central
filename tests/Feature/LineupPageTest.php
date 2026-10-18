<?php

namespace Tests\Feature;

use App\Livewire\FleetBoard;
use App\Livewire\Lineup;
use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentStatusEvent;
use App\Models\LineupAllocation;
use App\Models\LineupCorrection;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** The Shift Line-up page: Start Shift leads here; operators place machines by clicking; managers read. */
class LineupPageTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $operator;

    private User $manager;

    private Equipment $loader;

    private Equipment $truck;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active']);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'One', 'employee_number' => 'E-1']);
        $this->operator = $this->member($owner->currentTeam, 'operator', true);
        $this->manager = $this->member($owner->currentTeam, 'manager', false);
    }

    private function member(Team $team, string $role, bool $assign): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        if ($assign) {
            $this->room->users()->attach($user);
        }

        return $user;
    }

    public function test_starting_a_shift_leads_to_the_line_up_and_the_strip_shows_the_step(): void
    {
        $this->shift->forceFill(['status' => 'CLOSED'])->save();
        $night = ShiftType::where('control_room_id', $this->room->id)->where('code', 'NIGHT')->firstOrFail();

        $response = $this->actingAs($this->operator)->post(route('control-rooms.shifts.store', $this->room), ['shift_type_id' => $night->id, 'shift_date' => '2026-10-15']);
        $new = Shift::withoutGlobalScopes()->where('shift_type_id', $night->id)->firstOrFail();
        $response->assertRedirect(route('shifts.lineup', $new));

        $this->actingAs($this->operator)->get(route('shifts.show', $new))->assertOk()->assertSee('Line-up')->assertDontSee('Done: </span>Line-up', false);
        $this->actingAs($this->operator)->get(route('shifts.lineup', $new))->assertOk()->assertSee('Shift Line-up')->assertSee('DRAFT')->assertSee('planning')->assertSee('HT-01');
    }

    public function test_operators_build_the_line_up_by_clicking_and_confirm_it(): void
    {
        $this->actingAs($this->operator);
        $operatorId = (string) Operator::withoutGlobalScopes()->firstOrFail()->id;

        $component = Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSee('Shift Line-up')->assertSee('Confirm line-up')->assertSee('No faces yet')
            ->call('openFaceForm')->set('faceForm.location', 'default:Loading area 1')->set('faceForm.material', 'Overburden')->set('faceForm.cut', 'c1')->set('faceForm.blocks', 'b1 b2')->set('faceForm.loader_ids', [(string) $this->loader->id])->call('saveFace')
            ->assertSee('Face 1 — Loading area 1 — Overburden · C1 / B1 B2')->assertSee('Added Face 1')
            ->call('place', $this->truck->id)->assertSee('HT-01 → Face 1');
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());

        $component->call('openFaceForm')->set('faceForm.location', 'default:Loading area 2')->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('Face 2 — Loading area 2 — Coal')
            ->call('place', $this->truck->id)->assertSee('LU-01')->assertSee('Move here')
            ->call('place', $this->truck->id, true, $component->get('target'))->assertSee('HT-01 → Face 2')->assertDontSee('Refused');
        $this->assertSame(1, LineupAllocation::withoutGlobalScopes()->where('equipment_id', $this->truck->id)->whereNull('to_at')->count());

        $component->call('assignOperator', $this->truck->id, $operatorId)->assertSee('Operator One operates HT-01')
            ->call('assignOperator', $this->loader->id, $operatorId)->assertSee('LU-04')->assertSee('Move operator to this machine')
            ->call('assignOperator', $this->loader->id, $operatorId, true)->assertSee('Operator One operates EX-01');

        $standby = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'role_class' => RoleClass::SUPPORT, 'mines_status' => 'active']);
        $component->set('standbyPick.'.$dozer->id, (string) $standby->id)->call('setStandby', $dozer->id)->assertSee('DZ-01: '.$standby->name)
            ->call('confirm')->assertSee('Line-up confirmed (version 1)')->assertSee('CONFIRMED v1')->assertSee('Re-confirm');

        $this->actingAs($this->operator)->get(route('shifts.show', $this->shift))->assertOk()->assertSee('Done: </span>Line-up', false);
    }

    public function test_managers_see_the_line_up_read_only_and_refusals_name_the_role(): void
    {
        $this->actingAs($this->manager)->get(route('shifts.lineup', $this->shift))->assertOk()->assertSee('Shift Line-up')->assertDontSee('Confirm line-up')->assertDontSee('New face');

        Livewire::actingAs($this->manager)->test(Lineup::class, ['shift' => $this->shift])->call('openFaceForm')->set('faceForm.location', 'default:Loading area 1')->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('SH-04');
    }

    public function test_the_fleet_board_shows_availability_line_up_state_and_issues_per_machine(): void
    {
        $this->actingAs($this->operator);
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'loader_ids' => [$this->loader->id]], $this->operator);
        $service->place($this->shift, $face, $this->truck, $this->operator);
        $service->assignOperator($this->shift, $this->truck, Operator::withoutGlobalScopes()->firstOrFail(), $this->operator);
        $dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'role_class' => RoleClass::SUPPORT, 'mines_status' => 'maintenance', 'last_heard_at' => now()]);
        EquipmentStatusEvent::create(['equipment_id' => $dozer->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 09:00:00', 'source' => 'sync']);
        Alert::factory()->for($this->room)->create(['severity' => 'warning', 'title' => 'Hydraulic pressure low', 'equipment_id' => $this->truck->id]);
        OperationalException::factory()->for($this->room)->create(['shift_id' => $this->shift->id, 'equipment_id' => $this->loader->id, 'rule_code' => 'LU-10', 'title' => 'EX-01 is on Face 1 with no operator', 'raised_by' => 'SUPERVISOR']);

        $page = Livewire::test(FleetBoard::class, ['controlRoom' => $this->room]);
        $page->assertSee('Available for operating')->assertSee('Face 1 — Loading area 1 — Coal')->assertSee('Operator One')->assertSee('alert: Hydraulic pressure low')->assertSee('LU-10 EX-01 is on Face 1 with no operator')->assertSee('down: Dot.Mines: maintenance')->assertSee('no operator');
        $this->assertSame(2, $page->viewData('availability')['available']);
        $this->assertSame(1, $page->viewData('availability')['down']);

        $page->set('filter', 'available')->assertSee('HT-01')->assertDontSee('DZ-01');
        $page->set('filter', 'free')->assertDontSee('HT-01')->assertSee('Every machine has a place or a reason.');
    }

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
}
