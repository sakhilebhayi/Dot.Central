<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\Operator;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Operator>
 */
class OperatorFactory extends Factory
{
    public function definition(): array
    {
        $n = fake()->unique()->numberBetween(1000, 99999);

        return [
            'control_room_id' => ControlRoom::factory(),
            'employee_number' => 'EMP-'.$n,
            'first_name' => 'Operator',
            'last_name' => (string) $n,
            'is_active' => true,
        ];
    }
}
