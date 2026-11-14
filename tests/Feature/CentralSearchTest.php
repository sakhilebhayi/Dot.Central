<?php

namespace Tests\Feature;

use App\Livewire\HeaderSearch;
use App\Models\Agent;
use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\OperationalException;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Support\Search\CentralSearch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The header search (R-52) is a real search over what Dot.Central holds. It is scoped the way every list in this app is: to the control
 * rooms the person may see (their team, narrowed to assigned rooms for operators and supervisors), to the current team for agents, and
 * operators (personal data) only to people who manage the room.
 */
class CentralSearchTest extends TestCase
{
    use RefreshDatabase;

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);

        return $user;
    }

    /** @return array{owner: User, team: Team, room: ControlRoom} */
    private function company(string $roomName, string $machine, string $suffix = ''): array
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create(['name' => $roomName]);
        Equipment::factory()->for($room)->create(['name' => $machine, 'serial_number' => 'SN-'.$machine.$suffix]);

        return ['owner' => $owner, 'team' => $team, 'room' => $room];
    }

    private function titles(array $groups, string $group): array
    {
        return array_map(fn ($hit) => $hit->title, $groups[$group] ?? []);
    }

    public function test_it_finds_every_kind_of_record_in_the_persons_rooms(): void
    {
        ['owner' => $owner, 'team' => $team, 'room' => $room] = $this->company('Pit Two Control Room', 'ZEBRA-9');
        $equipment = Equipment::withoutGlobalScopes()->where('control_room_id', $room->id)->first();
        Alert::factory()->for($room)->create(['title' => 'Zebra tyre low', 'equipment_id' => $equipment->id]);
        OperationalException::factory()->create(['control_room_id' => $room->id, 'title' => 'Zebra hour meter backwards']);
        $type = ShiftType::factory()->for($room)->create(['name' => 'Zebra shift']);
        Shift::factory()->for($room)->for($type, 'shiftType')->create();
        Operator::factory()->for($room)->create(['first_name' => 'Zebulon', 'last_name' => 'Zebra']);
        Agent::factory()->create(['team_id' => $team->id, 'name' => 'Zebra Planner']);
        $owner->forceFill(['current_team_id' => $team->id])->save();
        $this->actingAs($owner);

        $groups = app(CentralSearch::class)->search($owner->fresh(), 'zebra');

        $this->assertSame(['ZEBRA-9'], $this->titles($groups, 'Equipment'));
        $this->assertSame(['Zebra tyre low'], $this->titles($groups, 'Alerts'));
        $this->assertSame(['Zebra hour meter backwards'], $this->titles($groups, 'Exceptions'));
        $this->assertCount(1, $groups['Shifts']);
        $this->assertStringContainsString('Zebra shift', $groups['Shifts'][0]->title);
        $this->assertSame(['Zebulon Zebra'], $this->titles($groups, 'Operators'), 'the owner is an administrator, who manages the room');
        $this->assertSame(['Zebra Planner'], $this->titles($groups, 'Agents'));
        $this->assertSame(route('control-rooms.equipment.show', [$room->id, $equipment->id]), $groups['Equipment'][0]->url);
    }

    public function test_a_room_is_found_by_name_and_links_to_its_page(): void
    {
        ['owner' => $owner, 'room' => $room] = $this->company('Kopano North Room', 'HT-1');
        $groups = app(CentralSearch::class)->search($owner, 'kopano north');

        $this->assertSame(['Kopano North Room'], $this->titles($groups, 'Control rooms'));
        $this->assertSame(route('control-rooms.show', $room), $groups['Control rooms'][0]->url);
    }

    public function test_another_companys_records_never_appear(): void
    {
        $mine = $this->company('Mine Room', 'SHARED-1');
        $theirs = $this->company('Their Room', 'SHARED-2');
        Alert::factory()->for($theirs['room'])->create(['title' => 'Shared alert theirs']);
        OperationalException::factory()->create(['control_room_id' => $theirs['room']->id, 'title' => 'Shared exception theirs']);
        Operator::factory()->for($theirs['room'])->create(['first_name' => 'Shared', 'last_name' => 'Operator']);
        Agent::factory()->create(['team_id' => $theirs['team']->id, 'name' => 'Shared Agent Theirs']);

        $groups = app(CentralSearch::class)->search($mine['owner'], 'shared');
        $this->assertSame(['SHARED-1'], $this->titles($groups, 'Equipment'));
        $this->assertArrayNotHasKey('Alerts', $groups);
        $this->assertArrayNotHasKey('Exceptions', $groups);
        $this->assertArrayNotHasKey('Operators', $groups);
        $this->assertArrayNotHasKey('Agents', $groups);
        $this->assertArrayNotHasKey('Control rooms', $groups, 'the other room name does not match, and is not theirs to see');

        $this->assertSame([], app(CentralSearch::class)->search($mine['owner'], 'Their Room'));
        $this->assertSame([], app(CentralSearch::class)->search($mine['owner'], 'SHARED-2'));
    }

    public function test_the_dropdown_component_and_the_full_page_are_tenant_scoped_too(): void
    {
        $mine = $this->company('Mine Room', 'SHARED-1');
        $this->company('Their Room', 'SHARED-2');

        $this->actingAs($mine['owner']);
        Livewire::test(HeaderSearch::class)->set('q', 'SHARED')->assertSee('SHARED-1')->assertDontSee('SHARED-2');

        $this->actingAs($mine['owner'])->get(route('search', ['q' => 'shared']))->assertOk()->assertSee('SHARED-1')->assertDontSee('SHARED-2');
    }

    public function test_an_operator_only_searches_the_rooms_they_are_assigned_to(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $north = ControlRoom::factory()->for($team)->create(['name' => 'North Room']);
        $south = ControlRoom::factory()->for($team)->create(['name' => 'South Room']);
        Equipment::factory()->for($north)->create(['name' => 'DOZER-NORTH']);
        Equipment::factory()->for($south)->create(['name' => 'DOZER-SOUTH']);
        $operator = $this->member($team, 'operator');
        $north->users()->attach($operator);

        $groups = app(CentralSearch::class)->search($operator, 'dozer');
        $this->assertSame(['DOZER-NORTH'], $this->titles($groups, 'Equipment'));

        $manager = $this->member($team, 'manager');
        $this->assertEqualsCanonicalizing(['DOZER-NORTH', 'DOZER-SOUTH'], $this->titles(app(CentralSearch::class)->search($manager, 'dozer'), 'Equipment'));
    }

    public function test_operators_are_only_listed_for_people_who_manage_the_room(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $room = ControlRoom::factory()->for($team)->create();
        Operator::factory()->for($room)->create(['first_name' => 'Thabo', 'last_name' => 'Nkosi', 'employee_number' => 'E-777']);
        $supervisor = $this->member($team, 'supervisor');
        $room->users()->attach($supervisor);
        $manager = $this->member($team, 'manager');

        $this->assertArrayNotHasKey('Operators', app(CentralSearch::class)->search($supervisor, 'nkosi'), 'a supervisor cannot open the operator register');
        $this->assertArrayNotHasKey('Operators', app(CentralSearch::class)->search($manager, 'E-777'), 'nor can a manager');
        $this->assertSame(['Thabo Nkosi'], $this->titles(app(CentralSearch::class)->search($owner, 'E-777'), 'Operators'));
    }

    public function test_go_to_pages_follow_permissions(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $team = $owner->currentTeam;
        $operator = $this->member($team, 'operator');

        $this->assertContains('Audit log', $this->titles(app(CentralSearch::class)->search($owner, 'audit'), 'Go to'));
        $this->assertArrayNotHasKey('Go to', app(CentralSearch::class)->search($operator, 'audit'), 'an operator has no audit log');
        $this->assertContains('Alerts', $this->titles(app(CentralSearch::class)->search($operator, 'alerts'), 'Go to'));
    }

    public function test_empty_short_and_no_match_queries(): void
    {
        ['owner' => $owner] = $this->company('Mine Room', 'HT-1');
        $search = app(CentralSearch::class);

        $this->assertSame([], $search->search($owner, ''));
        $this->assertSame([], $search->search($owner, 'a'), 'one character is not a search');
        $this->assertSame([], $search->search($owner, '   '));
        $this->assertSame([], $search->search($owner, 'nothing-like-this'));
        $this->assertFalse(CentralSearch::isSearchable('%_'), 'wildcards alone are not a query');

        $this->actingAs($owner);
        Livewire::test(HeaderSearch::class)->set('q', 'nothing-like-this')->assertSee('Nothing matches')->assertSee('nothing-like-this');
        Livewire::test(HeaderSearch::class)->assertSee('Go to')->assertSee('Type at least 2 characters');
        $this->get(route('search', ['q' => 'nothing-like-this']))->assertOk()->assertSee('Nothing matches');
        $this->get(route('search'))->assertOk()->assertSee('Search control rooms');
    }

    public function test_the_query_is_parameterised_case_insensitive_and_wildcards_are_literal(): void
    {
        ['owner' => $owner, 'room' => $room] = $this->company('Mine Room', 'HT-1');
        Equipment::factory()->for($room)->create(['name' => 'Water Cart']);
        $search = app(CentralSearch::class);

        $this->assertSame(['Water Cart'], $this->titles($search->search($owner, 'WATER cart'), 'Equipment'), 'case does not matter, every word must match');
        $this->assertSame([], $search->search($owner, "x' OR 1=1 --"), 'quotes are data');
        $this->assertSame([], $search->search($owner, '%%'), 'a percent sign is not a wildcard');
        $this->assertSame([], $search->search($owner, 'wx_zq'), 'nor is an underscore: it splits the word, and both halves must match');
        $this->assertSame(['wa', 'r'], CentralSearch::words('wa_%r'));
    }

    public function test_results_are_capped_per_group(): void
    {
        ['owner' => $owner, 'room' => $room] = $this->company('Mine Room', 'HT-1');
        foreach (range(1, 12) as $i) {
            Equipment::factory()->for($room)->create(['name' => 'CAP-'.$i]);
        }

        $this->assertCount(4, app(CentralSearch::class)->search($owner, 'cap-', 4)['Equipment']);
        $this->assertCount(12, app(CentralSearch::class)->search($owner, 'cap-', 25)['Equipment']);
    }

    public function test_a_result_never_carries_markup(): void
    {
        ['owner' => $owner, 'room' => $room] = $this->company('Mine Room', 'HT-1');
        Alert::factory()->for($room)->create(['title' => 'Boom <script>alert(1)</script> low']);
        $this->actingAs($owner);

        $html = Livewire::test(HeaderSearch::class)->set('q', 'boom')->html();
        $this->assertStringNotContainsString('<script>alert(1)', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_search_requires_a_signed_in_member_of_an_entitled_company(): void
    {
        $this->get(route('search', ['q' => 'abc']))->assertRedirect(route('login'));
    }
}
