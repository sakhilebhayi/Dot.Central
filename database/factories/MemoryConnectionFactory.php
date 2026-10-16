<?php

namespace Database\Factories;

use App\Models\MemoryConnection;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MemoryConnection> */
class MemoryConnectionFactory extends Factory
{
    protected $model = MemoryConnection::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'base_url' => 'http://memory.test',
            'api_token' => 'memory-test-token',
            'enabled' => true,
            'status' => 'verified',
        ];
    }
}
