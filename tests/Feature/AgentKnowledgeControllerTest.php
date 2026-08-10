<?php

namespace Tests\Feature;

use App\Models\AgentKnowledge;
use App\Models\User;
use App\Services\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class AgentKnowledgeControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/agent-knowledge')->assertRedirect('/login');
    }

    public function test_index_shows_the_current_teams_documents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        AgentKnowledge::factory()->for($user->currentTeam)->create(['title' => 'Onboarding Guide']);

        $this->actingAs($user)
            ->get('/agent-knowledge')
            ->assertOk()
            ->assertSee('Onboarding Guide');
    }

    public function test_a_user_can_upload_pasted_text(): void
    {
        $user = User::factory()->withPersonalTeam()->create();

        $response = $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'Team Policy',
            'input_mode' => 'text',
            'content' => 'Employees get 20 days of leave per year.',
        ]);

        $this->assertDatabaseHas('agent_knowledge', [
            'team_id' => $user->currentTeam->id,
            'title' => 'Team Policy',
            'content' => 'Employees get 20 days of leave per year.',
            'source_type' => 'pasted',
        ]);
        $response->assertRedirect(route('agent-knowledge.index'));
    }

    public function test_a_user_can_upload_a_text_file(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->createWithContent('faq.txt', "Q: What is Dot.Central?\nA: An AI command centre.");

        $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'FAQ',
            'input_mode' => 'file',
            'file' => $file,
        ]);

        $this->assertDatabaseHas('agent_knowledge', [
            'team_id' => $user->currentTeam->id,
            'title' => 'FAQ',
            'source_type' => 'text_file',
            'original_filename' => 'faq.txt',
        ]);
        $doc = AgentKnowledge::where('title', 'FAQ')->firstOrFail();
        $this->assertStringContainsString('An AI command centre.', $doc->content);
    }

    public function test_a_valid_pdf_is_extracted_and_stored(): void
    {
        $this->mock(PdfTextExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn('Extracted PDF content here.');
        });

        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->create('manual.pdf', 100, 'application/pdf');

        $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'Manual',
            'input_mode' => 'file',
            'file' => $file,
        ]);

        $this->assertDatabaseHas('agent_knowledge', [
            'team_id' => $user->currentTeam->id,
            'title' => 'Manual',
            'content' => 'Extracted PDF content here.',
            'source_type' => 'pdf',
            'original_filename' => 'manual.pdf',
        ]);
    }

    public function test_a_pdf_that_fails_extraction_is_rejected_with_a_validation_error(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->create('bad.pdf', 10, 'application/pdf');
        // Not mocking PdfTextExtractor here -- this is a genuinely invalid
        // PDF (fake placeholder bytes), exercising the real failure path.

        $response = $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'Bad File',
            'input_mode' => 'file',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
        $this->assertDatabaseMissing('agent_knowledge', ['title' => 'Bad File']);
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->create('huge.txt', 6000); // 6MB, over the 5MB cap

        $response = $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'Huge File',
            'input_mode' => 'file',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $file = UploadedFile::fake()->create('image.png', 10, 'image/png');

        $response = $this->actingAs($user)->post('/agent-knowledge', [
            'title' => 'Image',
            'input_mode' => 'file',
            'file' => $file,
        ]);

        $response->assertSessionHasErrors('file');
    }

    public function test_a_user_can_delete_an_owned_document(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = AgentKnowledge::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->delete("/agent-knowledge/{$doc->id}");

        $response->assertRedirect(route('agent-knowledge.index'));
        $this->assertDatabaseMissing('agent_knowledge', ['id' => $doc->id]);
    }

    public function test_a_user_cannot_delete_another_teams_document(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $doc = AgentKnowledge::factory()->for($owner->currentTeam)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->delete("/agent-knowledge/{$doc->id}")
            ->assertNotFound();
    }
}
