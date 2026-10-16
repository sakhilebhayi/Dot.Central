<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\ShiftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShiftType>
 */
class ShiftTypeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'code' => 'DAY',
            'name' => 'Day shift',
            'starts_at' => '06:00',
            'ends_at' => '18:00',
            'crosses_midnight' => false,
            'sort' => 1,
        ];
    }

    public function night(): static
    {
        return $this->state(fn () => ['code' => 'NIGHT', 'name' => 'Night shift', 'starts_at' => '18:00', 'ends_at' => '06:00', 'crosses_midnight' => true, 'sort' => 2]);
    }
}
