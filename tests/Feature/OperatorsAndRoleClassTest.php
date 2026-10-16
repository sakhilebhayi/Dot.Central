<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MineArea;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Team;
use App\Models\User;
use App\Services\Mines\MinesSynchroniser;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Room configuration the line-up needs (13-shift-lineup-design.md §5):
 * the operator register, the role class per machine, mirrored mine areas.
 */
class OperatorsAndRoleClassTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private User $owner;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($this->owner->currentTeam)->create();
        $this->operator = $this->member($this->owner->currentTeam, 'operator');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    /** @return list<array{0: string|null, 1: string|null, 2: string|null, 3: float|null, 4: string}> */
    public static function guesses(): array
    {
        return [
            ['HT-01', 'B50E', 'Bell', null, RoleClass::HAUL],
            ['A40', 'A40G', 'Volvo', null, RoleClass::HAUL],
            ['M12', null, null, 40.0, RoleClass::HAUL],
            ['EX-02', 'PC1250', 'Komatsu', null, RoleClass::LOADER],
            ['L-7', 'WA600', null, null, RoleClass::LOADER],
            ['DZ-3', 'D375', 'Komatsu', null, RoleClass::SUPPORT],
            ['Unit', null, null, null, RoleClass::SUPPORT],
        ];
    }

    #[DataProvider('guesses')]
    public function test_the_role_class_guess_reads_model_manufacturer_and_capacity(?string $name, ?string $model, ?string $manufacturer, ?float $capacity, string $expected): void
    {
        $this->assertSame($expected, RoleClass::guess($name, $model, $manufacturer, null, $capacity));
    }

    public function test_sync_mirrors_mine_areas_and_guesses_the_role_class_but_keeps_a_manual_one(): void
    {
        $connection = MinesConnection::factory()->for($this->room)->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'status' => 'unverified', 'sync_cursor' => null]);
        $manual = Equipment::factory()->for($this->room)->create(['mines_machine_id' => 8, 'name' => 'HT-08', 'role_class' => RoleClass::SUPPORT, 'role_class_source' => 'manual']);

        Http::fake([
            'mines.test/api/v1/user' => Http::response(['id' => 2, 'name' => 'Demo', 'email' => 'demo@example.test', 'current_team_id' => 2]),
            'mines.test/api/v1/sync?*' => Http::response(['version' => 5, 'server_time' => now()->toIso8601String(), 'changes' => [
                'fleet' => [
                    ['id' => 7, 'name' => 'EX-07', 'machine_type' => 'komatsu', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => now()->toIso8601String(), 'sync_version' => 4],
                    ['id' => 8, 'name' => 'HT-08', 'machine_type' => 'bell', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => now()->toIso8601String(), 'sync_version' => 5],
                ],
                'production' => [], 'reference' => [['id' => 3, 'name' => 'North Pit', 'status' => 'active', 'sync_version' => 2]],
            ], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
            'mines.test/api/v1/alerts?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]]),
        ]);

        app(MinesSynchroniser::class)->run($connection, 'manual');

        $this->assertDatabaseHas('mine_areas', ['control_room_id' => $this->room->id, 'mines_area_id' => 3, 'name' => 'North Pit']);
        $this->assertSame(1, MineArea::withoutGlobalScopes()->count());
        $loader = Equipment::withoutGlobalScopes()->where('mines_machine_id', 7)->firstOrFail();
        $this->assertSame(RoleClass::LOADER, $loader->role_class);
        $this->assertSame('auto', $loader->role_class_source);
        $this->assertSame(RoleClass::SUPPORT, $manual->fresh()->role_class, 'an administrator-set class survives sync');
    }

    public function test_administrators_manage_the_operator_register_and_it_is_audited(): void
    {
        $payload = ['employee_number' => 'E-100', 'first_name' => 'Operator', 'last_name' => 'One', 'preferred_name' => 'Op', 'tag_id' => 'TAG-1'];

        $this->actingAs($this->operator)->post(route('control-rooms.admin.operators', $this->room), $payload)->assertForbidden();

        $this->actingAs($this->owner)->post(route('control-rooms.admin.operators', $this->room), $payload)->assertRedirect();
        $this->assertDatabaseHas('operators', ['control_room_id' => $this->room->id, 'employee_number' => 'E-100', 'is_active' => true]);
        $operator = Operator::withoutGlobalScopes()->where('employee_number', 'E-100')->firstOrFail();
        $this->assertSame('Op One · E-100', $operator->label());
        $this->assertDatabaseHas('audit_log', ['entity_type' => Operator::class, 'entity_id' => $operator->id, 'action' => 'created']);

        $this->actingAs($this->owner)->post(route('control-rooms.admin.operators', $this->room), $payload)->assertSessionHasErrors('employee_number');

        $this->actingAs($this->owner)->post(route('control-rooms.admin.operators.toggle', [$this->room, $operator]))->assertRedirect();
        $this->assertFalse($operator->fresh()->is_active);

        $this->actingAs($this->owner)->get(route('control-rooms.admin', $this->room))->assertOk()->assertSee('Op One')->assertSee('Equipment roles');
    }

    public function test_administrators_set_a_machine_role_with_a_reason_and_it_is_audited(): void
    {
        $unit = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'role_class' => RoleClass::HAUL, 'role_class_source' => 'auto']);

        $this->actingAs($this->operator)->post(route('control-rooms.admin.equipment-role', [$this->room, $unit]), ['role_class' => RoleClass::SUPPORT, 'reason' => 'It is a dozer'])->assertForbidden();
        $this->actingAs($this->owner)->post(route('control-rooms.admin.equipment-role', [$this->room, $unit]), ['role_class' => 'TRACTOR', 'reason' => 'x'])->assertSessionHasErrors('role_class');

        $this->actingAs($this->owner)->post(route('control-rooms.admin.equipment-role', [$this->room, $unit]), ['role_class' => RoleClass::SUPPORT, 'reason' => 'It is a dozer'])->assertRedirect();
        $unit->refresh();
        $this->assertSame(RoleClass::SUPPORT, $unit->role_class);
        $this->assertSame('manual', $unit->role_class_source);
        $this->assertSame('It is a dozer', AuditEntry::where('entity_type', Equipment::class)->where('entity_id', $unit->id)->latest('id')->value('reason'));
    }
}
