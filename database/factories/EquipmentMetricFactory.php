<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\EquipmentMetric;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentMetric>
 */
class EquipmentMetricFactory extends Factory
{
    public function definition(): array
    {
        return [
            'equipment_id' => Equipment::factory(),
            'recorded_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'operating_hours' => fake()->randomFloat(1, 100, 20000),
            'idle_hours' => fake()->randomFloat(1, 10, 2000),
            'fuel_level' => fake()->randomFloat(0, 5, 100),
            'engine_running' => true,
            'load_count' => fake()->numberBetween(100, 9000),
            'ingested_at' => now(),
        ];
    }
}
