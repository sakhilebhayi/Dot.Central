<?php

namespace Database\Factories;

use App\Models\AgentKnowledge;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentKnowledge>
 */
class AgentKnowledgeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'title' => fake()->sentence(3),
            'content' => fake()->paragraphs(3, true),
            'source_type' => 'pasted',
            'original_filename' => null,
        ];
    }
}
