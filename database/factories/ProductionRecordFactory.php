<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\ProductionRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ProductionRecord>
 */
class ProductionRecordFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'mines_record_id' => fake()->unique()->numberBetween(1, 100000),
            'record_date' => now()->toDateString(),
            'shift' => 'day',
            'quantity' => fake()->randomFloat(2, 100, 900),
            'unit' => 'tonnes',
            'mines_status' => 'recorded',
            'last_synced_at' => now(),
        ];
    }
}
