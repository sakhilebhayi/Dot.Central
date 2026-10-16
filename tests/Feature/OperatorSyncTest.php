<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Services\Mines\MinesSynchroniser;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Dot.Mines' operator register, who is on which machine, and the canonical
 * equipment type arrive through the sync scopes (I-28, I-30) and webhooks.
 */
class OperatorSyncTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private MinesConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->room = ControlRoom::factory()->create();
        $this->connection = MinesConnection::factory()->for($this->room)->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'status' => 'verified', 'sync_cursor' => 0]);
    }

    /** @return array<string, mixed> */
    private function empty(): array
    {
        return ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]];
    }

    /**
     * One stub per URL: a later Http::fake() for the same pattern does not
     * replace the first, so every sync page is queued up front as a sequence.
     *
     * @param  list<array{changes: array<string, mixed>, deleted?: list<array<string, mixed>>}>  $pages
     */
    private function fakeSyncPages(array $pages): void
    {
        $sequence = Http::sequence();
        foreach ($pages as $i => $page) {
            $sequence->push(['version' => 9 + $i, 'server_time' => now()->toIso8601String(), 'changes' => $page['changes'] + ['fleet' => [], 'production' => [], 'reference' => [], 'operators' => [], 'operator_assignments' => []], 'deleted' => $page['deleted'] ?? [], 'has_more' => false]);
        }
        Http::fake([
            'mines.test/api/v1/sync?*' => $sequence,
            'mines.test/api/v1/machines?*' => Http::response($this->empty()),
            'mines.test/api/v1/alerts?*' => Http::response($this->empty()),
            'mines.test/api/v1/geofences?*' => Http::response($this->empty()),
        ]);
    }

    public function test_operators_assignments_and_the_equipment_type_are_mirrored(): void
    {
        $local = Operator::create(['control_room_id' => $this->room->id, 'employee_number' => 'E-1', 'first_name' => 'Old', 'last_name' => 'Name', 'is_active' => true]);
        $manual = Equipment::factory()->for($this->room)->create(['mines_machine_id' => 8, 'name' => 'DZ-08', 'role_class' => RoleClass::SUPPORT, 'role_class_source' => 'manual']);

        $this->fakeSyncPages([['changes' => [
            'fleet' => [
                ['id' => 7, 'name' => 'HT-07', 'machine_type' => 'bell', 'equipment_type' => 'adt', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => now()->toIso8601String(), 'sync_version' => 1],
                ['id' => 8, 'name' => 'DZ-08', 'machine_type' => 'cat', 'equipment_type' => 'adt', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => now()->toIso8601String(), 'sync_version' => 2],
                ['id' => 9, 'name' => 'X-09', 'machine_type' => 'volvo', 'equipment_type' => 'other', 'status' => 'active', 'allocation_state' => null, 'latitude' => null, 'longitude' => null, 'fuel_level' => null, 'engine_hours' => null, 'payload' => null, 'last_seen_at' => now()->toIso8601String(), 'sync_version' => 3],
            ],
            'operators' => [
                ['id' => 3, 'employee_number' => 'E-1', 'first_name' => 'Operator', 'last_name' => 'One', 'preferred_name' => null, 'employment_status' => 'active', 'default_shift' => 'day', 'mine_area_id' => null, 'sync_version' => 4],
                ['id' => 4, 'employee_number' => 'E-2', 'first_name' => 'Operator', 'last_name' => 'Two', 'preferred_name' => 'Two', 'employment_status' => 'on_leave', 'default_shift' => 'night', 'mine_area_id' => null, 'sync_version' => 5],
            ],
            'operator_assignments' => [
                ['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'shift' => 'day', 'assigned_at' => '2026-10-15T06:00:00+00:00', 'unassigned_at' => null, 'sync_version' => 6],
            ],
        ]], ['changes' => ['operator_assignments' => [['id' => 55, 'operator_id' => 3, 'machine_id' => 7, 'shift' => 'day', 'assigned_at' => '2026-10-15T06:00:00+00:00', 'unassigned_at' => '2026-10-15T18:00:00+00:00', 'sync_version' => 7]]], 'deleted' => [['entity_type' => 'operators', 'entity_id' => 4, 'sync_version' => 8]]]]);

        $run = app(MinesSynchroniser::class)->run($this->connection, 'manual');
        $this->assertSame('succeeded', $run->status);

        $linked = $local->fresh();
        $this->assertSame(3, $linked->mines_operator_id, 'the locally added person is linked by employee number, not duplicated');
        $this->assertSame('Operator One', $linked->name());
        $this->assertSame(2, Operator::withoutGlobalScopes()->where('control_room_id', $this->room->id)->count());
        $two = Operator::withoutGlobalScopes()->where('mines_operator_id', 4)->firstOrFail();
        $this->assertFalse($two->is_active, 'only active employment is offered on the line-up');
        $this->assertSame('on_leave', $two->employment_status);

        $truck = Equipment::withoutGlobalScopes()->where('mines_machine_id', 7)->firstOrFail();
        $this->assertSame(RoleClass::HAUL, $truck->role_class);
        $this->assertSame('mines', $truck->role_class_source);
        $this->assertSame(3, $truck->mines_operator_id);
        $this->assertSame(55, $truck->mines_operator_assignment_id);
        $this->assertSame('day', $truck->mines_operator_shift);
        $this->assertSame(RoleClass::SUPPORT, $manual->fresh()->role_class, 'an administrator-set class beats Dot.Mines');
        $this->assertSame('auto', Equipment::withoutGlobalScopes()->where('mines_machine_id', 9)->firstOrFail()->role_class_source, 'an unclassified machine keeps the guess');

        // Next delta: the stretch ended and the second person left the company.
        app(MinesSynchroniser::class)->run($this->connection->fresh(), 'manual');

        $this->assertNull($truck->fresh()->mines_operator_id);
        $this->assertFalse($two->fresh()->is_active);
        $this->assertSame(2, Operator::withoutGlobalScopes()->count(), 'tombstones never delete history');
    }
}
