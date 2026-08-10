<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Agent>
 */
class AgentFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->firstName().' Assistant';

        return [
            'team_id' => Team::factory(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(6),
            'description' => fake()->sentence(),
            'system_prompt' => 'You are a helpful assistant for '.fake()->company().'.',
            'model' => 'claude-sonnet-4-6',
            'avatar_path' => null,
            'is_active' => true,
            'capabilities' => [],
        ];
    }
}
