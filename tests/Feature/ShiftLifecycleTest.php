<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Services\Shifts\ShiftBlockers;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ShiftLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private User $operator;

    private User $supervisor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 07:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->operator = $this->member($owner->currentTeam, 'operator');
        $this->supervisor = $this->member($owner->currentTeam, 'supervisor');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    public function test_start_proposes_the_shift_for_now_then_the_one_after_the_last(): void
    {
        $this->actingAs($this->operator)->get(route('control-rooms.shifts.start', $this->room))
            ->assertOk()->assertSee('Day shift')->assertSee('2026-10-15');

        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->actingAs($this->operator)->post(route('control-rooms.shifts.store', $this->room), ['shift_type_id' => $day->id, 'shift_date' => '2026-10-15'])
            ->assertRedirect();

        $shift = Shift::firstOrFail();
        $this->assertSame('OPEN', $shift->status);
        $this->assertSame('2026-10-15 06:00:00', $shift->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-15 18:00:00', $shift->ends_at->format('Y-m-d H:i:s'));

        $this->actingAs($this->operator)->post(route('control-rooms.shifts.store', $this->room), ['shift_type_id' => $day->id, 'shift_date' => '2026-10-15'])
            ->assertSessionHasErrors('shift');
        $this->assertSame(1, Shift::count());

        $this->actingAs($this->operator)->post(route('shifts.close', $shift), ['notes' => 'Quiet shift.'])->assertRedirect();
        $this->assertSame('CLOSED', $shift->fresh()->status);

        $this->actingAs($this->operator)->get(route('control-rooms.shifts.start', $this->room))
            ->assertOk()->assertSee('Night shift')->assertSee('Quiet shift.');
    }

    public function test_close_is_refused_while_blockers_exist(): void
    {
        $this->app->instance(ShiftBlockers::class, new class implements ShiftBlockers
        {
            public function blocking(Shift $shift): Collection
            {
                return collect([['code' => 'TEL-01', 'title' => 'No telemetry for HT-01']]);
            }
        });
        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($this->room)->for($day, 'shiftType')->create(['status' => 'OPEN']);

        $this->actingAs($this->operator)->post(route('shifts.close', $shift), ['notes' => ''])->assertSessionHasErrors('shift');
        $this->assertSame('OPEN', $shift->fresh()->status);
    }

    public function test_handover_is_issued_on_close_and_acknowledged_by_the_next_start(): void
    {
        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $night = ShiftType::where('control_room_id', $this->room->id)->where('code', 'NIGHT')->firstOrFail();
        $shift = Shift::factory()->for($this->room)->for($day, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);

        $this->actingAs($this->operator)->post(route('shifts.close', $shift), ['notes' => 'HT-01 still offline.'])->assertRedirect();

        $handover = ShiftHandover::where('shift_id', $shift->id)->firstOrFail();
        $this->assertSame('HT-01 still offline.', $handover->notes);
        $this->assertNull($handover->acknowledged_at);
        $this->assertArrayHasKey('fleet', $handover->snapshot);

        $this->actingAs($this->operator)->post(route('control-rooms.shifts.store', $this->room), ['shift_type_id' => $night->id, 'shift_date' => '2026-10-15'])->assertRedirect();
        $this->assertSame($this->operator->id, $handover->fresh()->acknowledged_by);

        $this->assertDatabaseHas('audit_log', ['entity_type' => Shift::class, 'entity_id' => $shift->id, 'action' => 'updated']);
    }

    public function test_supervisor_approves_and_reopens_with_a_reason_and_operators_cannot(): void
    {
        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($this->room)->for($day, 'shiftType')->create(['status' => 'CLOSED', 'closed_at' => now()]);

        $this->actingAs($this->operator)->post(route('shifts.approve', $shift))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('shifts.approve', $shift), ['comment' => 'ok'])->assertRedirect();
        $this->assertSame('APPROVED', $shift->fresh()->status);

        $this->actingAs($this->supervisor)->post(route('shifts.reopen', $shift), ['reason' => ''])->assertSessionHasErrors('reason');
        $this->actingAs($this->supervisor)->post(route('shifts.reopen', $shift), ['reason' => 'Late telemetry arrived'])->assertRedirect();
        $this->assertSame('REOPENED', $shift->fresh()->status);
        $this->assertDatabaseHas('audit_log', ['entity_type' => Shift::class, 'entity_id' => $shift->id, 'action' => 'override', 'reason' => 'Late telemetry arrived']);
    }

    public function test_operations_and_review_pages_render_for_the_open_shift(): void
    {
        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($this->room)->for($day, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);

        $this->actingAs($this->operator)->get(route('shifts.show', $shift))->assertOk()->assertSee('Shift Operations')->assertSee('Day shift');
        $this->actingAs($this->operator)->get(route('shifts.review', $shift))->assertOk()->assertSee('End Shift Review')->assertSee('Close shift and issue handover');
        $this->actingAs($this->operator)->get(route('control-rooms.shifts.index', $this->room))->assertOk()->assertSee('OPEN');
        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertSee('Day shift');
    }
}
