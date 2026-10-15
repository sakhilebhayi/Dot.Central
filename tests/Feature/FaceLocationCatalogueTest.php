<?php

namespace Tests\Feature;

use App\Livewire\Lineup;
use App\Models\ControlRoom;
use App\Models\Geofence;
use App\Models\MineArea;
use App\Models\MinesConnection;
use App\Models\Shift;
use App\Models\ShiftType;
use App\Models\Team;
use App\Models\User;
use App\Services\Lineup\LineupService;
use App\Services\Lineup\LocationCatalogue;
use App\Services\Mines\MinesSynchroniser;
use App\Services\RoomProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\TestCase;

/** Where a face can be: Dot.Mines geofences, else mine areas, else default mining names (owner instruction 2026-10-15). */
class FaceLocationCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private ControlRoom $room;

    private Shift $shift;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
        $owner = User::factory()->withPersonalTeam()->create();
        $this->room = ControlRoom::factory()->for($owner->currentTeam)->create();
        MinesConnection::factory()->for($this->room)->verified()->create(['base_url' => 'http://mines.test', 'api_token' => 'tok', 'timezone' => 'UTC', 'sync_cursor' => null]);
        app(RoomProvisioner::class)->provision($this->room);
        $type = ShiftType::where('control_room_id', $this->room->id)->where('code', 'DAY')->firstOrFail();
        $this->shift = Shift::factory()->for($this->room)->for($type, 'shiftType')->create(['shift_date' => '2026-10-15', 'starts_at' => '2026-10-15 06:00:00', 'ends_at' => '2026-10-15 18:00:00', 'status' => 'OPEN']);
        $this->operator = $this->member($owner->currentTeam, 'operator');
    }

    private function member(Team $team, string $role): User
    {
        $user = User::factory()->create();
        $team->users()->attach($user, ['role' => $role]);
        $user->switchTeam($team);
        $this->room->users()->attach($user);

        return $user;
    }

    public function test_the_list_falls_back_from_geofences_to_mine_areas_to_default_names(): void
    {
        $catalogue = app(LocationCatalogue::class);

        $defaults = $catalogue->options($this->room);
        $this->assertSame('default', $defaults['source']);
        $this->assertSame('Loading area 1', $defaults['options'][0]['name']);
        $this->assertContains('Dumping area 1', array_column($defaults['options'], 'name'));
        $this->assertContains('Waste dump 1', array_column($defaults['options'], 'name'));

        $area = MineArea::create(['control_room_id' => $this->room->id, 'mines_area_id' => 3, 'name' => 'North Pit', 'status' => 'active']);
        $areas = $catalogue->options($this->room);
        $this->assertSame('area', $areas['source']);
        $this->assertSame([['key' => 'area:'.$area->id, 'name' => 'North Pit']], array_map(fn ($o) => ['key' => $o['key'], 'name' => $o['name']], $areas['options']));

        $fence = Geofence::create(['control_room_id' => $this->room->id, 'mines_geofence_id' => 9, 'name' => 'North Ramp', 'type' => 'pit', 'status' => 'active', 'mine_area_id' => 3]);
        Geofence::create(['control_room_id' => $this->room->id, 'mines_geofence_id' => 10, 'name' => 'Old dump', 'type' => 'dump', 'status' => 'active', 'retired_at' => now()]);
        $fences = $catalogue->options($this->room);
        $this->assertSame('geofence', $fences['source']);
        $this->assertSame(['North Ramp'], array_column($fences['options'], 'name'), 'retired geofences are left out');
        $this->assertSame('Pits', $fences['options'][0]['group']);

        $resolved = $catalogue->resolve($this->room, 'geofence:'.$fence->id);
        $this->assertSame(['location_source' => 'geofence', 'location_ref' => $fence->id, 'location_name' => 'North Ramp', 'mine_area_id' => $area->id], $resolved);

        try {
            $catalogue->resolve($this->room, 'default:Loading area 1');
            $this->fail('a default name is not accepted once geofences exist');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('LU-05', $e->errors()['lineup'][0]);
            $this->assertStringContainsString('geofences', $e->errors()['lineup'][0]);
        }
    }

    public function test_sync_mirrors_geofences_and_retires_the_ones_dot_mines_dropped(): void
    {
        $connection = $this->room->minesConnection;
        $empty = ['data' => [], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 100, 'from' => null, 'to' => null]];
        Geofence::create(['control_room_id' => $this->room->id, 'mines_geofence_id' => 77, 'name' => 'Gone', 'type' => 'dump', 'status' => 'active']);

        Http::fake([
            'mines.test/api/v1/user' => Http::response(['id' => 2, 'name' => 'Demo', 'email' => 'demo@example.test', 'current_team_id' => 2]),
            'mines.test/api/v1/sync?*' => Http::response(['version' => 1, 'server_time' => now()->toIso8601String(), 'changes' => ['fleet' => [], 'production' => [], 'reference' => []], 'deleted' => [], 'has_more' => false]),
            'mines.test/api/v1/machines?*' => Http::response($empty),
            'mines.test/api/v1/alerts?*' => Http::response($empty),
            'mines.test/api/v1/geofences?*' => Http::response(['data' => [
                ['id' => 9, 'name' => 'North Ramp', 'description' => null, 'type' => 'pit', 'status' => 'active', 'mine_area_id' => 3, 'coordinates' => [], 'center' => ['latitude' => null, 'longitude' => null], 'area_sqm' => null, 'perimeter_m' => null, 'notes' => null, 'created_at' => '2026-10-01T00:00:00+00:00', 'updated_at' => '2026-10-01T00:00:00+00:00'],
            ], 'links' => [], 'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1, 'per_page' => 100, 'from' => 1, 'to' => 1]]),
        ]);

        $run = app(MinesSynchroniser::class)->run($connection, 'manual');

        $this->assertSame('succeeded', $run->status);
        $this->assertDatabaseHas('geofences', ['control_room_id' => $this->room->id, 'mines_geofence_id' => 9, 'name' => 'North Ramp', 'type' => 'pit', 'mine_area_id' => 3, 'retired_at' => null]);
        $this->assertNotNull(Geofence::withoutGlobalScopes()->where('mines_geofence_id', 77)->firstOrFail()->retired_at);
    }

    public function test_the_face_form_offers_the_current_list_and_faces_carry_the_location(): void
    {
        $this->actingAs($this->operator);

        Livewire::test(Lineup::class, ['shift' => $this->shift])->call('openFaceForm')
            ->assertSee('default area names')->assertSee('Loading area 1')->assertSee('Dumping area 1')
            ->set('faceForm.location', 'default:Dumping area 1')->set('faceForm.kind', 'AREA')->set('faceForm.activity', 'Dozing')->call('saveFace')
            ->assertSee('Face 1 — Dumping area 1 — Dozing')
            ->call('openFaceForm')->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('LU-05')->assertSee('default area names');

        Geofence::create(['control_room_id' => $this->room->id, 'mines_geofence_id' => 9, 'name' => 'North Ramp', 'type' => 'pit', 'status' => 'active']);
        Livewire::test(Lineup::class, ['shift' => $this->shift])->call('openFaceForm')
            ->assertSee('Dot.Mines geofences')->assertSee('North Ramp')->assertDontSee('Loading area 1')
            ->set('faceForm.location', 'geofence:'.Geofence::withoutGlobalScopes()->firstOrFail()->id)->set('faceForm.material', 'Coal')->call('saveFace')->assertSee('Face 2 — North Ramp — Coal');

        $faces = app(LineupService::class)->faces($this->shift);
        $this->assertSame(['default', 'geofence'], $faces->pluck('location_source')->all());
    }
}
