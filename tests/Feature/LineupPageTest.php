<?php

namespace Tests\Feature;

use App\Livewire\Lineup;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\LineupAllocation;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
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

        $this->actingAs($this->operator)->get(route('shifts.show', $new))->assertOk()->assertSee('Line-up')->assertDontSee('✓ Line-up');
        $this->actingAs($this->operator)->get(route('shifts.lineup', $new))->assertOk()->assertSee('Shift Line-up')->assertSee('DRAFT')->assertSee('planning')->assertSee('HT-01');
    }

    public function test_operators_build_the_line_up_by_clicking_and_confirm_it(): void
    {
        $this->actingAs($this->operator);
        $operatorId = (string) Operator::withoutGlobalScopes()->firstOrFail()->id;

        $component = Livewire::test(Lineup::class, ['shift' => $this->shift])
            ->assertSee('Shift Line-up')->assertSee('Confirm line-up')->assertSee('No faces yet')
            ->call('openFaceForm')->set('faceForm.material', 'Overburden')->set('faceForm.cut', 'c1')->set('faceForm.blocks', 'b1 b2')->set('faceForm.loader_ids', [(string) $this->loader->id])->call('saveFace')
            ->assertSee('Face 1 — Overburden · C1 / B1 B2')->assertSee('Added Face 1')
            ->call('place', $this->truck->id)->assertSee('HT-01 → Face 1');
        $this->assertSame(2, LineupAllocation::withoutGlobalScopes()->where('shift_id', $this->shift->id)->count());

        $component->call('openFaceForm')->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('Face 2 — Coal')
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

        $this->actingAs($this->operator)->get(route('shifts.show', $this->shift))->assertOk()->assertSee('✓ Line-up');
    }

    public function test_managers_see_the_line_up_read_only_and_refusals_name_the_role(): void
    {
        $this->actingAs($this->manager)->get(route('shifts.lineup', $this->shift))->assertOk()->assertSee('Shift Line-up')->assertDontSee('Confirm line-up')->assertDontSee('New face');

        Livewire::actingAs($this->manager)->test(Lineup::class, ['shift' => $this->shift])->call('openFaceForm')->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('SH-04');
    }
}
