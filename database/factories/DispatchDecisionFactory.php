<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\DispatchDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\DispatchDecision>
 */
class DispatchDecisionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id'    => ControlRoom::factory(),
            'workflow_type'      => fake()->randomElement(DispatchDecision::WORKFLOW_TYPES),
            'sequence'           => fake()->unique()->numberBetween(1, 100000),
            'decided_at'         => fake()->dateTimeBetween('-1 week', 'now'),
            'decided_by_user_id' => User::factory(),
            'summary'            => fake()->sentence(),
        ];
    }
}
