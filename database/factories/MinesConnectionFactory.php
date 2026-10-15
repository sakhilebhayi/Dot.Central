<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\MinesConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MinesConnection>
 */
class MinesConnectionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'base_url' => 'http://127.0.0.1:8010',
            'mines_team_id' => fake()->numberBetween(1, 50),
            'api_token' => 'test-token-'.fake()->sha1(),
            'webhook_secret' => null,
            'provider_cadence_seconds' => 900,
            'timezone' => 'UTC',
            'status' => 'unverified',
        ];
    }

    public function verified(): static
    {
        return $this->state(fn () => ['status' => 'verified', 'last_success_at' => now()]);
    }
}
