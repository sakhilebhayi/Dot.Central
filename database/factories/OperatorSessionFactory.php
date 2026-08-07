<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\OperatorSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OperatorSession>
 */
class OperatorSessionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'user_id' => User::factory(),
            'shift_label' => fake()->randomElement(['day', 'night']),
            'started_at' => fake()->dateTimeBetween('-1 week', 'now'),
            'ended_at' => null,
        ];
    }
}
