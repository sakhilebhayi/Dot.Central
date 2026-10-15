<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
use App\Models\MinesConnection;
use App\Models\ProductionRecord;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Engine Hours, Production, Downtime and the Fleet board are read-only
 * views over the derivation; the only input is classifying a stoppage.
 */
class DerivedPagesTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private Equipment $truck;

    private User $operator;

    private User $manager;

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
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'machine_type' => 'haul_truck', 'mines_status' => 'active', 'last_heard_at' => '2026-10-15 11:55:00', 'mine_area_name' => 'North Pit', 'mine_area_id' => 1]);
        EquipmentMetric::factory()->for($this->truck)->create(['recorded_at' => '2026-10-15 05:30:00', 'operating_hours' => 1000.0, 'idle_hours' => 10.0, 'load_count' => 500]);
        EquipmentMetric::factory()->for($this->truck)->create(['recorded_at' => '2026-10-15 11:55:00', 'operating_hours' => 1005.0, 'idle_hours' => 10.5, 'load_count' => 522]);
        ProductionRecord::factory()->for($this->room)->create(['equipment_id' => $this->truck->id, 'record_date' => '2026-10-15', 'shift' => 'day', 'quantity' => 437.0]);
        EquipmentStatusEvent::create(['equipment_id' => $this->truck->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'offline', 'observed_at' => '2026-10-15 08:00:00', 'source' => 'sync']);
        EquipmentStatusEvent::create(['equipment_id' => $this->truck->id, 'field' => 'mines_status', 'from_value' => 'offline', 'to_value' => 'active', 'observed_at' => '2026-10-15 09:30:00', 'source' => 'sync']);
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

    public function test_engine_hours_page_is_derived_and_has_no_inputs(): void
    {
        $html = $this->actingAs($this->operator)->get(route('shifts.hours', $this->shift))->assertOk()
            ->assertSee('Engine Hours')->assertSee('HT-01')->assertSee('1 000.0')->assertSee('1 005.0')->assertSee('5.0')
            ->getContent();

        $this->assertStringNotContainsString('type="number"', $html, 'no reading is typed');
        $this->assertStringContainsString('Dot.Mines metrics', $html);
    }

    public function test_production_page_shows_records_and_loads(): void
    {
        $this->actingAs($this->operator)->get(route('shifts.production', $this->shift))->assertOk()
            ->assertSee('Production')->assertSee('HT-01')->assertSee('437')->assertSee('22')->assertSee('tonnes');
    }

    public function test_downtime_page_lists_observed_stoppages_and_operator_can_classify(): void
    {
        $this->actingAs($this->operator)->get(route('shifts.downtime', $this->shift))->assertOk()
            ->assertSee('Downtime')->assertSee('HT-01')->assertSee('offline')->assertSee('90')->assertSee('Classify');

        $reason = $this->room->downtimeReasons()->where('code', 'BRK_MECH')->firstOrFail();

        $this->actingAs($this->operator)->post(route('shifts.downtime.classify', $this->shift), [
            'equipment_id' => $this->truck->id,
            'started_at' => '2026-10-15T08:00',
            'ended_at' => '2026-10-15T09:30',
            'downtime_reason_id' => $reason->id,
            'note' => '',
        ])->assertSessionHasErrors('note', 'a breakdown reason requires a description');

        $this->actingAs($this->operator)->post(route('shifts.downtime.classify', $this->shift), [
            'equipment_id' => $this->truck->id,
            'started_at' => '2026-10-15T08:00',
            'ended_at' => '2026-10-15T09:30',
            'downtime_reason_id' => $reason->id,
            'note' => 'Hydraulic hose replaced',
        ])->assertRedirect(route('shifts.downtime', $this->shift));

        $classification = DowntimeClassification::firstOrFail();
        $this->assertSame($reason->id, $classification->downtime_reason_id);
        $this->assertSame($this->operator->id, $classification->classified_by);
        $this->assertDatabaseHas('audit_log', ['entity_type' => DowntimeClassification::class, 'entity_id' => $classification->id, 'action' => 'created']);

        $this->actingAs($this->operator)->get(route('shifts.downtime', $this->shift))->assertOk()->assertSee('Breakdown — mechanical')->assertSee('Hydraulic hose replaced');
    }

    public function test_managers_see_the_pages_read_only_and_cannot_classify(): void
    {
        $html = $this->actingAs($this->manager)->get(route('shifts.downtime', $this->shift))->assertOk()->getContent();
        $this->assertStringNotContainsString('Classify', $html);

        $this->actingAs($this->manager)->post(route('shifts.downtime.classify', $this->shift), [
            'equipment_id' => $this->truck->id, 'started_at' => '2026-10-15T08:00', 'ended_at' => '2026-10-15T09:30',
            'downtime_reason_id' => $this->room->downtimeReasons()->first()->id,
        ])->assertForbidden();
    }

    public function test_fleet_board_groups_machines_by_area_with_state(): void
    {
        Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'machine_type' => 'dozer', 'mines_status' => 'idle', 'last_heard_at' => '2026-10-15 11:50:00', 'mine_area_name' => 'South Dump', 'mine_area_id' => 2]);
        Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'machine_type' => 'excavator', 'mines_status' => 'active', 'last_heard_at' => null]);

        $this->actingAs($this->operator)->get(route('control-rooms.fleet', $this->room))->assertOk()
            ->assertSee('Fleet board')
            ->assertSeeInOrder(['North Pit', 'HT-01'])
            ->assertSee('South Dump')->assertSee('DZ-01')
            ->assertSee('No area')->assertSee('EX-01')->assertSee('Unknown')
            ->assertSee('wire:poll', false);
    }
}
