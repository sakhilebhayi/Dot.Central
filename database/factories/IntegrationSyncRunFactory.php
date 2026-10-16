<?php

namespace Database\Factories;

use App\Models\IntegrationSyncRun;
use App\Models\MinesConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationSyncRun>
 */
class IntegrationSyncRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'mines_connection_id' => MinesConnection::factory(),
            'trigger' => 'schedule',
            'status' => 'running',
            'started_at' => now(),
        ];
    }

    public function succeeded(): static
    {
        return $this->state(fn () => ['status' => 'succeeded', 'finished_at' => now(), 'cursor_after' => 10]);
    }

    public function failed(string $class = 'App\\Services\\Mines\\Exceptions\\MinesUnavailable', string $message = 'Dot.Mines returned HTTP 503.'): static
    {
        return $this->state(fn () => ['status' => 'failed', 'finished_at' => now(), 'error_class' => $class, 'error_message' => $message]);
    }
}
