<?php

namespace Database\Factories;

use App\Models\Equipment;
use App\Models\EquipmentHourReading;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EquipmentHourReading>
 */
class EquipmentHourReadingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'equipment_id' => Equipment::factory(),
            'hours' => fake()->randomFloat(1, 100, 20000),
            'recorded_at' => now()->subMinutes(fake()->numberBetween(1, 600)),
            'source' => 'dot_mines.metrics',
            'ingested_at' => now(),
        ];
    }
}
