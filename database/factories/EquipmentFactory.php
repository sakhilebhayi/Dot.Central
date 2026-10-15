<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\Equipment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Equipment>
 */
class EquipmentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'mines_machine_id' => fake()->unique()->numberBetween(1, 100000),
            'name' => 'HT-'.fake()->unique()->numberBetween(1, 999),
            'machine_type' => 'haul_truck',
            'mines_status' => 'idle',
            'first_synced_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function heard(int $secondsAgo = 60): static
    {
        return $this->state(fn () => [
            'location_recorded_at' => now()->subSeconds($secondsAgo),
            'last_heard_at' => now()->subSeconds($secondsAgo),
            'last_seen_at' => now()->subSeconds($secondsAgo),
        ]);
    }
}
