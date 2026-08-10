# AgentKnowledge Document Grounding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a team upload reference documents (pasted text, `.txt`/`.md`, or PDF) and assign them to specific agents, so replies can be grounded in retrieved content — using only infrastructure that already exists or is trivially portable.

**Architecture:** `AgentKnowledge` (team-scoped, matching `Agent`) holds extracted plain text and is many-to-many assigned to `Agent` (mirroring `AgentSkill`'s existing pattern). A small, focused `PdfTextExtractor` service wraps `smalot/pdfparser` behind one narrow method, kept separate from `AgentKnowledgeController` so tests can stub it for the success path without needing a fragile hand-crafted PDF fixture, while still exercising the real failure path against a genuinely invalid file. `AgentChatService::chat()` gains a retrieval step (`LOWER(...) LIKE`, works identically on Postgres and SQLite) that appends matched excerpts to the system prompt before the existing request-building logic runs — no change to the request shape or streaming logic already in place.

**Tech Stack:** Laravel 13.17.0, PHP 8.5.9 (correcting `wiki.md`'s own long-standing wrong "Laravel 12" claim, fixed 2026-08-10), `smalot/pdfparser` (new dependency, pure PHP, no system binary), Mockery (already installed, `mockery/mockery ^1.6`), PHPUnit, Laravel Pint.

## Global Constraints

- No embedding/vector infrastructure exists anywhere in this app or the shared Postgres instance — confirmed directly (see spec's Context section), not assumed. Do not introduce one; this plan is keyword search only.
- This app runs real PostgreSQL in dev/prod but SQLite in tests (`phpunit.xml` forces `DB_CONNECTION=sqlite`). Every retrieval query must use `LOWER(column) LIKE ?` with a lowercased search term on both sides — never Postgres-specific `ILIKE`/`tsvector`, which SQLite can't run.
- `AgentKnowledge` uses `HasTeamScope`, matching `Agent`'s exact pattern (`app/Models/Concerns/HasTeamScope.php`, already read directly in the prior session pass — scopes to `Auth::user()->currentTeam->id` when authenticated with a current team).
- `agent_agent_knowledge` pivot: composite primary key `['agent_id', 'agent_knowledge_id']`, matching `agent_agent_skill`'s exact existing shape (confirmed via `database/migrations/2026_06_27_000001_create_central_tables.php`) — not an auto-incrementing `id` column.
- Match `AgentController`/`resources/views/agents/*.blade.php`'s exact established conventions: plain controllers, no Livewire, inline-style Blade matching `var(--card-bg)`/`var(--text-primary)`/`'Syne',sans-serif` tokens, `x-app-layout`/`x-validation-errors`/`x-label`/`x-input`/`x-button` components.
- Do not hand-craft a PDF byte fixture for tests — see Task 1's explicit reasoning for why (fragility risk of an unverifiable, hand-authored binary format) and Task 2's actual approach (Mockery for the success path, a genuinely-invalid file for the failure path).
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.
- Full spec: `docs/superpowers/specs/2026-08-10-agent-knowledge-design.md`. Read it before starting if anything below is ambiguous.

---

### Task 1: Dependency, migration, models, `PdfTextExtractor`, factory

**Files:**
- Modify: `composer.json` (adds `smalot/pdfparser`)
- Create: `database/migrations/2026_08_10_000002_create_agent_knowledge_tables.php`
- Create: `app/Models/AgentKnowledge.php`
- Modify: `app/Models/Agent.php` (adds `knowledge()` relation)
- Modify: `app/Models/Team.php` (adds `agentKnowledge()` relation)
- Create: `app/Services/PdfTextExtractor.php`
- Create: `database/factories/AgentKnowledgeFactory.php`
- Test: `tests/Unit/Services/PdfTextExtractorTest.php`
- Modify: `tests/Feature/HasTeamScopeTest.php`

**Interfaces:**
- Produces: `AgentKnowledge` model (`team_id`, `title`, `content`, `source_type`, `original_filename`, `HasTeamScope`, `HasFactory`); `Agent::knowledge(): BelongsToMany`; `Team::agentKnowledge(): HasMany`; `PdfTextExtractor::extract(\Illuminate\Http\UploadedFile $file): string` — throws `\RuntimeException` with a plain-language message if the file can't be parsed or yields no real text. Task 2 consumes `PdfTextExtractor` via constructor injection.

- [ ] **Step 1: Add the dependency**

Run: `composer require smalot/pdfparser`
Expected: adds `smalot/pdfparser` to `composer.json`'s `require` block and `composer.lock`, no conflicts (pure-PHP package, no other dependency touches PDF parsing).

- [ ] **Step 2: Write the migration**

Create `database/migrations/2026_08_10_000002_create_agent_knowledge_tables.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_knowledge', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('content');
            $table->string('source_type'); // pasted, text_file, pdf
            $table->string('original_filename')->nullable();
            $table->timestamps();
            $table->index('team_id');
        });

        Schema::create('agent_agent_knowledge', function (Blueprint $table) {
            $table->foreignId('agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agent_knowledge_id')->constrained('agent_knowledge')->cascadeOnDelete();
            $table->primary(['agent_id', 'agent_knowledge_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_agent_knowledge');
        Schema::dropIfExists('agent_knowledge');
    }
};
```

- [ ] **Step 3: Run the migration**

Run: `php artisan migrate`
Expected: both tables created without error.

- [ ] **Step 4: Write the `AgentKnowledge` model**

Create `app/Models/AgentKnowledge.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Database\Factories\AgentKnowledgeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class AgentKnowledge extends Model
{
    /** @use HasFactory<AgentKnowledgeFactory> */
    use HasFactory, HasTeamScope;

    protected $table = 'agent_knowledge';

    protected $fillable = ['team_id', 'title', 'content', 'source_type', 'original_filename'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function agents(): BelongsToMany
    {
        return $this->belongsToMany(Agent::class, 'agent_agent_knowledge');
    }
}
```

Note: `protected $table = 'agent_knowledge'` is explicit because Eloquent's default pluralization would otherwise guess `agent_knowledges`, which doesn't match the migration.

- [ ] **Step 5: Add `Agent::knowledge()` and `Team::agentKnowledge()`**

Modify `app/Models/Agent.php` — add alongside the existing `skills()` method:

```php
    public function knowledge(): BelongsToMany
    {
        return $this->belongsToMany(AgentKnowledge::class, 'agent_agent_knowledge');
    }
```

Modify `app/Models/Team.php` — add alongside the existing `agents()` method:

```php
    /**
     * The knowledge documents owned by this team, available to be
     * assigned to any of the team's agents.
     */
    public function agentKnowledge(): HasMany
    {
        return $this->hasMany(AgentKnowledge::class);
    }
```

(`HasMany` is already imported in `Team.php` from the earlier `agents()` addition.)

- [ ] **Step 6: Write `PdfTextExtractor`**

Create `app/Services/PdfTextExtractor.php`:

```php
<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Smalot\PdfParser\Parser;

/**
 * Kept as its own small, focused class -- separate from
 * AgentKnowledgeController -- specifically so tests can stub the
 * successful-extraction path (Mockery) without needing a hand-crafted,
 * unverifiable PDF byte fixture, while the real failure path (a
 * genuinely invalid file) still exercises this actual class directly.
 */
class PdfTextExtractor
{
    /**
     * @throws \RuntimeException if the file can't be parsed, or parses
     *   to no real text (e.g. a scanned/image-only PDF with no text layer)
     */
    public function extract(UploadedFile $file): string
    {
        try {
            $pdf = (new Parser)->parseFile($file->getRealPath());
            $text = trim($pdf->getText());
        } catch (\Throwable $e) {
            throw new \RuntimeException('This file could not be read as a PDF.', previous: $e);
        }

        if ($text === '') {
            throw new \RuntimeException('No extractable text was found in this PDF (it may be a scanned image with no text layer).');
        }

        return $text;
    }
}
```

- [ ] **Step 7: Write `PdfTextExtractorTest`**

Create `tests/Unit/Services/PdfTextExtractorTest.php`:

```php
<?php

namespace Tests\Unit\Services;

use App\Services\PdfTextExtractor;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class PdfTextExtractorTest extends TestCase
{
    public function test_a_file_that_is_not_a_real_pdf_is_rejected_with_a_plain_language_message(): void
    {
        $file = UploadedFile::fake()->create('not-a-real.pdf', 10, 'application/pdf');
        // UploadedFile::fake()->create() writes arbitrary placeholder
        // bytes, not a real PDF structure -- this is a genuinely invalid
        // file, exercising the real failure path with no fixture needed.

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This file could not be read as a PDF.');

        (new PdfTextExtractor)->extract($file);
    }
}
```

- [ ] **Step 8: Run the test to verify it passes**

Run: `php artisan test --compact --filter=PdfTextExtractorTest`
Expected: PASS.

- [ ] **Step 9: Write `AgentKnowledgeFactory`**

Create `database/factories/AgentKnowledgeFactory.php`:

```php
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
```

- [ ] **Step 10: Prove the scope is load-bearing for `AgentKnowledge` too**

Modify `tests/Feature/HasTeamScopeTest.php` — add this method alongside the existing `ControlRoom`/`Agent` scope tests:

```php
    public function test_scope_alone_blocks_cross_team_agent_knowledge_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $doc = AgentKnowledge::factory()->for($owner->currentTeam)->create(['title' => 'Owner Doc']);

        $this->actingAs($outsider);

        $this->assertNull(AgentKnowledge::find($doc->id));
        $this->assertSame(0, AgentKnowledge::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(AgentKnowledge::find($doc->id));
        $this->assertSame(1, AgentKnowledge::query()->count());
    }
```

Add `use App\Models\AgentKnowledge;` to the top of the file alongside the existing `use App\Models\Agent;`.

- [ ] **Step 11: Run tests to verify they pass**

Run: `php artisan test --compact --filter="HasTeamScopeTest|PdfTextExtractorTest"`
Expected: PASS, all tests.

- [ ] **Step 12: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock database/migrations/2026_08_10_000002_create_agent_knowledge_tables.php app/Models/AgentKnowledge.php app/Models/Agent.php app/Models/Team.php app/Services/PdfTextExtractor.php database/factories/AgentKnowledgeFactory.php tests/Unit/Services/PdfTextExtractorTest.php tests/Feature/HasTeamScopeTest.php
git commit -m "feat: AgentKnowledge model, pivot, and PdfTextExtractor"
```

---

### Task 2: `AgentKnowledgeController` — upload, list, delete

**Files:**
- Create: `app/Http/Controllers/AgentKnowledgeController.php`
- Create: `resources/views/agent-knowledge/index.blade.php`
- Create: `resources/views/agent-knowledge/create.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AgentKnowledgeControllerTest.php`

**Interfaces:**
- Consumes: `AgentKnowledge`, `PdfTextExtractor` (Task 1).
- Produces: named routes `agent-knowledge.index`, `agent-knowledge.create`, `agent-knowledge.store`, `agent-knowledge.destroy`. Task 3's agent create/edit forms link into `agent-knowledge.create` and read from the team's `agentKnowledge()` list to build the documents multi-select.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AgentKnowledgeControllerTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AgentKnowledgeControllerTest`
Expected: FAIL — no routes registered yet.

- [ ] **Step 3: Write `AgentKnowledgeController`**

Create `app/Http/Controllers/AgentKnowledgeController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\AgentKnowledge;
use App\Services\PdfTextExtractor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Team-scoped CRUD for knowledge documents -- create and delete only, no
 * update. An outdated document gets deleted and re-uploaded rather than
 * edited in place, so "what does the agent actually know" stays auditable
 * (a document's content never silently changes under an agent already
 * using it) -- see the design spec's §3 for the full reasoning.
 */
class AgentKnowledgeController extends Controller
{
    private const MAX_CONTENT_LENGTH = 50_000;

    public function __construct(private readonly PdfTextExtractor $pdfTextExtractor)
    {
    }

    public function index(Request $request): View
    {
        $documents = $request->user()->currentTeam
            ? $request->user()->currentTeam->agentKnowledge()->latest()->get()
            : collect();

        return view('agent-knowledge.index', compact('documents'));
    }

    public function create(): View
    {
        return view('agent-knowledge.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'input_mode' => ['required', 'in:text,file'],
            'content' => ['required_if:input_mode,text', 'nullable', 'string'],
            'file' => [
                'required_if:input_mode,file',
                'nullable',
                'file',
                'max:5120', // 5MB, in kilobytes
                'mimes:txt,md,pdf',
            ],
        ]);

        abort_unless($request->user()->currentTeam, 403, 'You need a team before uploading a document.');

        if ($validated['input_mode'] === 'text') {
            $content = $validated['content'];
            $sourceType = 'pasted';
            $originalFilename = null;
        } else {
            $file = $request->file('file');
            $originalFilename = $file->getClientOriginalName();

            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                try {
                    $content = $this->pdfTextExtractor->extract($file);
                } catch (\RuntimeException $e) {
                    return back()->withErrors(['file' => $e->getMessage()])->withInput();
                }
                $sourceType = 'pdf';
            } else {
                $content = file_get_contents($file->getRealPath());
                $sourceType = 'text_file';
            }
        }

        $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);

        $request->user()->currentTeam->agentKnowledge()->create([
            'title' => $validated['title'],
            'content' => $content,
            'source_type' => $sourceType,
            'original_filename' => $originalFilename,
        ]);

        return redirect()->route('agent-knowledge.index');
    }

    public function destroy(Request $request, AgentKnowledge $agentKnowledge): RedirectResponse
    {
        $agentKnowledge->delete();

        return redirect()->route('agent-knowledge.index');
    }
}
```

Note: `destroy()` relies on `HasTeamScope`'s global scope for the cross-team 404 (implicit route-model binding on `{agentKnowledge}` makes another team's row invisible before this method body ever runs) — matching every other team-scoped controller's already-proven pattern in this codebase, no explicit check needed.

- [ ] **Step 4: Register routes**

Modify `routes/web.php` — add `use App\Http\Controllers\AgentKnowledgeController;` to the imports, and inside the `auth:sanctum` group, alongside the `agents` resource route:

```php
    Route::resource('agent-knowledge', AgentKnowledgeController::class)->only(['index', 'create', 'store', 'destroy']);
```

- [ ] **Step 5: Write the views**

Create `resources/views/agent-knowledge/index.blade.php`:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Knowledge Documents</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">Upload reference material and assign it to agents so their replies can be grounded in it.</p>
            </div>
            <a href="{{ route('agent-knowledge.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Document
            </a>
        </div>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            @if($documents->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No documents yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Upload one to start grounding your agents' replies.</p>
                    <a href="{{ route('agent-knowledge.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        Upload your first document
                    </a>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Title</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Source</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($documents as $doc)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:var(--text-primary);">{{ $doc->title }}</td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $doc->original_filename ?? 'Pasted text' }}</td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <form method="POST" action="{{ route('agent-knowledge.destroy', $doc) }}" style="display:inline;" onsubmit="return confirm('Delete this document? Any agents using it will lose access to it.');">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" style="background:none;border:none;color:#f87171;font-size:0.75rem;font-weight:700;cursor:pointer;">Delete</button>
                                </form>
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>
    </div>
</x-app-layout>
```

Create `resources/views/agent-knowledge/create.blade.php`:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">New Document</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agent-knowledge.store') }}" enctype="multipart/form-data" x-data="{ mode: 'text' }">
            @csrf

            <div style="margin-bottom:1rem;">
                <x-label for="title" value="Title" />
                <x-input id="title" name="title" type="text" class="mt-1 block w-full" value="{{ old('title') }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;display:flex;gap:1rem;">
                <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="radio" name="input_mode" value="text" x-model="mode" checked />
                    Paste text
                </label>
                <label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="radio" name="input_mode" value="file" x-model="mode" />
                    Upload a file (.txt, .md, .pdf)
                </label>
            </div>

            <div x-show="mode === 'text'" style="margin-bottom:1.5rem;">
                <x-label for="content" value="Content" />
                <textarea id="content" name="content" rows="10" style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('content') }}</textarea>
            </div>

            <div x-show="mode === 'file'" style="margin-bottom:1.5rem;">
                <x-label for="file" value="File" />
                <input id="file" name="file" type="file" accept=".txt,.md,.pdf" style="margin-top:0.25rem;display:block;width:100%;color:var(--text-secondary);font-size:0.85rem;" />
                <p style="font-size:0.72rem;color:var(--text-muted);margin:0.35rem 0 0;">Max 5MB. Scanned/image-only PDFs with no real text layer will be rejected.</p>
            </div>

            <x-button>Upload Document</x-button>
        </form>
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentKnowledgeControllerTest`
Expected: PASS, all 9 tests.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AgentKnowledgeController.php resources/views/agent-knowledge routes/web.php tests/Feature/AgentKnowledgeControllerTest.php
git commit -m "feat: agent-knowledge document upload, list, delete"
```

---

### Task 3: Assign documents to agents (create/edit forms + `AgentController`)

**Files:**
- Modify: `app/Http/Controllers/AgentController.php`
- Modify: `resources/views/agents/create.blade.php`
- Modify: `resources/views/agents/edit.blade.php`
- Modify: `tests/Feature/AgentControllerTest.php`

**Interfaces:**
- Consumes: `AgentKnowledge`, `Agent::knowledge()`, `Team::agentKnowledge()` (Task 1); `agent-knowledge.create` route (Task 2, linked from both forms as a "manage documents" shortcut).

- [ ] **Step 1: Write the failing test**

Modify `tests/Feature/AgentControllerTest.php` — add this test (matches the existing `test_user_can_create_an_agent_with_assigned_skills` test's shape exactly, for knowledge instead of skills):

```php
    public function test_user_can_create_an_agent_with_assigned_knowledge(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $doc = \App\Models\AgentKnowledge::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->post('/agents', [
            'name' => 'Support Bot',
            'system_prompt' => 'You are a support agent.',
            'model' => 'claude-sonnet-4-6',
            'knowledge' => [$doc->id],
        ]);

        $agent = Agent::where('name', 'Support Bot')->firstOrFail();
        $this->assertTrue($agent->knowledge->contains($doc));
        $response->assertRedirect(route('agents.show', $agent));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=test_user_can_create_an_agent_with_assigned_knowledge`
Expected: FAIL — `AgentController::store()` doesn't handle a `knowledge` input yet.

- [ ] **Step 3: Update `AgentController`**

Modify `app/Http/Controllers/AgentController.php`:

In `create()`, also pass the team's documents:

```php
    public function create(): View
    {
        $skills = AgentSkill::orderBy('name')->get();
        $knowledge = auth()->user()->currentTeam
            ? auth()->user()->currentTeam->agentKnowledge()->latest()->get()
            : collect();

        return view('agents.create', compact('skills', 'knowledge'));
    }
```

In `store()`, add validation and sync, alongside the existing `skills` handling:

```php
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer', 'exists:agent_skills,id'],
            'knowledge' => ['nullable', 'array'],
            'knowledge.*' => ['integer', 'exists:agent_knowledge,id'],
        ]);
```

(add the two `knowledge`/`knowledge.*` lines to the existing validation array), then after the existing `$agent->skills()->sync($validated['skills'] ?? []);` line:

```php
        $agent->knowledge()->sync($validated['knowledge'] ?? []);
```

Apply the identical three changes (view's `$knowledge` variable, validation rules, sync call) to `edit()` and `update()`.

- [ ] **Step 4: Update the views**

In `resources/views/agents/create.blade.php`, directly after the existing skills multi-select block (`@if($skills->isNotEmpty()) ... @endif`), add:

```blade
            @if($knowledge->isNotEmpty())
            <div style="margin-bottom:1.5rem;">
                <x-label value="Knowledge Documents" />
                <div style="margin-top:0.5rem;display:flex;flex-direction:column;gap:0.4rem;">
                    @foreach($knowledge as $doc)
                    <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.8rem;color:var(--text-secondary);">
                        <input type="checkbox" name="knowledge[]" value="{{ $doc->id }}" {{ in_array($doc->id, old('knowledge', [])) ? 'checked' : '' }} />
                        {{ $doc->title }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif
            <p style="font-size:0.72rem;color:var(--text-muted);margin:-1rem 0 1.5rem;">
                <a href="{{ route('agent-knowledge.create') }}" style="color:var(--text-muted);">+ Upload a new document</a>
            </p>
```

Apply the identical block to `resources/views/agents/edit.blade.php`, using `$agent->knowledge->contains($doc->id)` instead of `in_array($doc->id, old('knowledge', []))` for the `checked` condition, matching exactly how the existing skills block already differs between `create.blade.php` and `edit.blade.php`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentControllerTest`
Expected: PASS, all tests including the new one.

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AgentController.php resources/views/agents/create.blade.php resources/views/agents/edit.blade.php tests/Feature/AgentControllerTest.php
git commit -m "feat: assign knowledge documents to agents"
```

---

### Task 4: Retrieval — `AgentChatService` grounds replies in assigned documents

**Files:**
- Modify: `app/Services/AgentChatService.php`
- Modify: `tests/Unit/Services/AgentChatServiceTest.php`

**Interfaces:**
- Consumes: `Agent::knowledge()` (Task 1).
- Produces: no new public interface — `chat()`'s signature and return shape are unchanged from the prior streaming work; only the `system` value sent to Anthropic changes when the agent has matching assigned documents.

- [ ] **Step 1: Write the failing tests**

Modify `tests/Unit/Services/AgentChatServiceTest.php` — add these two tests:

```php
    public function test_a_matching_assigned_document_is_injected_into_the_system_prompt(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody(['Sure, 20 days.']), 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['system_prompt' => 'You are a helpful HR assistant.']);
        \App\Models\AgentKnowledge::factory()->for($user->currentTeam)->create([
            'title' => 'Leave Policy',
            'content' => 'Employees get 20 days of annual leave.',
        ])->agents()->attach($agent);
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        app(AgentChatService::class)->chat($conversation, 'How many leave days do I get?', $user->id);

        Http::assertSent(function ($request) {
            return str_contains($request['system'], 'Employees get 20 days of annual leave.')
                && str_contains($request['system'], 'You are a helpful HR assistant.');
        });
    }

    public function test_an_agent_with_no_assigned_documents_sends_the_system_prompt_unchanged(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->sseBody(['Hi!']), 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['system_prompt' => 'You are a helpful assistant.']);
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        app(AgentChatService::class)->chat($conversation, 'Hi', $user->id);

        Http::assertSent(fn ($request) => $request['system'] === 'You are a helpful assistant.');
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="test_a_matching_assigned_document_is_injected_into_the_system_prompt|test_an_agent_with_no_assigned_documents_sends_the_system_prompt_unchanged"`
Expected: FAIL — `chat()` doesn't build a dynamic system prompt yet.

- [ ] **Step 3: Add retrieval to `AgentChatService`**

Modify `app/Services/AgentChatService.php` — add `use App\Models\AgentKnowledge;` and `use Illuminate\Support\Facades\DB;` to the imports, and replace the `$history = ...` block in `chat()` with:

```php
        $systemPrompt = $this->buildSystemPrompt($agent, $userMessage);

        $history = $conversation->messages()
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->toArray();

        $result = $this->streamAnthropic([
            'model' => $agent->model,
            'max_tokens' => 1024,
            'system' => $systemPrompt,
            'messages' => $history,
        ], $onChunk);
```

(replacing the `'system' => $agent->system_prompt,` line inside the existing `streamAnthropic([...])` call with `'system' => $systemPrompt,`, and adding the `$systemPrompt = $this->buildSystemPrompt(...)` line before it).

Add the new private method, placed after `chat()` and before `streamAnthropic()`:

```php
    /**
     * Appends up to 3 assigned AgentKnowledge documents whose title or
     * content matches a word from the user's message, ranked by number of
     * distinct matched words. Retrieval failure is never a chat failure --
     * if the search itself throws, log it and fall back to the agent's
     * plain system_prompt rather than blocking the conversation.
     */
    private function buildSystemPrompt(Agent $agent, string $userMessage): string
    {
        try {
            $words = array_unique(array_filter(
                array_map('strtolower', preg_split('/\s+/', trim($userMessage))),
                fn ($w) => mb_strlen($w) >= 3
            ));

            if (empty($words)) {
                return $agent->system_prompt;
            }

            // $agent->knowledge() is itself directly query-buildable (all
            // Eloquent relations proxy query-builder methods) -- calling
            // ->newQuery() here would have been a real bug: it returns a
            // fresh, unrelated query against AgentKnowledge's base table,
            // silently dropping the BelongsToMany pivot join/filtering and
            // searching every team's documents, not just this agent's
            // assigned ones. Caught in this plan's own self-review, not
            // written this way originally -- worth the explicit note so a
            // future edit doesn't reintroduce it.
            $matches = $agent->knowledge()
                ->where(function ($q) use ($words) {
                    foreach ($words as $word) {
                        $q->orWhere(fn ($sub) => $sub
                            ->whereRaw('LOWER(agent_knowledge.title) LIKE ?', ['%'.$word.'%'])
                            ->orWhereRaw('LOWER(agent_knowledge.content) LIKE ?', ['%'.$word.'%']));
                    }
                })
                ->get()
                ->map(function (AgentKnowledge $doc) use ($words) {
                    $haystack = strtolower($doc->title.' '.$doc->content);
                    $doc->matchCount = collect($words)->filter(fn ($w) => str_contains($haystack, $w))->count();

                    return $doc;
                })
                ->filter(fn ($doc) => $doc->matchCount > 0)
                ->sortByDesc('matchCount')
                ->take(3);

            if ($matches->isEmpty()) {
                return $agent->system_prompt;
            }

            $excerpts = $matches->map(fn ($doc) => "---\n{$doc->title}\n{$doc->content}\n---")->implode("\n");

            return $agent->system_prompt."\n\nRelevant reference material:\n\n".$excerpts;
        } catch (\Throwable $e) {
            Log::warning('AgentKnowledge retrieval failed', ['agent' => $agent->slug, 'error' => $e->getMessage()]);

            return $agent->system_prompt;
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentChatServiceTest`
Expected: PASS, all 6 tests (4 existing + 2 new).

- [ ] **Step 5: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, all tests (this is the point where the whole feature exists together — model, upload UI, assignment, retrieval).

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/AgentChatService.php tests/Unit/Services/AgentChatServiceTest.php
git commit -m "feat: ground agent replies in assigned knowledge documents"
```

---

### Task 5: Final verification + wiki.md update

**Files:**
- Modify: `wiki.md`

- [ ] **Step 1: Run the full backend suite**

Run: `php artisan test --compact`
Expected: PASS, all tests (report the real total — this plan adds roughly 15 new tests: 1 in Task 1's `HasTeamScopeTest`, 1 in `PdfTextExtractorTest`, 9 in `AgentKnowledgeControllerTest`, 1 in `AgentControllerTest`, 2 in `AgentChatServiceTest`; confirm the actual count from the output rather than assuming).

- [ ] **Step 2: Pint, repo-wide**

Run: `vendor/bin/pint --format agent`
Expected: no changes needed beyond what earlier tasks already fixed.

- [ ] **Step 3: Verification, honestly scoped**

Same environment limitation as the prior two AI-domain builds this session: this environment's browser-preview launcher forces `SESSION_DRIVER=array`, so interactive login-gated visual verification isn't achievable here. The real test suite (Step 1) is what actually proves this feature works, including the two most failure-prone pieces: PDF extraction (both the mocked-success and genuine-failure paths) and retrieval injection (asserting the real HTTP request body sent toward Anthropic, not just component state). Do not fabricate a "verified live" claim.

- [ ] **Step 4: Update `wiki.md`**

Read §3 (Domain Entities), §4 (What's Not Built Yet), and §7 (Roadmap)'s current text before editing.

Update:
- §3: add an `AgentKnowledge` row to the entity table (table `agent_knowledge`, key fields `team_id`/`title`/`content`/`source_type`/`original_filename`, team-scoped like `Agent`, many-to-many with `Agent` via `agent_agent_knowledge` matching `AgentSkill`'s pattern).
- §4: remove the "Knowledge-base upload / document grounding per agent (no `AgentKnowledge` model exists yet...)" bullet — it's no longer an accurate gap description.
- §7: add a closed `- [x]` roadmap entry summarizing what was built and the real infrastructure findings that shaped v1's scope (no embeddings/vector anywhere, Postgres-vs-SQLite driving the plain-`LIKE` decision), referencing the spec/plan file paths.
- Bump the frontmatter `version:` (currently `0.11.1`) and add a Change Log row with today's date: what was found (no embedding infra, no PDF library, the SQLite/Postgres search-portability constraint), what was built, the real test count from Step 1, and the honest verification-scope note from Step 3.

- [ ] **Step 5: Commit**

```bash
git add wiki.md
git commit -m "docs: update wiki.md for AgentKnowledge document grounding"
```

Do not push without explicit confirmation — matches this platform's established pattern.
