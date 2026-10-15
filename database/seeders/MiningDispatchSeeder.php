<?php

namespace Database\Seeders;

use App\Models\Alert;
use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\OperatorSession;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds a demonstrable slice of the mining-dispatch domain: one control
 * room (attached to the first available team), a handful of dispatch
 * decisions across all four workflow types, a couple of alerts, and an
 * operator session or two.
 */
class MiningDispatchSeeder extends Seeder
{
    public function run(): void
    {
        $team = Team::first() ?? User::factory()->withPersonalTeam()->create()->currentTeam;

        $controlRoom = ControlRoom::factory()->create([
            'team_id' => $team->id,
            'name' => 'Demo Control Room',
            'mines_site_ref' => 'demo-site',
        ]);

        $operator = User::first() ?? User::factory()->create();

        foreach (DispatchDecision::WORKFLOW_TYPES as $index => $workflowType) {
            DispatchDecision::factory()->create([
                'control_room_id' => $controlRoom->id,
                'workflow_type' => $workflowType,
                'sequence' => $index + 1,
                'decided_by_user_id' => $operator->id,
            ]);
        }

        Alert::factory()->create([
            'control_room_id' => $controlRoom->id,
            'severity' => 'critical',
            'title' => 'Telemetry missing on three haul units',
        ]);

        Alert::factory()->cleared()->create([
            'control_room_id' => $controlRoom->id,
            'severity' => 'warning',
            'title' => 'Sync completed with partial data',
        ]);

        OperatorSession::factory()->create([
            'control_room_id' => $controlRoom->id,
            'user_id' => $operator->id,
            'shift_label' => 'day',
        ]);
    }
}
