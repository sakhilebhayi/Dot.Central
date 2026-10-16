<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
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

class ExceptionLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $operator;

    private User $supervisor;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create();
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $this->operator = $this->member($owner->currentTeam, 'operator');
        $this->supervisor = $this->member($owner->currentTeam, 'supervisor');
        $this->manager = $this->member($owner->currentTeam, 'manager');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    private function exception(array $attributes = []): OperationalException
    {
        return OperationalException::factory()->for($this->room)->create(array_merge(['shift_id' => $this->shift->id], $attributes));
    }

    public function test_explaining_a_critical_item_routes_it_to_approval_and_a_review_item_resolves(): void
    {
        $critical = $this->exception(['rule_code' => 'TEL-01', 'severity' => 'CRITICAL', 'outcome' => 'MISSING']);
        $review = $this->exception(['rule_code' => 'EQ-03', 'severity' => 'REVIEW', 'outcome' => 'REQUIRES_EXPLANATION']);

        $this->actingAs($this->operator)->post(route('exceptions.explain', $critical), ['explanation' => ''])->assertSessionHasErrors('explanation');
        $this->actingAs($this->operator)->post(route('exceptions.explain', $critical), ['explanation' => 'Tracker removed in workshop'])->assertRedirect();
        $this->actingAs($this->operator)->post(route('exceptions.explain', $review), ['explanation' => 'Truck assisting at the plant'])->assertRedirect();

        $this->assertSame('EXPLAINED', $critical->fresh()->status);
        $this->assertSame('RESOLVED', $review->fresh()->status);
        $this->assertSame('Explained by operator', $review->fresh()->resolution);
        $this->assertDatabaseHas('exception_events', ['operational_exception_id' => $critical->id, 'action' => 'explained', 'user_id' => $this->operator->id]);
        $this->assertDatabaseHas('audit_log', ['entity_type' => OperationalException::class, 'entity_id' => $critical->id]);
    }

    public function test_supervisors_approve_reject_and_dismiss_but_operators_cannot(): void
    {
        $a = $this->exception(['status' => 'EXPLAINED', 'severity' => 'CRITICAL', 'outcome' => 'MISSING']);
        $b = $this->exception(['status' => 'EXPLAINED', 'severity' => 'CRITICAL', 'outcome' => 'MISSING']);
        $c = $this->exception(['status' => 'OPEN', 'severity' => 'WARNING', 'outcome' => 'SUSPICIOUS']);
        $d = $this->exception(['status' => 'OPEN', 'severity' => 'CRITICAL', 'outcome' => 'MISSING']);

        $this->actingAs($this->operator)->post(route('exceptions.approve', $a))->assertForbidden();
        $this->actingAs($this->supervisor)->post(route('exceptions.approve', $a), ['comment' => 'fine'])->assertRedirect();
        $this->actingAs($this->supervisor)->post(route('exceptions.reject', $b), ['comment' => ''])->assertSessionHasErrors('comment');
        $this->actingAs($this->supervisor)->post(route('exceptions.reject', $b), ['comment' => 'Not acceptable'])->assertRedirect();
        $this->actingAs($this->supervisor)->post(route('exceptions.dismiss', $c), ['comment' => 'Known sensor glitch'])->assertRedirect();
        $this->actingAs($this->supervisor)->post(route('exceptions.dismiss', $d), ['comment' => 'x'])->assertForbidden();

        $this->assertSame('APPROVED', $a->fresh()->status);
        $this->assertSame('REJECTED', $b->fresh()->status);
        $this->assertSame('DISMISSED', $c->fresh()->status);
        $this->assertSame('OPEN', $d->fresh()->status);
    }

    public function test_conflicts_are_resolved_by_supervisors_or_managers(): void
    {
        $conflict = $this->exception(['rule_code' => 'EQ-02', 'severity' => 'CRITICAL', 'outcome' => 'CONFLICT', 'detail' => ['source_a' => 'reading 05:30', 'value_a' => 800, 'source_b' => 'reading 11:55', 'value_b' => 790]]);

        $this->actingAs($this->operator)->post(route('exceptions.resolve', $conflict), ['choice' => 'A', 'note' => ''])->assertForbidden();
        $this->actingAs($this->manager)->post(route('exceptions.resolve', $conflict), ['choice' => 'NEITHER', 'note' => 'Meter replaced, baseline reset in Dot.Mines'])->assertRedirect();

        $this->assertSame('RESOLVED', $conflict->fresh()->status);
        $this->assertStringContainsString('Conflict resolved', $conflict->fresh()->resolution);
    }

    public function test_open_critical_exceptions_block_closing_the_shift(): void
    {
        // Raised by a person (not the engine), so re-evaluation leaves it alone.
        $this->exception(['severity' => 'CRITICAL', 'outcome' => 'MISSING', 'status' => 'OPEN', 'title' => 'EX-01 has no telemetry', 'raised_by' => 'SUPERVISOR']);

        $this->actingAs($this->operator)->post(route('shifts.close', $this->shift), ['notes' => ''])->assertSessionHasErrors('shift');
        $this->actingAs($this->operator)->get(route('shifts.review', $this->shift))->assertOk()->assertSee('EX-01 has no telemetry');
    }

    public function test_pages_render_with_filters_counts_and_badges(): void
    {
        $this->exception(['severity' => 'CRITICAL', 'outcome' => 'MISSING', 'status' => 'OPEN', 'title' => 'EX-01 has no telemetry']);
        $this->exception(['severity' => 'REVIEW', 'outcome' => 'REQUIRES_EXPLANATION', 'status' => 'EXPLAINED', 'title' => 'HT-02 ran with no loads']);
        $this->exception(['severity' => 'WARNING', 'outcome' => 'SUSPICIOUS', 'status' => 'DISMISSED', 'title' => 'Old item']);

        $this->actingAs($this->operator)->get(route('exceptions.index'))->assertOk()
            ->assertSee('EX-01 has no telemetry')->assertSee('HT-02 ran with no loads')->assertDontSee('Old item')->assertSee('Explain');
        $this->actingAs($this->operator)->get(route('exceptions.index', ['status' => 'ALL']))->assertOk()->assertSee('Old item');
        $this->actingAs($this->operator)->get(route('exceptions.index', ['sev' => 'CRITICAL']))->assertOk()->assertSee('EX-01')->assertDontSee('HT-02');

        $this->actingAs($this->supervisor)->get(route('approvals.index'))->assertOk()->assertSee('HT-02 ran with no loads')->assertSee('Approve');
        $this->actingAs($this->operator)->get(route('quality.index'))->assertOk()->assertSee('Missing readings')->assertSee('Open items by rule');

        $this->actingAs($this->operator)->get('/dashboard')->assertOk()->assertSee('nav-count--bad', false);
    }
}
