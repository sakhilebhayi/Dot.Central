<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DowntimeClassification;
use App\Models\Equipment;
use App\Models\EquipmentMetric;
use App\Models\EquipmentStatusEvent;
use App\Models\MinesConnection;
use App\Models\OperationalException;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\User;
use App\Services\RoomProvisioner;
use App\Services\Rules\RuleEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * docs/dot-central/12-derived-operations-design.md §4 — rules over derived
 * data; findings are upserted per key, resolved when the condition clears
 * and reopened when it returns.
 */
class RuleEngineTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['timezone' => 'UTC', 'provider_cadence_seconds' => 900]);
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
    }

    private function codes(): array
    {
        return OperationalException::where('control_room_id', $this->room->id)->where('status', 'OPEN')->pluck('rule_code')->sort()->values()->all();
    }

    public function test_telemetry_rules_fire_for_unknown_and_stale_machines(): void
    {
        Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'mines_status' => 'active', 'last_heard_at' => null]);
        Equipment::factory()->for($this->room)->create(['name' => 'HT-02', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(45)]); // > 2×15 min, < 4×15 min → stale
        Equipment::factory()->for($this->room)->create(['name' => 'HT-03', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(5)]);

        app(RuleEngine::class)->evaluate($this->room, $this->shift);

        $this->assertSame(['TEL-01'], $this->codes(), 'stale under the stale_minutes threshold is not yet an exception');
        $tel = OperationalException::where('rule_code', 'TEL-01')->firstOrFail();
        $this->assertSame('CRITICAL', $tel->severity);
        $this->assertSame('MISSING', $tel->outcome);
        $this->assertStringContainsString('EX-01', $tel->title);
        $this->assertNotNull($tel->fix);

        $this->room->settings()->set('stale_minutes', 30);
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertSame(['TEL-01', 'TEL-02'], $this->codes());
    }

    public function test_equipment_rules_from_the_derivation(): void
    {
        $truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'machine_type' => 'haul_truck', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(5)]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 05:30:00', 'operating_hours' => 1000.0, 'idle_hours' => 10.0, 'load_count' => 500]);
        EquipmentMetric::factory()->for($truck)->create(['recorded_at' => '2026-10-15 11:55:00', 'operating_hours' => 1006.0, 'idle_hours' => 15.0, 'load_count' => 500]);
        $dozer = Equipment::factory()->for($this->room)->create(['name' => 'DZ-01', 'machine_type' => 'dozer', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(5)]);
        EquipmentMetric::factory()->for($dozer)->create(['recorded_at' => '2026-10-15 05:30:00', 'operating_hours' => 800.0, 'idle_hours' => 1.0, 'load_count' => null]);
        EquipmentMetric::factory()->for($dozer)->create(['recorded_at' => '2026-10-15 11:55:00', 'operating_hours' => 790.0, 'idle_hours' => 1.0, 'load_count' => null]);

        app(RuleEngine::class)->evaluate($this->room, $this->shift);

        $this->assertSame(['EQ-02', 'EQ-03', 'EQ-04'], $this->codes());
        $this->assertSame('CONFLICT', OperationalException::where('rule_code', 'EQ-02')->first()->outcome);
        $this->assertSame($dozer->id, OperationalException::where('rule_code', 'EQ-02')->first()->equipment_id);
        $this->assertSame($truck->id, OperationalException::where('rule_code', 'EQ-03')->first()->equipment_id);
    }

    public function test_stoppage_rules_clear_when_classified_and_reopen_when_the_condition_returns(): void
    {
        $truck = Equipment::factory()->for($this->room)->create(['name' => 'HT-01', 'machine_type' => 'haul_truck', 'mines_status' => 'active', 'last_heard_at' => now()->subMinutes(5)]);
        EquipmentStatusEvent::create(['equipment_id' => $truck->id, 'field' => 'mines_status', 'from_value' => 'active', 'to_value' => 'offline', 'observed_at' => '2026-10-15 08:00:00', 'source' => 'sync']);
        EquipmentStatusEvent::create(['equipment_id' => $truck->id, 'field' => 'mines_status', 'from_value' => 'offline', 'to_value' => 'active', 'observed_at' => '2026-10-15 09:30:00', 'source' => 'sync']);

        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertSame(['DT-01', 'EQ-01'], $this->codes());
        $dt = OperationalException::where('rule_code', 'DT-01')->firstOrFail();

        DowntimeClassification::create(['control_room_id' => $this->room->id, 'equipment_id' => $truck->id, 'shift_id' => $this->shift->id, 'downtime_reason_id' => $this->room->downtimeReasons()->first()->id, 'started_at' => '2026-10-15 08:00:00', 'ended_at' => '2026-10-15 09:30:00', 'note' => 'x']);
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertSame([], $this->codes());
        $this->assertSame('RESOLVED', $dt->fresh()->status);
        $this->assertNull($dt->fresh()->resolved_by);

        DowntimeClassification::query()->delete();
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertSame('OPEN', $dt->fresh()->status, 'a system-resolved exception reopens, no duplicate is created');
        $this->assertSame(1, OperationalException::where('rule_code', 'DT-01')->count());
    }

    public function test_alert_and_shift_rules(): void
    {
        Alert::factory()->for($this->room)->create(['severity' => 'critical', 'triggered_at' => now()->subMinutes(40), 'cleared_at' => null]);
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertSame(['AL-01'], $this->codes());

        Carbon::setTestNow('2026-10-15 19:00:00');
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $this->assertContains('SH-01', $this->codes());
    }

    public function test_human_decisions_are_left_alone_by_re_evaluation(): void
    {
        Equipment::factory()->for($this->room)->create(['name' => 'EX-01', 'mines_status' => 'active', 'last_heard_at' => null]);
        app(RuleEngine::class)->evaluate($this->room, $this->shift);
        $tel = OperationalException::where('rule_code', 'TEL-01')->firstOrFail();
        $tel->forceFill(['status' => 'EXPLAINED', 'explanation' => 'Machine in the workshop, tracker removed'])->save();

        app(RuleEngine::class)->evaluate($this->room, $this->shift);

        $this->assertSame('EXPLAINED', $tel->fresh()->status);
        $this->assertSame(1, OperationalException::count());
    }
}
