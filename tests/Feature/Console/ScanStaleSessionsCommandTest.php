<?php

namespace Tests\Feature\Console;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use App\Models\User;
use App\Notifications\AlertRaisedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ScanStaleSessionsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_session_silent_4_hours_raises_a_warning_alert_once(): void
    {
        Notification::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(5),
            'ended_at' => null,
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();
        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertDatabaseCount('alerts', 1);
        $this->assertDatabaseHas('alerts', [
            'control_room_id' => $controlRoom->id,
            'severity' => 'warning',
        ]);
        Notification::assertSentToTimes($owner, AlertRaisedNotification::class, 1);
    }

    public function test_a_session_silent_8_hours_also_opens_a_proposal(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        $session = OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertDatabaseHas('stale_session_proposals', [
            'operator_session_id' => $session->id,
            'control_room_id' => $controlRoom->id,
            'status' => 'pending',
        ]);
    }

    public function test_a_session_with_a_recent_decision_is_left_alone(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);
        DispatchDecision::factory()->for($controlRoom)->for($owner, 'decidedBy')->create([
            'sequence' => 1,
            'decided_at' => now()->subMinutes(30),
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertSame(0, Alert::count());
        $this->assertSame(0, StaleSessionProposal::count());
    }

    public function test_an_ended_session_is_skipped(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($controlRoom)->create([
            'started_at' => now()->subHours(9),
            'ended_at' => now()->subHours(1),
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $this->assertSame(0, Alert::count());
        $this->assertSame(0, StaleSessionProposal::count());
    }
}
