<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftLineup;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The arrival band on the Operational Overview: the original logo, a welcome
 * from the person's own record, the state of the room in words, and exactly
 * ONE next step that follows the shift and opens for the role it is shown to.
 */
class OverviewArrivalTest extends TestCase
{
    use RefreshDatabase;

    private const LOGO_SHA256 = '77877d8e1c8037301c64dfdafb0b549c3483d7bc4414dcda3734a1000478b930';

    private const LOGO_LIGHT_SHA256 = '7cc61dbf962e5232ef64c1621d44fa2b243342a92fb722e1e346dc3095aba1b7';

    private User $owner;

    private Team $team;

    private ControlRoom $room;

    private ShiftType $day;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $this->owner = User::factory()->withPersonalTeam()->create(['name' => 'Thandi Owner']);
        $this->team = $this->owner->currentTeam;
        $this->team->forceFill(['name' => 'Kopano Mining'])->save();
        $this->room = ControlRoom::factory()->for($this->team)->create(['name' => 'North Room']);
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        app(RoomProvisioner::class)->provision($this->room);
        $this->day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
    }

    private function member(string $role, string $name, bool $inRoom = true): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($this->team);
        if ($inRoom) {
            $this->room->users()->attach($user);
        }

        return $user;
    }

    /** @param array<string, mixed> $attributes */
    private function shift(array $attributes = []): Shift
    {
        return Shift::factory()->for($this->room)->for($this->day, 'shiftType')->create($attributes + ['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
    }

    /**
     * The page renders, carries exactly one primary action with the expected
     * key, and that action's target answers 200 for the same person.
     */
    private function assertArrival(User $user, string $key, string $url): TestResponse
    {
        $response = $this->actingAs($user)->get('/dashboard')->assertOk();
        $html = (string) $response->getContent();

        $this->assertSame(1, substr_count($html, 'data-primary-action='), 'exactly one primary action');
        $this->assertSame(1, preg_match('/<a href="([^"]+)"[^>]*data-primary-action="([^"]+)"/', $html, $m));
        $this->assertSame($key, $m[2]);
        $this->assertSame($url, html_entity_decode($m[1]));
        $this->actingAs($user)->get($m[1])->assertOk();

        return $response;
    }

    private function band(TestResponse $response): string
    {
        $html = (string) $response->getContent();
        $this->assertSame(1, preg_match('/<section class="dot-panels dot-arrival".*?<\/section>/s', $html, $m));

        return $m[0];
    }

    public function test_with_no_shift_an_operator_and_a_supervisor_are_sent_to_start_one(): void
    {
        foreach (['operator' => 'Sipho Operator', 'supervisor' => 'Lerato Supervisor'] as $role => $name) {
            $this->assertArrival($this->member($role, $name), 'start-shift', route('control-rooms.shifts.start', $this->room))
                ->assertSee('Start shift')
                ->assertSee('Welcome, '.explode(' ', $name)[0].'. You are in North Room, Kopano Mining.')
                ->assertSee('No shift has been started in this room yet. Nothing needs you right now.');
        }
    }

    public function test_a_just_started_shift_sends_the_operator_to_the_line_up_and_everything_else_stays_reachable(): void
    {
        $shift = $this->shift();

        $this->assertArrival($this->member('operator', 'Sipho Operator'), 'lineup', route('shifts.lineup', $shift))
            ->assertSee('Build the line-up')
            ->assertSee('15 Oct 2026 · Day shift is open.')
            ->assertSee(route('shifts.show', $shift), false)
            ->assertSee(route('exceptions.index', ['room' => $this->room->id]), false)
            ->assertDontSee(route('control-rooms.shifts.start', $this->room).'" class="dot-btn', false);
    }

    public function test_mid_shift_with_a_confirmed_line_up_the_action_is_returning_to_the_shift(): void
    {
        $shift = $this->shift();
        ShiftLineup::withoutGlobalScopes()->create(['shift_id' => $shift->id, 'control_room_id' => $this->room->id, 'status' => 'CONFIRMED', 'version' => 1]);

        $this->assertArrival($this->member('operator', 'Sipho Operator'), 'shift', route('shifts.show', $shift))
            ->assertSee('Return to the shift')
            ->assertSee(route('shifts.lineup', $shift), false);
    }

    public function test_a_shift_past_its_window_sends_the_operator_to_end_it(): void
    {
        $shift = $this->shift(['shift_date' => '2026-10-14', 'starts_at' => '2026-10-14 06:00:00', 'ends_at' => '2026-10-14 18:00:00']);

        $this->assertArrival($this->member('operator', 'Sipho Operator'), 'end-shift', route('shifts.review', $shift))
            ->assertSee('End shift and hand over')
            ->assertSee('The shift window ended at 18:00.');
    }

    public function test_read_only_roles_get_an_action_they_can_open(): void
    {
        $manager = $this->member('manager', 'Mpho Manager', inRoom: false);

        $this->assertArrival($manager, 'fleet', route('control-rooms.fleet', $this->room))->assertDontSee('Start shift');

        $closed = $this->shift(['status' => 'CLOSED', 'shift_date' => '2026-10-14', 'starts_at' => '2026-10-14 06:00:00', 'ends_at' => '2026-10-14 18:00:00']);
        $this->assertArrival($manager, 'handover', route('shifts.review', $closed))
            ->assertSee('No shift is open. The last one, 14 Oct 2026 · Day shift, is closed.');

        $open = $this->shift();
        $this->assertArrival($manager, 'shift', route('shifts.show', $open));
        // The owner is an administrator: sees the room, cannot run the shift.
        $this->assertArrival($this->owner, 'shift', route('shifts.show', $open))->assertSee('Welcome, Thandi.');
    }

    public function test_an_owner_is_led_to_connect_dot_mines_and_then_to_create_a_room_when_there_is_none(): void
    {
        MinesConnection::query()->where('control_room_id', $this->room->id)->delete();
        $this->assertArrival($this->owner, 'connect', route('control-rooms.connection.edit', $this->room))->assertSee('Connect Dot.Mines');

        $this->room->delete();
        $this->assertArrival($this->owner, 'create-room', route('control-rooms.create'))
            ->assertSee('No control room exists for this team yet.');
    }

    public function test_a_new_member_with_nothing_gets_an_honest_empty_state_without_any_figure(): void
    {
        $new = $this->member('operator', 'Naledi Newcomer', inRoom: false);

        $response = $this->assertArrival($new, 'team', route('teams.show', $this->team))
            ->assertSee('Welcome, Naledi. You are signed in to Kopano Mining.')
            ->assertSee('You are not assigned to a control room yet.')
            ->assertDontSee('dot-readout', false);

        $this->assertSame(0, preg_match('/\d/', strip_tags(preg_replace('/<img[^>]*>/', '', $this->band($response)))), 'no figure in the empty arrival band');
    }

    public function test_the_state_sentence_counts_only_what_is_recorded(): void
    {
        $shift = $this->shift();
        $operator = $this->member('operator', 'Sipho Operator');
        $state = fn (): string => trim(strip_tags((string) preg_replace('/.*data-arrival-state>(.*?)<\/p>.*/s', '$1', (string) $this->actingAs($operator)->get('/dashboard')->assertOk()->getContent())));

        $this->assertSame('15 Oct 2026 · Day shift is open. Nothing needs you right now.', $state());

        OperationalException::factory()->for($this->room)->count(2)->create(['shift_id' => $shift->id, 'severity' => 'WARNING']);
        OperationalException::factory()->for($this->room)->create(['shift_id' => $shift->id, 'severity' => 'CRITICAL']);
        OperationalException::factory()->for($this->room)->create(['shift_id' => $shift->id, 'severity' => 'CRITICAL', 'status' => 'RESOLVED']);
        Alert::factory()->for($this->room)->create(['severity' => 'warning']);
        Alert::factory()->for($this->room)->cleared()->create(['severity' => 'critical']);
        // Another room's records never reach this sentence.
        OperationalException::factory()->for(ControlRoom::factory()->for($this->team))->create();

        $this->assertSame('15 Oct 2026 · Day shift is open. 3 exceptions and 1 open alert need attention; 1 critical exception blocks the shift close.', $state());
    }

    public function test_the_band_shows_the_original_logo_files_and_the_way_back_to_infodot(): void
    {
        $this->assertSame(self::LOGO_SHA256, hash_file('sha256', public_path('images/logo.png')));
        $this->assertSame(self::LOGO_LIGHT_SHA256, hash_file('sha256', public_path('images/logo-light.png')));

        $band = $this->band($this->actingAs($this->member('operator', 'Sipho Operator'))->get('/dashboard')->assertOk());

        $this->assertStringContainsString('src="'.asset('images/logo.png').'" alt="Dot.Central"', $band);
        $this->assertStringContainsString('src="'.asset('images/logo-light.png').'" alt="Dot.Central"', $band);
        $this->assertStringContainsString('<a href="https://infodot.co.za/home">InfoDot</a>', $band);
        $this->assertStringContainsString('<a href="https://infodot.co.za/platforms">Platforms</a>', $band);
        $this->assertStringNotContainsString('<svg', $band);
    }

    public function test_the_platform_registry_lists_only_current_platforms(): void
    {
        $current = ['agents', 'analytics', 'auction', 'central', 'charts', 'design', 'docs', 'dopemine', 'ehail', 'emall', 'engage', 'files', 'finance', 'forms', 'hr', 'memory', 'mines', 'notify', 'press', 'projects', 'pulse', 'sheet', 'tasks', 'tutor', 'infodot'];

        $this->assertSame([], array_values(array_diff(array_keys(config('ecosystem.platforms')), $current)));
    }
}
