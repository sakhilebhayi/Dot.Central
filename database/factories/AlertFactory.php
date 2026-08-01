<?php

namespace Database\Factories;

use App\Models\Alert;
use App\Models\ControlRoom;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Alert>
 */
class AlertFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'severity'        => fake()->randomElement(Alert::SEVERITIES),
            'title'           => fake()->sentence(4),
            'description'     => fake()->optional()->paragraph(),
            'triggered_at'    => fake()->dateTimeBetween('-1 week', 'now'),
            'cleared_at'      => null,
        ];
    }

    public function cleared(): static
    {
        return $this->state(fn () => ['cleared_at' => now()]);
    }
}
