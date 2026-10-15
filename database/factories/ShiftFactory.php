<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\Shift;
use App\Models\ShiftType;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shift>
 */
class ShiftFactory extends Factory
{
    public function definition(): array
    {
        $room = ControlRoom::factory();

        return [
            'control_room_id' => $room,
            'shift_type_id' => ShiftType::factory()->for($room),
            'shift_date' => now()->toDateString(),
            'starts_at' => now()->startOfDay()->addHours(6),
            'ends_at' => now()->startOfDay()->addHours(18),
            'status' => 'OPEN',
            'opened_at' => now(),
        ];
    }
}
