<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * R-51: the Overview as a ruled sheet (one queue of exceptions and alerts, a detail pane, a fleet strip) and the Agents
 * landing. What is pinned here: one truth for "what needs me" in the bar, the headline and the nav; the roles and
 * policies of every action; honest empty states and dashes; tenant isolation; the validated selection hint.
 */
class OverviewDashboardTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    private ControlRoom $room;

    private Shift $shift;

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
        $day = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($day, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
    }

    private function member(string $role, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $this->team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($this->team);
        $this->room->users()->attach($user);

        return $user;
    }

    private function html(User $user, string $query = ''): string
    {
        return (string) $this->actingAs($user)->get('/dashboard'.$query)->assertOk()->getContent();
    }

    private function exception(string $severity, string $outcome, string $title, array $extra = []): OperationalException
    {
        return OperationalException::factory()->create(['control_room_id' => $this->room->id, 'severity' => $severity, 'outcome' => $outcome, 'title' => $title, 'shift_id' => $this->shift->id, 'raised_at' => now()->subMinutes(10)] + $extra);
    }

    /** The one section of the page that belongs to a queue row's detail pane. */
    private function detail(string $html, string $rowId): string
    {
        $this->assertSame(1, preg_match('/<section class="ov-detail"[^>]*data-ov-detail="'.preg_quote($rowId, '/').'".*?<\/section>/s', $html, $m), 'detail for '.$rowId);

        return $m[0];
    }

    public function test_every_role_variant_has_exactly_one_primary_action(): void
    {
        $this->exception('CRITICAL', 'MISSING', 'No telemetry');
        foreach (['operator', 'supervisor', 'manager'] as $role) {
            $this->assertSame(1, substr_count($this->html($this->member($role, ucfirst($role).' Person')), 'data-primary-action='), $role);
        }
        $this->assertSame(1, substr_count($this->html($this->owner), 'data-primary-action='), 'administrator');
    }

    public function test_the_bar_the_headline_and_the_nav_count_the_same_things(): void
    {
        $this->exception('CRITICAL', 'MISSING', 'No telemetry from DZ-402');
        $this->exception('CRITICAL', 'CONFLICT', 'Hour meter went backwards');
        $this->exception('WARNING', 'SUSPICIOUS', 'Run hours exceed window');
        Alert::factory()->for($this->room)->create(['severity' => 'critical', 'title' => 'Tyre pressure low']);
        Alert::factory()->for($this->room)->create(['severity' => 'warning', 'title' => 'Haul road wet']);
        $html = $this->html($this->member('supervisor', 'Sipho Supervisor'));

        // Bar: 2 critical exceptions + 1 critical alert = 3 critical; 3 exceptions + 2 alerts = 5 open.
        $this->assertStringContainsString('3 critical, 5 open, 1 control room.', $html);
        $this->assertStringContainsString('statebar--bad', $html);
        // Headline: the same sentence the page opens with, from the exceptions that block the close.
        $this->assertStringContainsString('2 critical exceptions block the shift close.', $html);
        // The queue and its heading carry the same five.
        $this->assertSame(5, substr_count($html, 'data-ov-row="'));
        $this->assertStringContainsString('Needs you <span class="ov-mono">05</span>', $html);
        // Nav badge counts open exceptions (3), not alerts.
        $this->assertMatchesRegularExpression('/Exceptions\s*<span class="nav-count nav-count--bad">3<\/span>/', $html);
    }

    public function test_the_queue_is_one_list_most_severe_first_and_the_first_row_is_selected(): void
    {
        Alert::factory()->for($this->room)->create(['severity' => 'warning', 'title' => 'Haul road wet', 'triggered_at' => now()->subMinutes(5)]);
        $e = $this->exception('CRITICAL', 'MISSING', 'No telemetry from DZ-402');
        $review = $this->exception('REVIEW', 'REQUIRES_APPROVAL', 'Downtime needs a reason');
        $html = $this->html($this->member('supervisor', 'Sipho Supervisor'));

        preg_match_all('/data-ov-row="([a-z]+-\d+)"/', $html, $m);
        $this->assertSame(['exception-'.$e->id, 'alert-'.Alert::first()->id, 'exception-'.$review->id], $m[1]);
        $this->assertMatchesRegularExpression('/aria-selected="true" data-ov-row="exception-'.$e->id.'"/', $html);
        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
    }

    public function test_the_selection_hint_is_validated_against_the_loaded_rows(): void
    {
        $first = $this->exception('CRITICAL', 'MISSING', 'First');
        $second = $this->exception('WARNING', 'SUSPICIOUS', 'Second');
        $user = $this->member('supervisor', 'Sipho Supervisor');

        $this->assertMatchesRegularExpression('/aria-selected="true" data-ov-row="exception-'.$second->id.'"/', $this->html($user, '?sel=exception-'.$second->id));
        $this->assertStringNotContainsString('data-ov-detail="exception-'.$second->id.'" aria-label="Selected exception: Second" hidden', $this->html($user, '?sel=exception-'.$second->id));
        // An id that is not in the queue (nonsense, or another room's row) falls back to the first row.
        $foreign = OperationalException::factory()->create(['control_room_id' => ControlRoom::factory()->for($this->team)->create()->id]);
        foreach (['exception-999999', 'alert-1', 'exception-'.$foreign->id, '<script>', ''] as $bad) {
            $this->assertMatchesRegularExpression('/aria-selected="true" data-ov-row="exception-'.$first->id.'"/', $this->html($user, '?sel='.urlencode($bad)), $bad);
        }
    }

    public function test_a_supervisor_gets_the_decision_forms_but_never_dismiss_on_a_critical_exception(): void
    {
        $critical = $this->exception('CRITICAL', 'MISSING', 'No telemetry');
        $warning = $this->exception('WARNING', 'SUSPICIOUS', 'Run hours exceed window');
        $html = $this->html($this->member('supervisor', 'Sipho Supervisor'));

        $c = $this->detail($html, 'exception-'.$critical->id);
        $this->assertStringContainsString(route('exceptions.explain', $critical), $c);
        $this->assertStringContainsString(route('exceptions.approve', $critical), $c);
        $this->assertStringContainsString(route('exceptions.reject', $critical), $c);
        $this->assertStringNotContainsString(route('exceptions.dismiss', $critical), $c, 'a critical exception can never be dismissed');
        // Rejecting and dismissing still require their reason.
        $this->assertMatchesRegularExpression('/name="comment" class="ov-input" placeholder="Reason for rejection" required/', $c);

        $w = $this->detail($html, 'exception-'.$warning->id);
        $this->assertStringContainsString(route('exceptions.dismiss', $warning), $w);
        $this->assertMatchesRegularExpression('/name="comment" class="ov-input" placeholder="Why is this not an issue\?" required/', $w);
    }

    public function test_an_operator_explains_but_does_not_decide_and_a_conflict_offers_the_resolver_to_a_supervisor(): void
    {
        $e = $this->exception('WARNING', 'SUSPICIOUS', 'Run hours exceed window');
        $conflict = $this->exception('CRITICAL', 'CONFLICT', 'Hour meter went backwards', ['detail' => ['source_a' => 'Dot.Mines', 'value_a' => '120', 'source_b' => 'Operator', 'value_b' => '118']]);

        $operator = $this->detail($this->html($this->member('operator', 'Olu Operator')), 'exception-'.$e->id);
        $this->assertStringContainsString(route('exceptions.explain', $e), $operator);
        $this->assertStringNotContainsString(route('exceptions.approve', $e), $operator);
        $this->assertStringNotContainsString(route('exceptions.dismiss', $e), $operator);

        $supervisorHtml = $this->html($this->member('supervisor', 'Sipho Supervisor'));
        $this->assertStringContainsString(route('exceptions.resolve', $conflict), $this->detail($supervisorHtml, 'exception-'.$conflict->id));
    }

    public function test_a_manager_reads_the_facts_and_sees_no_write_form(): void
    {
        $e = $this->exception('CRITICAL', 'MISSING', 'No telemetry');
        $a = Alert::factory()->for($this->room)->create(['severity' => 'critical', 'title' => 'Tyre pressure low']);
        $html = $this->html($this->member('manager', 'Mpho Manager'));

        foreach ([$this->detail($html, 'exception-'.$e->id), $this->detail($html, 'alert-'.$a->id)] as $detail) {
            $this->assertStringContainsString('Your role can read this. A supervisor decides on exceptions and alerts.', $detail);
            $this->assertStringNotContainsString('<form', $detail);
        }
    }

    public function test_alert_actions_follow_the_role(): void
    {
        $a = Alert::factory()->for($this->room)->create(['severity' => 'warning', 'title' => 'Haul road wet']);
        $detail = $this->detail($this->html($this->member('supervisor', 'Sipho Supervisor')), 'alert-'.$a->id);

        foreach (['alerts.acknowledge', 'alerts.assign', 'alerts.escalate', 'alerts.resolve'] as $route) {
            $this->assertStringContainsString(route($route, $a), $detail, $route);
        }
    }

    public function test_an_absent_reading_is_a_dash_never_a_zero(): void
    {
        Equipment::factory()->for($this->room)->heard(60)->create(['name' => 'HT-101', 'mines_status' => 'active']);
        $html = $this->html($this->member('operator', 'Olu Operator'));

        $this->assertMatchesRegularExpression('/data-l="Run h">—<\/td>/', $html);
        $this->assertMatchesRegularExpression('/data-l="Loads">—<\/td>/', $html);
        $this->assertMatchesRegularExpression('/Production<b>—<\/b>/', $html);
        $this->assertMatchesRegularExpression('/Stoppage<b>—<\/b>/', $html);
        $this->assertStringContainsString('run hours and loads show a dash until Dot.Mines sends readings for this shift', $html);
    }

    public function test_empty_states_say_what_is_true(): void
    {
        $html = $this->html($this->member('operator', 'Olu Operator'));

        $this->assertStringContainsString('Nothing needs attention on this shift.', $html);
        $this->assertStringContainsString('Nothing needs you right now.', $html);
        $this->assertStringContainsString('No machines mirrored. Connect Dot.Mines', $html);
        $this->assertStringContainsString('No machine is reported stopped.', $html);
        $this->assertStringNotContainsString('data-ov-detail=', $html, 'no detail pane when nothing is raised');
        $this->assertStringContainsString(route('exceptions.index', ['room' => $this->room->id]), $html, 'the full lists stay one link away');
        $this->assertStringContainsString('statebar--ok', $html);
        $this->assertStringContainsString('ALL QUIET', $html);
    }

    public function test_another_teams_exceptions_and_alerts_never_reach_the_queue_the_bar_or_the_nav(): void
    {
        $other = User::factory()->withPersonalTeam()->create();
        $theirRoom = ControlRoom::factory()->for($other->currentTeam)->create(['name' => 'Elsewhere']);
        OperationalException::factory()->create(['control_room_id' => $theirRoom->id, 'severity' => 'CRITICAL', 'title' => 'Their critical thing']);
        Alert::factory()->for($theirRoom)->create(['severity' => 'critical', 'title' => 'Their alert']);

        $html = $this->html($this->member('supervisor', 'Sipho Supervisor'));

        $this->assertStringNotContainsString('Their critical thing', $html);
        $this->assertStringNotContainsString('Their alert', $html);
        $this->assertStringContainsString('ALL QUIET', $html);
        $this->assertStringNotContainsString('nav-count--bad', $html);
    }

    public function test_the_fleet_cells_carry_state_by_shape_and_link_to_the_machine(): void
    {
        $up = Equipment::factory()->for($this->room)->heard(30)->create(['name' => 'HT-101', 'mines_status' => 'active']);
        Equipment::factory()->for($this->room)->heard(30)->create(['name' => 'HT-102', 'mines_status' => 'offline']);
        $html = $this->html($this->member('operator', 'Olu Operator'));

        $this->assertStringContainsString('ov-u ov-u--op', $html);
        $this->assertStringContainsString('ov-u ov-u--off', $html);
        $this->assertStringContainsString('href="'.route('control-rooms.equipment.show', [$this->room, $up]).'" class="ov-u ov-u--op"', $html);
        $this->assertStringContainsString('aria-label="HT-101, Operating, last heard', $html);
        $this->assertMatchesRegularExpression('/Stopped now <span class="ov-mono">01<\/span>/', $html);
    }

    public function test_the_refresh_is_a_pausable_sentinel_and_the_page_has_no_inline_script(): void
    {
        $html = $this->html($this->member('operator', 'Olu Operator'));

        $this->assertStringContainsString('wire:poll.30s.visible data-ov-poll', $html);
        $this->assertStringNotContainsString('x-data', substr($html, (int) strpos($html, 'data-ov-root')), 'selection lives in the page script, not in morphed Alpine markup');
    }

    public function test_the_agents_landing_states_counts_honestly_and_keeps_its_links(): void
    {
        $user = $this->member('operator', 'Olu Operator');
        Agent::factory()->for($this->team)->count(2)->create(['is_active' => true]);
        Agent::factory()->for($this->team)->create(['is_active' => false]);
        // Another team's agent is not on this page (Agent is team-scoped).
        Agent::factory()->create(['name' => 'Foreign agent']);

        $html = (string) $this->actingAs($user)->get(route('agents.hub'))->assertOk()->getContent();

        $this->assertStringContainsString('3 agents, 2 active.', $html);
        $this->assertStringNotContainsString('Foreign agent', $html);
        $this->assertStringContainsString('Kopano Mining', $html);
        $this->assertStringContainsString('Agent Hub Dashboard', $html);
        $this->assertStringContainsString('Your conversations', $html);
        $this->assertSame(1, substr_count($html, 'data-primary-action='));
        $this->assertStringContainsString(route('agents.create'), $html);
        $this->assertStringContainsString(route('agents.index'), $html);
        $this->assertStringNotContainsString('border-radius:0.875rem', $html, 'the rounded KPI cards are gone');
    }
}
