<?php

namespace Tests\Feature\Memory;

use App\Livewire\MemoryContext;
use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\Equipment;
use App\Models\MemoryConnection;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class MemoryContextTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->withPersonalTeam()->create();
        $this->team = $this->owner->currentTeam;
    }

    /** Lazy loading is switched off per call: the switch does not survive a previous render. */
    private function strip(string $class, array $params): Testable
    {
        Livewire::withoutLazyLoading();

        return Livewire::actingAs($this->owner)->test($class, $params);
    }

    public function test_known_history_is_phrased_in_the_control_room_language_and_cached(): void
    {
        $connection = MemoryConnection::factory()->create(['team_id' => $this->team->id]);
        Http::fake(['memory.test/api/intelligence/context*' => Http::response(['data' => [
            'known' => true, 'observations' => 4, 'first_seen' => '2026-08-12T06:00:00Z', 'last_seen' => '2026-10-15T06:00:00Z',
            'what_was_tried' => [['action_kind' => 'alert.acknowledge', 'verdict' => 'improved', 'at' => '2026-09-01T06:00:00Z'], ['action_kind' => 'alert.resolve', 'verdict' => 'improved', 'at' => '2026-09-02T06:00:00Z']],
            'what_worked' => [['action_kind' => 'alert.resolve', 'success_rate' => 1.0]],
        ]])]);

        $this->strip(MemoryContext::class, ['subjectType' => 'equipment', 'subjectId' => '701', 'signature' => 'dot-central:alert:critical'])
            ->assertSee('seen before 4 times')
            ->assertSee('since 12 Aug')
            ->assertSee('tried acknowledge (improved), resolve (improved)')
            ->assertSee('worked best: resolve');
        $this->strip(MemoryContext::class, ['subjectType' => 'equipment', 'subjectId' => '701', 'signature' => 'dot-central:alert:critical'])->assertSee('seen before 4 times');

        Http::assertSentCount(1);
        $this->assertNotNull($connection->fresh()->last_context_at);
    }

    public function test_no_history_and_unreachable_states(): void
    {
        MemoryConnection::factory()->create(['team_id' => $this->team->id]);
        Http::fake(['memory.test/api/intelligence/context*' => Http::sequence()->push(['data' => ['known' => false]])->push('', 503)]);

        $this->strip(MemoryContext::class, ['subjectType' => 'equipment', 'subjectId' => '701', 'signature' => null])->assertSee('no history for this machine');
        $this->strip(MemoryContext::class, ['subjectType' => 'control_room', 'subjectId' => '1', 'signature' => 'x'])->assertSee('Dot.Memory unreachable');
    }

    public function test_strip_renders_on_cards_only_when_a_connection_exists(): void
    {
        $room = ControlRoom::factory()->for($this->team)->create();
        $truck = Equipment::factory()->for($room)->create(['mines_machine_id' => 701]);
        Alert::factory()->for($room)->create(['source' => 'dot_mines', 'external_id' => '1', 'severity' => 'critical', 'equipment_id' => $truck->id]);

        $this->actingAs($this->owner)->get(route('alerts.index'))->assertOk()->assertDontSee('memory-strip');

        MemoryConnection::factory()->create(['team_id' => $this->team->id]);
        Http::fake(['memory.test/*' => Http::response(['data' => ['known' => false]])]);
        $this->actingAs($this->owner)->get(route('alerts.index'))->assertOk()->assertSee('memory-strip');
        $this->actingAs($this->owner)->get(route('control-rooms.equipment.show', [$room, $truck]))->assertOk()->assertSee('memory-strip');
    }
}
