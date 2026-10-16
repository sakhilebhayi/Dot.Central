<?php

namespace Database\Factories;

use App\Models\ControlRoom;
use App\Models\OperationalException;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperationalException>
 */
class OperationalExceptionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'control_room_id' => ControlRoom::factory(),
            'key' => 'TEST|'.Str::random(8),
            'rule_code' => 'TEL-01',
            'group' => 'Telemetry',
            'severity' => 'CRITICAL',
            'outcome' => 'MISSING',
            'title' => fake()->sentence(4),
            'message' => fake()->sentence(8),
            'fix' => 'Check the machine in Dot.Mines.',
            'status' => 'OPEN',
            'raised_by' => 'SYSTEM',
            'raised_at' => now(),
            'last_seen_at' => now(),
        ];
    }
}
