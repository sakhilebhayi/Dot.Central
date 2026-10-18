<?php

namespace Tests\Feature;

use App\Livewire\FleetBoard;
use App\Models\ControlRoom;
use App\Models\DowntimeReason;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
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
use App\Services\Rules\RuleEngine;
use App\Services\Shifts\ShiftService;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;

/** Line-up rules become exceptions; the handover carries the line-up; the next Start Shift shows what is outstanding. */
class LineupRulesAndHandoverTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $operator;

    private Equipment $loader;

    private Equipment $truck;

    private Equipment $dozer;

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
        $this->loader = Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'role_class' => RoleClass::LOADER, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'role_class' => RoleClass::HAUL, 'mines_status' => 'active', 'last_heard_at' => now()]);
        $this->dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'role_class' => RoleClass::SUPPORT, 'mines_status' => 'active', 'last_heard_at' => now()]);
        Operator::factory()->for($this->room)->create(['first_name' => 'Operator', 'last_name' => 'One', 'employee_number' => 'E-1']);
        $this->operator = $this->member($owner->currentTeam, 'operator');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    public function test_line_up_checks_become_exceptions_and_resolve_when_fixed(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'loader_ids' => [$this->loader->id]], $this->operator);

        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $codes = OperationalException::withoutGlobalScopes()->where('shift_id', $this->shift->id)->where('status', 'OPEN')->pluck('rule_code')->countBy()->all();
        $this->assertSame(1, $codes['LU-06'], 'loader without trucks');
        $this->assertSame(2, $codes['LU-09'], 'truck and dozer free');
        $this->assertSame(1, $codes['LU-10'], 'loader without operator');
        $this->assertSame(1, $codes['LU-13'], 'started and not confirmed');

        $service->place($this->shift, $face, $this->truck, $this->operator);
        $standby = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $service->setStandby($this->shift, $this->dozer, $standby, $this->operator);
        $op = Operator::withoutGlobalScopes()->firstOrFail();
        $service->assignOperator($this->shift, $this->loader, $op, $this->operator);
        $service->confirm($this->shift, $this->operator);
        app(RuleEngine::class)->evaluate($this->room, $this->shift);

        $open = OperationalException::withoutGlobalScopes()->where('shift_id', $this->shift->id)->where('status', 'OPEN')->pluck('rule_code')->countBy()->all();
        $this->assertArrayNotHasKey('LU-06', $open);
        $this->assertArrayNotHasKey('LU-09', $open);
        $this->assertArrayNotHasKey('LU-13', $open);
        $this->assertSame(1, $open['LU-10'], 'the truck still has no operator');
        $this->assertSame(5, OperationalException::withoutGlobalScopes()->where('shift_id', $this->shift->id)->where('status', 'RESOLVED')->where('rule_code', 'like', 'LU-%')->count(), 'LU-06, LU-09 x2, LU-10 (loader), LU-13');
    }

    public function test_a_machine_that_ran_but_was_never_placed_is_flagged_at_the_final_review(): void
    {
        $service = app(LineupService::class);
        $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'loader_ids' => [$this->loader->id]], $this->operator);
        EquipmentMetric::factory()->for($this->truck)->create(['recorded_at' => '2026-10-15 06:10:00', 'operating_hours' => 100.0]);
        EquipmentMetric::factory()->for($this->truck)->create(['recorded_at' => '2026-10-15 17:50:00', 'operating_hours' => 104.0]);

        Carbon::setTestNow('2026-10-15 18:30:00');
        app(RuleEngine::class)->evaluate($this->room, $this->shift);

        $this->assertDatabaseHas('operational_exceptions', ['shift_id' => $this->shift->id, 'rule_code' => 'LU-11', 'equipment_id' => $this->truck->id, 'status' => 'OPEN']);
    }

    public function test_the_handover_carries_the_line_up_and_the_next_start_shift_shows_what_is_outstanding(): void
    {
        $service = app(LineupService::class);
        $face = $service->addFace($this->shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Coal', 'cut' => 'C1', 'blocks' => ['B1'], 'loader_ids' => [$this->loader->id]], $this->operator);
        $service->place($this->shift, $face, $this->truck, $this->operator);
        $op = Operator::withoutGlobalScopes()->firstOrFail();
        $service->assignOperator($this->shift, $this->truck, $op, $this->operator);
        $standby = DowntimeReason::where('control_room_id', $this->room->id)->where('category', 'STANDBY')->firstOrFail();
        $service->setStandby($this->shift, $this->dozer, $standby, $this->operator);
        $service->confirm($this->shift, $this->operator);
        EquipmentStatusEvent::create(['equipment_id' => $this->loader->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'maintenance', 'observed_at' => '2026-10-15 16:00:00', 'source' => 'sync']);
        $this->loader->forceFill(['mines_status' => 'maintenance'])->save();

        Carbon::setTestNow('2026-10-15 18:30:00');
        Equipment::withoutGlobalScopes()->where('control_room_id', $this->room->id)->update(['last_heard_at' => '2026-10-15 18:25:00']);
        $closed = app(ShiftService::class)->close($this->shift, 'Loader in the workshop', $this->operator);
        $snap = $closed->fresh('handover')->handover->snapshot;

        $this->assertSame('CONFIRMED', $snap['lineup']['status']);
        $this->assertSame('Face 1 — Loading area 1 — Coal · C1 / B1', $snap['lineup']['faces'][0]['label']);
        $this->assertSame(['HT-01'], $snap['lineup']['faces'][0]['trucks']);
        $this->assertSame(['HT-01' => 'Operator One · E-1'], $snap['lineup']['operators']);
        $this->assertSame('DZ-01', $snap['lineup']['standby_now'][0]['name']);
        $this->assertSame('EX-01', $snap['lineup']['down_now'][0]['name']);

        $this->actingAs($this->operator)->get(route('control-rooms.shifts.start', $this->room))->assertOk()
            ->assertSee('Still down at the previous end (1)')->assertSee('EX-01')->assertSee('Dot.Mines: maintenance')
            ->assertSee('On standby at the previous end (1)')->assertSee('DZ-01')
            ->assertSee('Open exceptions carried')->assertSee('Previous faces (1)')->assertSee('Face 1 — Loading area 1 — Coal');

        $this->actingAs($this->operator)->get(route('reports.index', ['r' => 'lineup', 'shift' => $this->shift->id]))->assertOk()->assertSee('Shift line-up')->assertSee('Face 1 — Loading area 1 — Coal · C1 / B1')->assertSee('Operator One · E-1')->assertSee('down: Dot.Mines: maintenance')->assertSee($standby->name);
        $this->actingAs($this->operator)->get(route('shifts.show', $this->shift))->assertOk()->assertSee('Faces (line-up)')->assertSee('CONFIRMED');
        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertSee('line-up');
    }

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
}
