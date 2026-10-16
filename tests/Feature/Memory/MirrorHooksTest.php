<?php

namespace Tests\Feature\Memory;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MemoryConnection;
use App\Models\MemoryOutboxRow;
use App\Models\MinesConnection;
use App\Models\Operator;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\Mines\MinesSynchroniser;
use App\Services\RoomProvisioner;
use App\Support\RoleClass;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The two loop records with no audit row behind them (mirror writes) and
 * the one refusal that never becomes a local row.
 */
class MirrorHooksTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private MinesConnection $connection;

    private Equipment $truck;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-16 12:00:00');
        Queue::fake();
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        $this->connection = MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC']);
        MemoryConnection::factory()->create(['team_id' => $owner->currentTeam->id]);
        app(RoomProvisioner::class)->provision($this->room);
        $this->truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'mines_machine_id' => 701, 'role_class' => RoleClass::HAUL, 'mines_status' => 'active']);
        MemoryOutboxRow::query()->delete();
    }

    public function test_a_newly_mirrored_alert_is_an_observation_and_a_repeat_is_not(): void
    {
        $resource = ['id' => 88, 'machine_id' => 701, 'priority' => 'critical', 'title' => 'Engine temperature high', 'status' => 'active', 'triggered_at' => '2026-10-16T11:00:00+00:00', 'source_triggered_at' => '2026-10-16T10:58:00+00:00'];

        app(MinesSynchroniser::class)->applyAlert($this->connection, $resource);
        app(MinesSynchroniser::class)->applyAlert($this->connection, $resource);

        $rows = MemoryOutboxRow::where('stage', 'event')->get();
        $this->assertCount(1, $rows);
        $payload = $rows->first()->payload;
        $this->assertSame(['equipment', '701', 'HT-01', 'dot-central:alert:critical', 'dot-mines-mirror'], [$payload['subject_type'], $payload['subject_id'], $payload['subject_label'], $payload['signature'], $payload['source']]);
        $this->assertSame('2026-10-16T10:58:00+00:00', $payload['occurred_at']);
    }

    public function test_an_alert_cleared_by_dot_mines_is_an_outcome_with_time_to_clear(): void
    {
        $resource = ['id' => 89, 'machine_id' => 701, 'priority' => 'high', 'title' => 'Low fuel', 'status' => 'active', 'triggered_at' => '2026-10-16T11:00:00+00:00'];
        app(MinesSynchroniser::class)->applyAlert($this->connection, $resource);

        app(MinesSynchroniser::class)->applyAlert($this->connection, ['status' => 'resolved', 'resolved_at' => '2026-10-16T11:40:00+00:00'] + $resource);

        $outcome = MemoryOutboxRow::where('stage', 'outcome')->firstOrFail()->payload;
        $this->assertSame(['improved', 'alert.time_to_clear_minutes', 40, 'cleared by dot-mines'], [$outcome['verdict'], $outcome['measure'], $outcome['value_after'], $outcome['notes']]);
        $this->assertNotNull(Alert::withoutGlobalScopes()->where('external_id', '89')->first()->cleared_at);
    }

    public function test_an_operator_assignment_refused_by_dot_mines_is_a_refused_action(): void
    {
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-16', 'starts_at' => '2026-10-16 06:00:00', 'ends_at' => '2026-10-16 18:00:00']);
        $user = User::factory()->create();
        $this->room->team->users()->attach($user, ['role' => 'operator']);
        $user->switchTeam($this->room->team);
        $this->room->users()->attach($user);
        $operator = Operator::factory()->for($this->room)->create(['mines_operator_id' => 6, 'first_name' => 'Operator', 'last_name' => 'One']);
        Http::fake(['mines.test/api/v1/machines/701/operator-assignments' => Http::response(['message' => 'ineligible', 'errors' => ['operator_id' => ['Medical expired', 'No licence for haul_truck']]], 422)]);

        $this->actingAs($user);
        $service = app(LineupService::class);
        $face = $service->addFace($shift, ['location' => 'default:Loading area 1', 'kind' => 'LOADING', 'material' => 'Overburden'], $user);
        $service->place($shift, $face, $this->truck, $user);

        try {
            $service->assignOperator($shift, $this->truck, $operator, $user);
            $this->fail('expected LU-04');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('LU-04', $e->errors()['lineup'][0]);
        }

        $action = MemoryOutboxRow::where('stage', 'action')->where('event_id', 'like', 'dot-central:assignment-refused:%')->firstOrFail()->payload;
        $this->assertSame(['operator.assign', 'dot-mines', 'refused', '701'], [$action['action_kind'], $action['executor_platform'], $action['execution_status'], $action['subject_id']]);
        $this->assertSame(['Medical expired', 'No licence for haul_truck'], $action['detail']['blockers']);
        $this->assertSame($user->id, $action['user_id']);
    }
}
