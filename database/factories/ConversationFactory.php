<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Conversation>
 */
class ConversationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'agent_id' => Agent::factory(),
            'title' => 'Chat with '.fake()->firstName().' Assistant',
        ];
    }
}
