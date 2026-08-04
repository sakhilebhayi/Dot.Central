<?php

namespace Tests\Feature;

use App\Models\ControlRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves HasTeamScope itself is load-bearing, independent of any Policy
 * or explicit where('team_id', ...) call: querying ControlRoom directly
 * as a user on a different team, with no manual scoping anywhere in the
 * path, still cannot see the row. This is the property that makes the
 * scope "defense in depth" rather than decorative — it holds even if a
 * future controller forgets to call belongsToTeam() entirely.
 */
class HasTeamScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_alone_blocks_cross_team_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $controlRoom = ControlRoom::factory()->for($owner->currentTeam)->create([
            'name' => 'Owner Control Room',
        ]);

        $this->actingAs($outsider);

        $this->assertNull(ControlRoom::find($controlRoom->id));
        $this->assertSame(0, ControlRoom::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(ControlRoom::find($controlRoom->id));
        $this->assertSame(1, ControlRoom::query()->count());
    }
}
