<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\Team;
use App\Models\User;
use App\Services\Audits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * Every operational state change leaves an append-only audit row that
 * supervisors, managers and administrators can read and export.
 */
class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role, ControlRoom $room): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $room->users()->attach($user);

        return $user;
    }

    public function test_creating_and_clearing_an_alert_writes_audit_rows(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        $supervisor = $this->member($team, 'supervisor', $room);

        $this->actingAs($supervisor)->post(route('control-rooms.alerts.store', $room), [
            'severity' => 'critical',
            'title' => 'Telemetry missing on haul unit',
            'triggered_at' => now()->format('Y-m-d\TH:i'),
        ]);

        $alert = Alert::where('title', 'Telemetry missing on haul unit')->firstOrFail();

        $created = AuditEntry::where('entity_type', Alert::class)->where('entity_id', $alert->id)->where('action', 'created')->firstOrFail();
        $this->assertSame($supervisor->id, $created->user_id);
        $this->assertSame('supervisor', $created->user_role);
        $this->assertSame($team->id, $created->team_id);
        $this->assertSame($room->id, $created->control_room_id);
        $this->assertFalse($created->automatic);
        $this->assertSame('Telemetry missing on haul unit', $created->changes['title']['new']);

        $this->actingAs($supervisor)->patch(route('alerts.update', $alert));

        $updated = AuditEntry::where('entity_type', Alert::class)->where('entity_id', $alert->id)->where('action', 'updated')->firstOrFail();
        $this->assertNull($updated->changes['cleared_at']['old']);
        $this->assertNotNull($updated->changes['cleared_at']['new']);
    }

    public function test_stale_session_scan_writes_automatic_rows_without_a_user(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        OperatorSession::factory()->for($room)->for($owner, 'operator')->create([
            'started_at' => now()->subHours(9),
            'ended_at' => null,
        ]);

        $this->artisan('central:scan-stale-sessions')->assertSuccessful();

        $rows = AuditEntry::where('control_room_id', $room->id)->get();
        $this->assertGreaterThanOrEqual(2, $rows->count()); // alert + proposal
        $this->assertTrue($rows->every(fn (AuditEntry $row) => $row->automatic && $row->user_id === null));
    }

    public function test_audit_entries_cannot_be_updated_or_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $entry = AuditEntry::where('entity_type', ControlRoom::class)->where('entity_id', $room->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $entry->update(['reason' => 'tampered']);
    }

    public function test_audit_entries_cannot_be_deleted(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $entry = AuditEntry::where('entity_type', ControlRoom::class)->where('entity_id', $room->id)->firstOrFail();

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_operators_cannot_open_the_audit_log_but_supervisors_can(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create(['name' => 'Audited Room']);
        $operator = $this->member($team, 'operator', $room);
        $supervisor = $this->member($team, 'supervisor', $room);

        $this->actingAs($operator)->get(route('audit.index'))->assertForbidden();

        $this->actingAs($supervisor)->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Audited Room')
            ->assertSee('created');
    }

    public function test_supervisors_only_see_entries_for_their_rooms(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $mine = ControlRoom::factory()->for($team)->create(['name' => 'Mine Room']);
        $theirs = ControlRoom::factory()->for($team)->create(['name' => 'Their Room']);
        $supervisor = $this->member($team, 'supervisor', $mine);

        $this->actingAs($supervisor)->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Mine Room')
            ->assertDontSee('Their Room');

        $manager = User::factory()->create();
        $team->users()->attach($manager, ['role' => 'manager']);
        $manager->switchTeam($team);

        $this->actingAs($manager)->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Mine Room')
            ->assertSee('Their Room');
    }

    public function test_csv_export_is_gated_and_audited(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        $operator = $this->member($team, 'operator', $room);

        $this->actingAs($operator)->get(route('audit.index', ['export' => 'csv']))->assertForbidden();

        $response = $this->actingAs($owner)->get(route('audit.index', ['export' => 'csv']));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', (string) $response->headers->get('content-type'));
        $this->assertStringStartsWith('occurred_at,user,role,control_room,entity_type,entity_id,action,changes,reason,automatic', $response->streamedContent());

        $this->assertDatabaseHas('audit_log', ['action' => 'generate', 'user_id' => $owner->id, 'entity_type' => 'audit_log']);
    }

    public function test_csv_export_neutralises_spreadsheet_formulas_in_free_text(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $room = ControlRoom::factory()->for($owner->currentTeam)->create();
        app(Audits::class)->record('override', null, [], '=cmd|\' /C calc\'!A0', $room, $owner);

        $csv = $this->actingAs($owner)->get(route('audit.index', ['export' => 'csv']))->streamedContent();

        $this->assertStringContainsString("\"'=cmd|", $csv);
        $this->assertStringNotContainsString(',"=cmd', $csv);
    }
}
