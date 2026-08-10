<?php

namespace Tests\Unit\Models;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Neither Conversation nor Message had a factory before this task, so
 * nothing in this domain could be tested without hand-rolling
 * Model::create() calls. This proves both factories, and the
 * relationship between them, actually work.
 */
class ConversationFactoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_factory_creates_a_valid_conversation_with_its_own_user_and_agent(): void
    {
        $conversation = Conversation::factory()->create();

        $this->assertNotNull($conversation->user);
        $this->assertNotNull($conversation->agent);
    }

    public function test_message_factory_creates_a_valid_message_attached_to_a_conversation(): void
    {
        $message = Message::factory()->create();

        $this->assertNotNull($message->conversation);
        $this->assertContains($message->role, ['user', 'assistant']);
    }
}
