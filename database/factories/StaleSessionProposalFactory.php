<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\StaleSessionProposal;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StaleSessionProposal>
 */
class StaleSessionProposalFactory extends Factory
{
    public function definition(): array
    {
        return [
            'operator_session_id' => OperatorSession::factory(),
            'control_room_id' => ControlRoom::factory(),
            'hours_silent' => 8,
            'status' => 'pending',
        ];
    }
}
