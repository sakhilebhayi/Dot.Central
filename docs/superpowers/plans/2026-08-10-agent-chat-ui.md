# Real Agent Creation + Chat UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the actual, working UI for Dot.Central's AI-agent command centre — create an agent, browse/reopen past conversations with it, and chat — using the real, already-working `AgentChatService`. Today none of this is reachable: every dashboard action link is `href="#"`, and `AgentChat`'s own `render()` points at a Blade view that doesn't exist.

**Architecture:** `Agent` becomes team-scoped (`team_id` + `HasTeamScope`, mirroring `ControlRoom` exactly). A plain `AgentController` (matching `ControlRoomController`'s established convention — no Livewire, simple form CRUD) handles create/list/edit/show. A new, small `ConversationController` creates a conversation and hands off to the chat screen. The existing `AgentChat` Livewire component gets its missing view built and its `mount()` signature changed to always take an explicit `Conversation`, removing the ambiguous `firstOrCreate`-based `startOrContinue()` fallback that would otherwise always resolve to the same (oldest) conversation regardless of which one a user picked.

**Tech Stack:** Laravel 12, Livewire 3, Alpine.js, Tailwind (CDN, `corePlugins.preflight: false`, no build step for the authenticated shell), PostgreSQL, PHPUnit, Laravel Pint, Laravel Boost (use `search-docs` before Livewire/Laravel API questions).

## Global Constraints

- Match `ControlRoomController`'s exact convention for all new CRUD: plain resource-style controller, no Livewire, `abort_unless($request->user()->currentTeam, 403, ...)` guard on create, team-scoping done via `HasTeamScope` (not manual `where('team_id', ...)` calls).
- `Agent` gets `HasTeamScope` (confirmed by reading `app/Models/Concerns/HasTeamScope.php` directly: scopes reads to `Auth::user()->currentTeam->id` when authenticated with a current team — the exact trait `ControlRoom` already uses, not a new one).
- `Conversation`/`Message`/`AgentUsageLog` are **not** touched by this plan — they already correctly use `HasUserScope`/`HasConversationUserScope` and stay private per-user even though `Agent` becomes team-shared.
- This repo uses `Model::factory()->for(...)->create()` in tests (confirmed via `ControlRoomFactory`/`UserFactory::withPersonalTeam()`), not bare `Model::create()`. Match this in every new test.
- `$fillable` includes the foreign key explicitly even when set via a `HasMany::create()` relation call — confirmed via `ControlRoom::$fillable` containing `'team_id'` despite `$team->controlRooms()->create([...])` setting it automatically. Do the same for `Agent`.
- Match `resources/views/control-rooms/{index,create,edit,show}.blade.php`'s exact inline-style card/table/form convention for all new views: `var(--card-bg)`, `var(--card-border)`, `var(--text-primary)`/`var(--text-secondary)`/`var(--text-muted)`, `var(--divider)`, `'Syne',sans-serif` for headings, `x-app-layout`/`x-validation-errors`/`x-label`/`x-input`/`x-button`/`x-danger-button` components. **Not** the separate ink/gold/cyan/Sora+IBM-Plex tokens used on the public marketing pages (welcome/auth/error) — confirmed those are a deliberately distinct system for a different audience.
- The AI-agent domain already has its own established accent color in `dashboard.blade.php`'s existing (currently dead) markup: a rose/red gradient (`linear-gradient(135deg,#e11d48,#9f1239)`), distinct from the mining-dispatch domain's cyan (`#7dd3fc`). New Agent-domain UI keeps this rose accent for primary actions, matching what's already there stylistically rather than introducing a third color.
- `dashboard.blade.php`'s "Add Skill" link (`resources/views/dashboard.blade.php` around line 178) stays `href="#"` — `AgentSkill` *management* (creating/editing skill tags) is explicitly out of scope per the design spec, only *assigning* existing skills to an agent is in scope. Do not silently wire this one up; it's excluded on purpose.
- Per this repo's `CLAUDE.md` Laravel Boost guidelines: do not create verification scripts or use `tinker` for anything a test already proves. The one exception in this plan (Task 1, Step 0) is a genuine pre-migration safety check with no test equivalent — checking real row counts in a database a migration is about to alter, learned from a real mistake made earlier this same review session (running `migrate:fresh` against a real dev DB without checking first).
- Run `vendor/bin/pint --dirty --format agent` after every task before committing.
- Full spec: `docs/superpowers/specs/2026-08-10-agent-chat-ui-design.md`. Read it before starting if anything below is ambiguous.

---

### Task 1: `team_id` on `agents`, `HasTeamScope`, `Team::agents()`, factories, scope test

**Files:**
- Create: `database/migrations/2026_08_10_000001_add_team_id_to_agents_table.php`
- Modify: `app/Models/Agent.php`
- Modify: `app/Models/Team.php`
- Create: `database/factories/AgentFactory.php`
- Create: `database/factories/AgentSkillFactory.php`
- Modify: `tests/Feature/HasTeamScopeTest.php`

**Interfaces:**
- Produces: `Agent::$fillable` includes `'team_id'`; `Agent` uses `HasTeamScope` + `HasFactory`; `Team::agents(): HasMany`; `AgentFactory` (default state creates its own `Team` via `Team::factory()`); `AgentSkillFactory`.

- [ ] **Step 0: Safety check before altering a live table**

Before writing or running the migration, check whether any environment this plan might run against already has `agents` rows that would violate a `NOT NULL` `team_id`:

Run: `php artisan tinker --execute='echo App\Models\Agent::count();'`

If the count is `0` (expected — confirmed via this session's own audit that no factory or seeder has ever created an `Agent` row), proceed with a `NOT NULL` foreign key in Step 1. If the count is non-zero, stop and make `team_id` nullable instead (`$table->foreignId('team_id')->nullable()->after('id')->constrained()->cascadeOnDelete();`), and note in the migration's own doc comment why — do not silently assume zero rows on a database you have not checked, this exact mistake was made once already this session against a different database.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_08_10_000001_add_team_id_to_agents_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->foreignId('team_id')->after('id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agents', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};
```

- [ ] **Step 2: Run the migration**

Run: `php artisan migrate`
Expected: runs without error (given Step 0 confirmed zero existing rows).

- [ ] **Step 3: Apply `HasTeamScope` to `Agent`, add `team_id` to `$fillable`**

Modify `app/Models/Agent.php`:

```php
<?php

namespace App\Models;

use App\Models\Concerns\HasTeamScope;
use Database\Factories\AgentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends Model
{
    /** @use HasFactory<AgentFactory> */
    use HasFactory, HasTeamScope;

    protected $fillable = [
        'team_id', 'name', 'slug', 'description', 'system_prompt',
        'model', 'avatar_path', 'is_active', 'capabilities',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'capabilities' => 'array',
    ];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(AgentSkill::class, 'agent_agent_skill');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(Conversation::class);
    }
}
```

- [ ] **Step 4: Add `Team::agents()`**

Modify `app/Models/Team.php` — add this method inside the class, next to the existing `controlRooms()` method:

```php
    /**
     * The AI agents owned by this team.
     */
    public function agents(): HasMany
    {
        return $this->hasMany(Agent::class);
    }
```

(Confirm `use Illuminate\Database\Eloquent\Relations\HasMany;` is already imported — it is, `controlRooms()` already uses it.)

- [ ] **Step 5: Write `AgentFactory`**

Create `database/factories/AgentFactory.php`:

```php
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
```

- [ ] **Step 6: Write `AgentSkillFactory`**

Create `database/factories/AgentSkillFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\AgentSkill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AgentSkill>
 */
class AgentSkillFactory extends Factory
{
    public function definition(): array
    {
        $name = ucfirst(fake()->unique()->word());

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'icon' => 'bolt',
        ];
    }
}
```

`AgentSkill` itself needs `HasFactory` for this to work — check `app/Models/AgentSkill.php`; if it doesn't already `use HasFactory`, add it (it currently only has `belongsToMany`, no `HasFactory` trait).

- [ ] **Step 7: Prove the scope is load-bearing for `Agent` too**

Modify `tests/Feature/HasTeamScopeTest.php` — add this method to the existing class (same file, same mechanism as the existing `ControlRoom` test, just a second model):

```php
    public function test_scope_alone_blocks_cross_team_agent_access_even_without_an_explicit_where(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $outsider = User::factory()->withPersonalTeam()->create();

        $agent = Agent::factory()->for($owner->currentTeam)->create(['name' => 'Owner Agent']);

        $this->actingAs($outsider);

        $this->assertNull(Agent::find($agent->id));
        $this->assertSame(0, Agent::query()->count());

        $this->actingAs($owner);

        $this->assertNotNull(Agent::find($agent->id));
        $this->assertSame(1, Agent::query()->count());
    }
```

Add `use App\Models\Agent;` to the top of the file alongside the existing `use App\Models\ControlRoom;`.

- [ ] **Step 8: Run the tests**

Run: `php artisan test --compact --filter=HasTeamScopeTest`
Expected: PASS, 2 tests.

- [ ] **Step 9: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_10_000001_add_team_id_to_agents_table.php app/Models/Agent.php app/Models/Team.php app/Models/AgentSkill.php database/factories/AgentFactory.php database/factories/AgentSkillFactory.php tests/Feature/HasTeamScopeTest.php
git commit -m "feat: team-scope Agent, add Agent/AgentSkill factories"
```

---

### Task 2: `ConversationFactory` + `MessageFactory`

**Files:**
- Create: `database/factories/ConversationFactory.php`
- Create: `database/factories/MessageFactory.php`
- Test: `tests/Unit/Models/ConversationFactoryTest.php`

**Interfaces:**
- Consumes: `Agent` (Task 1), `User::factory()`.
- Produces: `ConversationFactory` (default state creates its own `User` + `Agent`), `MessageFactory` (default state creates its own `Conversation`).

- [ ] **Step 1: Check `Conversation`/`Message` have `HasFactory`**

Read `app/Models/Conversation.php` and `app/Models/Message.php` — neither currently declares `use HasFactory`. Add it to both, matching `ControlRoom`'s `/** @use HasFactory<...Factory> */` doc-comment convention.

- [ ] **Step 2: Write `ConversationFactory`**

Create `database/factories/ConversationFactory.php`:

```php
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
```

- [ ] **Step 3: Write `MessageFactory`**

Create `database/factories/MessageFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Message>
 */
class MessageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => fake()->randomElement(['user', 'assistant']),
            'content' => fake()->sentence(),
            'tokens_used' => null,
        ];
    }
}
```

- [ ] **Step 4: Write the failing test**

Create `tests/Unit/Models/ConversationFactoryTest.php`:

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter=ConversationFactoryTest`
Expected: PASS, 2 tests. (Written straightforwardly enough not to need a separate fail-first step — the factories either compile and work or the whole test errors immediately, which is the same signal.)

- [ ] **Step 6: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Conversation.php app/Models/Message.php database/factories/ConversationFactory.php database/factories/MessageFactory.php tests/Unit/Models/ConversationFactoryTest.php
git commit -m "feat: add Conversation and Message factories"
```

---

### Task 3: `ConversationController` (store/show) + routes + tests

Built before `AgentController` deliberately: `AgentController`'s `show` view (Task 4) links into `agents.conversations.store`/`agents.chat`, so those routes need to already exist when that view is written and tested — not the other way around. `ConversationController` itself has no dependency on `AgentController` at all, only on `Agent`/`Conversation` (Task 1-2), so this ordering has no cost.

**Files:**
- Create: `app/Http/Controllers/ConversationController.php`
- Modify: `routes/web.php`
- Create: `resources/views/agents/chat.blade.php`
- Test: `tests/Feature/ConversationControllerTest.php`

**Interfaces:**
- Consumes: `Agent`, `Conversation` (Tasks 1-2).
- Produces: named routes `agents.conversations.store`, `agents.chat`. `agents.chat`'s view renders `<livewire:agents.agent-chat :agent="$agent" :conversation="$conversation" />` — Task 5 depends on this route existing to be reachable, and on `AgentChat::mount()` accepting `(Agent $agent, Conversation $conversation)`. Task 4's `agents.show` view links into both these routes.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ConversationControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_creates_a_conversation_and_redirects_to_the_chat_screen(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();

        $response = $this->actingAs($user)->post(route('agents.conversations.store', $agent));

        $conversation = Conversation::where('agent_id', $agent->id)->where('user_id', $user->id)->firstOrFail();
        $response->assertRedirect(route('agents.chat', [$agent, $conversation]));
    }

    public function test_show_renders_the_chat_screen_for_an_existing_conversation(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        $this->actingAs($user)
            ->get(route('agents.chat', [$agent, $conversation]))
            ->assertOk()
            ->assertSee('Support Bot');
    }

    public function test_a_conversation_url_that_does_not_match_its_agent_404s(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agentA = Agent::factory()->for($user->currentTeam)->create();
        $agentB = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agentA)->create();

        $this->actingAs($user)
            ->get(route('agents.chat', [$agentB, $conversation]))
            ->assertNotFound();
    }

    public function test_another_users_conversation_404s(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create();
        $conversation = Conversation::factory()->for($owner)->for($agent)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('agents.chat', [$agent, $conversation]))
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=ConversationControllerTest`
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Write `ConversationController`**

Create `app/Http/Controllers/ConversationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\Conversation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * A conversation is always created explicitly (store) and opened by ID
 * (show) — no "guess which one is most recent" logic anywhere in this
 * path. See AgentChat::mount() (Task 5), which relies on always
 * receiving a real Conversation for exactly this reason.
 */
class ConversationController extends Controller
{
    public function store(Request $request, Agent $agent): RedirectResponse
    {
        $conversation = $agent->conversations()->create([
            'user_id' => $request->user()->id,
            'title' => 'Chat with '.$agent->name.' — '.now()->format('M j, H:i'),
        ]);

        return redirect()->route('agents.chat', [$agent, $conversation]);
    }

    public function show(Agent $agent, Conversation $conversation): View
    {
        abort_unless($conversation->agent_id === $agent->id, 404);

        return view('agents.chat', compact('agent', 'conversation'));
    }
}
```

- [ ] **Step 4: Register routes**

Modify `routes/web.php` — add `use App\Http\Controllers\ConversationController;`, and inside the `auth:sanctum` group:

```php
    Route::post('agents/{agent}/conversations', [ConversationController::class, 'store'])
        ->name('agents.conversations.store');
    Route::get('agents/{agent}/chat/{conversation}', [ConversationController::class, 'show'])
        ->name('agents.chat');
```

- [ ] **Step 5: Write a placeholder `agents/chat.blade.php`**

This task doesn't finish the chat screen (that's Task 5) — it only needs to prove the route/controller/authorization logic works. Create a minimal `resources/views/agents/chat.blade.php` for now:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);">{{ $agent->name }}</h1>
        <p style="color:var(--text-muted);font-size:0.8rem;">Chat screen — finished in Task 5.</p>
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=ConversationControllerTest`
Expected: PASS, all 4 tests.

- [ ] **Step 7: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/ConversationController.php resources/views/agents/chat.blade.php routes/web.php tests/Feature/ConversationControllerTest.php
git commit -m "feat: create and open conversations"
```

---

### Task 4: `AgentController` (index/create/store/edit/update/show) + views + tests

**Files:**
- Create: `app/Http/Controllers/AgentController.php`
- Create: `resources/views/agents/index.blade.php`
- Create: `resources/views/agents/create.blade.php`
- Create: `resources/views/agents/edit.blade.php`
- Create: `resources/views/agents/show.blade.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/AgentControllerTest.php`

**Interfaces:**
- Consumes: `Agent`, `AgentSkill`, `Team::agents()` (Task 1); `agents.conversations.store`/`agents.chat` routes (Task 3, already exist by this point).
- Produces: named routes `agents.index`, `agents.create`, `agents.store`, `agents.edit`, `agents.update`, `agents.show`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/AgentControllerTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Agent;
use App\Models\AgentSkill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature coverage for the AI-agent domain's CRUD — mirrors
 * ControlRoomTest's shape exactly, since AgentController follows the
 * same plain-controller, team-scoped convention.
 */
class AgentControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/agents')->assertRedirect('/login');
    }

    public function test_index_shows_the_current_teams_agents(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);

        $this->actingAs($user)
            ->get('/agents')
            ->assertOk()
            ->assertSee('Support Bot');
    }

    public function test_user_can_create_an_agent_with_assigned_skills(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $skill = AgentSkill::factory()->create(['name' => 'Research']);

        $response = $this->actingAs($user)->post('/agents', [
            'name' => 'Research Assistant',
            'description' => 'Helps with research tasks.',
            'system_prompt' => 'You are a research assistant.',
            'model' => 'claude-sonnet-4-6',
            'skills' => [$skill->id],
        ]);

        $this->assertDatabaseHas('agents', [
            'name' => 'Research Assistant',
            'team_id' => $user->currentTeam->id,
        ]);

        $agent = Agent::where('name', 'Research Assistant')->firstOrFail();
        $this->assertTrue($agent->skills->contains($skill));
        $response->assertRedirect(route('agents.show', $agent));
    }

    public function test_creating_an_agent_without_a_current_team_is_forbidden(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $this->actingAs($user)->post('/agents', [
            'name' => 'Orphan Agent',
            'system_prompt' => 'You are an assistant.',
        ])->assertForbidden();
    }

    public function test_user_can_update_an_agent_including_deactivating_it(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['is_active' => true]);

        $this->actingAs($user)->put(route('agents.update', $agent), [
            'name' => 'Renamed Agent',
            'system_prompt' => 'You are still an assistant.',
            'model' => 'claude-sonnet-4-6',
            'is_active' => false,
        ]);

        $agent->refresh();
        $this->assertSame('Renamed Agent', $agent->name);
        $this->assertFalse($agent->is_active);
    }

    public function test_show_page_lists_this_users_past_conversations_with_the_agent(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);
        \App\Models\Conversation::factory()->for($user)->for($agent)->create(['title' => 'Debugging session']);

        $this->actingAs($user)
            ->get(route('agents.show', $agent))
            ->assertOk()
            ->assertSee('Support Bot')
            ->assertSee('Debugging session');
    }

    /**
     * As of HasTeamScope, an agent belonging to another team is invisible
     * to implicit route-model binding before any explicit check runs —
     * 404, not 403, matching ControlRoomTest's identical assertion for
     * the same reason.
     */
    public function test_a_user_cannot_view_an_agent_belonging_to_another_team(): void
    {
        $owner = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($owner->currentTeam)->create();

        $outsider = User::factory()->withPersonalTeam()->create();

        $this->actingAs($outsider)
            ->get(route('agents.show', $agent))
            ->assertNotFound();
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=AgentControllerTest`
Expected: FAIL — no routes registered yet.

- [ ] **Step 3: Write `AgentController`**

Create `app/Http/Controllers/AgentController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Agent;
use App\Models\AgentSkill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Basic CRUD for AI agents — team-scoped, matching ControlRoomController's
 * established convention exactly (plain controller, no Livewire; Livewire
 * is reserved for the chat screen itself, see AgentChat).
 */
class AgentController extends Controller
{
    public function index(Request $request): View
    {
        $agents = $request->user()->currentTeam
            ? $request->user()->currentTeam->agents()->withCount('conversations')->latest()->get()
            : collect();

        return view('agents.index', compact('agents'));
    }

    public function create(): View
    {
        $skills = AgentSkill::orderBy('name')->get();

        return view('agents.create', compact('skills'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer', 'exists:agent_skills,id'],
        ]);

        abort_unless($request->user()->currentTeam, 403, 'You need a team before creating an agent.');

        $agent = $request->user()->currentTeam->agents()->create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']).'-'.Str::random(6),
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'model' => $validated['model'] ?? 'claude-sonnet-4-6',
        ]);

        $agent->skills()->sync($validated['skills'] ?? []);

        return redirect()->route('agents.show', $agent);
    }

    public function show(Request $request, Agent $agent): View
    {
        $agent->load('skills');
        $conversations = $agent->conversations()->where('user_id', $request->user()->id)->latest()->get();

        return view('agents.show', compact('agent', 'conversations'));
    }

    public function edit(Agent $agent): View
    {
        $agent->load('skills');
        $skills = AgentSkill::orderBy('name')->get();

        return view('agents.edit', compact('agent', 'skills'));
    }

    public function update(Request $request, Agent $agent): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'system_prompt' => ['required', 'string'],
            'model' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'skills' => ['nullable', 'array'],
            'skills.*' => ['integer', 'exists:agent_skills,id'],
        ]);

        $agent->update([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'system_prompt' => $validated['system_prompt'],
            'model' => $validated['model'] ?? 'claude-sonnet-4-6',
            'is_active' => $request->boolean('is_active'),
        ]);

        $agent->skills()->sync($validated['skills'] ?? []);

        return redirect()->route('agents.show', $agent);
    }
}
```

Note: `Conversation::user_id`'s explicit `where` in `show()` is deliberate even though `Conversation` already carries `HasUserScope` (which would filter this automatically) — it's here for readability at the call site, not because the scope needs help. `HasUserScope`'s own global scope still applies underneath regardless.

- [ ] **Step 4: Register routes**

Modify `routes/web.php` — add `use App\Http\Controllers\AgentController;` to the imports, and inside the existing `auth:sanctum` group (alongside `Route::resource('control-rooms', ControlRoomController::class);`):

```php
    Route::resource('agents', AgentController::class)->except(['destroy']);
```

- [ ] **Step 5: Write the views**

Create `resources/views/agents/index.blade.php`:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;">
            <div>
                <h1 style="font-family:'Syne',sans-serif;font-size:1.625rem;font-weight:800;color:var(--text-primary);margin:0 0 0.25rem;">Agents</h1>
                <p style="font-size:0.8rem;color:var(--text-muted);margin:0;">AI-agent command centre — configure and converse with Claude-powered agents.</p>
            </div>
            <a href="{{ route('agents.create') }}" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;text-decoration:none;">
                New Agent
            </a>
        </div>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            @if($agents->isEmpty())
                <div style="padding:3.5rem 1.5rem;text-align:center;">
                    <div style="width:56px;height:56px;border-radius:14px;background:rgba(225,29,72,0.1);display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem;">
                        <span class="material-symbols-rounded" style="font-size:28px;color:#e11d48;">smart_toy</span>
                    </div>
                    <div style="font-family:'Syne',sans-serif;font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:0.5rem;">No agents yet</div>
                    <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1.5rem;">Create your first AI agent to start chatting.</p>
                    <a href="{{ route('agents.create') }}" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
                        <span class="material-symbols-rounded" style="font-size:16px;">add_circle</span>
                        Create your first agent
                    </a>
                </div>
            @else
                <table style="width:100%;border-collapse:collapse;">
                    <thead>
                        <tr style="border-bottom:1px solid var(--divider);">
                            <th style="padding:0.75rem 1.5rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Name</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Status</th>
                            <th style="padding:0.75rem 1rem;text-align:left;font-size:0.62rem;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--text-muted);">Conversations</th>
                            <th style="padding:0.75rem 1.5rem;text-align:right;"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($agents as $agent)
                        <tr style="border-bottom:1px solid var(--divider);">
                            <td style="padding:1rem 1.5rem;font-family:'Syne',sans-serif;font-weight:700;color:var(--text-primary);">{{ $agent->name }}</td>
                            <td style="padding:1rem;">
                                @if($agent->is_active)
                                    <span style="color:#22c55e;font-size:0.75rem;font-weight:700;">Active</span>
                                @else
                                    <span style="color:var(--text-muted);font-size:0.75rem;font-weight:700;">Inactive</span>
                                @endif
                            </td>
                            <td style="padding:1rem;color:var(--text-secondary);font-size:0.8rem;">{{ $agent->conversations_count }}</td>
                            <td style="padding:1rem 1.5rem;text-align:right;">
                                <a href="{{ route('agents.show', $agent) }}" style="color:#fda4af;font-size:0.75rem;font-weight:700;text-decoration:none;">Open →</a>
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

Create `resources/views/agents/create.blade.php`:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">New Agent</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agents.store') }}">
            @csrf

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name') }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="description" value="Description (optional)" />
                <x-input id="description" name="description" type="text" class="mt-1 block w-full" value="{{ old('description') }}" />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="system_prompt" value="System Prompt" />
                <textarea id="system_prompt" name="system_prompt" rows="4" required style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('system_prompt') }}</textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="model" value="Model" />
                <x-input id="model" name="model" type="text" class="mt-1 block w-full" value="{{ old('model', 'claude-sonnet-4-6') }}" />
            </div>

            @if($skills->isNotEmpty())
            <div style="margin-bottom:1.5rem;">
                <x-label value="Skills" />
                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                    @foreach($skills as $skill)
                    <label style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--text-secondary);padding:0.35rem 0.75rem;border-radius:9999px;border:1px solid var(--card-border);">
                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" {{ in_array($skill->id, old('skills', [])) ? 'checked' : '' }} />
                        {{ $skill->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif

            <x-button>Create Agent</x-button>
        </form>
    </div>
</x-app-layout>
```

Create `resources/views/agents/edit.blade.php` (same field set as `create.blade.php`, pre-filled, plus the `is_active` checkbox — matches `control-rooms/edit.blade.php`'s exact pattern):

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;max-width:560px;">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.4rem;font-weight:800;color:var(--text-primary);margin:0 0 1.5rem;">Edit Agent</h1>

        <x-validation-errors class="mb-4" />

        <form method="POST" action="{{ route('agents.update', $agent) }}">
            @csrf
            @method('PUT')

            <div style="margin-bottom:1rem;">
                <x-label for="name" value="Name" />
                <x-input id="name" name="name" type="text" class="mt-1 block w-full" value="{{ old('name', $agent->name) }}" required autofocus />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="description" value="Description (optional)" />
                <x-input id="description" name="description" type="text" class="mt-1 block w-full" value="{{ old('description', $agent->description) }}" />
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="system_prompt" value="System Prompt" />
                <textarea id="system_prompt" name="system_prompt" rows="4" required style="margin-top:0.25rem;display:block;width:100%;border-radius:0.5rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.75rem;font-size:0.85rem;">{{ old('system_prompt', $agent->system_prompt) }}</textarea>
            </div>

            <div style="margin-bottom:1rem;">
                <x-label for="model" value="Model" />
                <x-input id="model" name="model" type="text" class="mt-1 block w-full" value="{{ old('model', $agent->model) }}" />
            </div>

            @if($skills->isNotEmpty())
            <div style="margin-bottom:1rem;">
                <x-label value="Skills" />
                <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                    @foreach($skills as $skill)
                    <label style="display:inline-flex;align-items:center;gap:0.4rem;font-size:0.8rem;color:var(--text-secondary);padding:0.35rem 0.75rem;border-radius:9999px;border:1px solid var(--card-border);">
                        <input type="checkbox" name="skills[]" value="{{ $skill->id }}" {{ $agent->skills->contains($skill->id) ? 'checked' : '' }} />
                        {{ $skill->name }}
                    </label>
                    @endforeach
                </div>
            </div>
            @endif

            <div style="margin-bottom:1.5rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;font-size:0.85rem;color:var(--text-secondary);">
                    <input type="checkbox" name="is_active" value="1" {{ $agent->is_active ? 'checked' : '' }} />
                    Active
                </label>
            </div>

            <x-button>Save Changes</x-button>
        </form>
    </div>
</x-app-layout>
```

Create `resources/views/agents/show.blade.php`:

```blade
<x-app-layout>
    <div style="padding:2rem 2.5rem;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:0.5rem;">
            <h1 style="font-family:'Syne',sans-serif;font-size:1.5rem;font-weight:800;color:var(--text-primary);margin:0;">{{ $agent->name }}</h1>
            <a href="{{ route('agents.edit', $agent) }}" style="font-size:0.75rem;color:var(--text-muted);text-decoration:none;">Edit →</a>
        </div>
        <p style="font-size:0.8rem;color:var(--text-muted);margin:0 0 1rem;">{{ $agent->description ?? 'No description.' }}</p>

        @if($agent->skills->isNotEmpty())
        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-bottom:1.5rem;">
            @foreach($agent->skills as $skill)
            <span style="display:inline-flex;align-items:center;padding:0.3rem 0.7rem;border-radius:9999px;background:rgba(168,85,247,0.12);border:1px solid rgba(168,85,247,0.2);font-size:0.7rem;font-weight:700;color:#d8b4fe;font-family:'Syne',sans-serif;">{{ $skill->name }}</span>
            @endforeach
        </div>
        @endif

        <form method="POST" action="{{ route('agents.conversations.store', $agent) }}" style="margin-bottom:1.5rem;">
            @csrf
            <button type="submit" style="display:inline-flex;align-items:center;gap:0.5rem;padding:0.65rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;border:none;cursor:pointer;">
                <span class="material-symbols-rounded" style="font-size:16px;">add_comment</span>
                New Conversation
            </button>
        </form>

        <div style="background:var(--card-bg);border:1px solid var(--card-border);border-radius:0.875rem;overflow:hidden;">
            <h2 style="font-family:'Syne',sans-serif;font-size:0.95rem;font-weight:700;color:var(--text-primary);margin:0;padding:1.25rem 1.25rem 0.75rem;">Your Conversations</h2>
            @if($conversations->isEmpty())
                <p style="font-size:0.8rem;color:var(--text-muted);padding:0 1.25rem 1.25rem;">No conversations yet — start one above.</p>
            @else
                @foreach($conversations as $conversation)
                <a href="{{ route('agents.chat', [$agent, $conversation]) }}" style="display:block;padding:0.85rem 1.25rem;border-top:1px solid var(--divider);text-decoration:none;">
                    <div style="font-size:0.82rem;font-weight:600;color:var(--text-primary);">{{ $conversation->title }}</div>
                    <div style="font-size:0.7rem;color:var(--text-muted);margin-top:0.15rem;">{{ $conversation->updated_at->diffForHumans() }}</div>
                </a>
                @endforeach
            @endif
        </div>
    </div>
</x-app-layout>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter=AgentControllerTest`
Expected: PASS, all 6 tests — `agents.conversations.store`/`agents.chat` already exist from Task 3, so `show.blade.php`'s "New Conversation" form and conversation-list links resolve cleanly with no dangling routes.

- [ ] **Step 7: Run both controllers' tests together**

Run: `php artisan test --compact --filter="AgentControllerTest|ConversationControllerTest"`
Expected: PASS, all 10 tests.

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AgentController.php resources/views/agents routes/web.php tests/Feature/AgentControllerTest.php
git commit -m "feat: agent CRUD (create, list, edit, show)"
```

---

### Task 5: Finish `AgentChat` — real view, fixed `mount()`, loading/error states, component test

**Files:**
- Modify: `app/Livewire/Agents/AgentChat.php`
- Create: `resources/views/livewire/agents/agent-chat.blade.php`
- Modify: `resources/views/agents/chat.blade.php`
- Test: `tests/Feature/Livewire/AgentChatTest.php`

**Interfaces:**
- Consumes: `Agent`, `Conversation`, `AgentChatService` (all pre-existing/Task 1-2), `agents.chat` route (Task 3).
- Produces: `AgentChat::mount(Agent $agent, Conversation $conversation)` (was `mount(Agent $agent)`); `startOrContinue()` removed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Livewire/AgentChatTest.php`:

```php
<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Agents\AgentChat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class AgentChatTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_message_persists_it_and_shows_the_agents_reply(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['type' => 'text', 'text' => 'Hello! How can I help?']],
                'usage' => ['input_tokens' => 12, 'output_tokens' => 8],
            ], 200),
        ]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        Livewire::actingAs($user)
            ->test(AgentChat::class, ['agent' => $agent, 'conversation' => $conversation])
            ->set('message', 'Hi there')
            ->call('send')
            ->assertSet('message', '')
            ->assertSet('error', null);

        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Hi there',
        ]);
        $this->assertDatabaseHas('messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Hello! How can I help?',
        ]);
    }

    public function test_a_failed_api_call_sets_a_plain_language_error(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
        config(['services.anthropic.api_key' => 'test-key']);

        $user = User::factory()->withPersonalTeam()->create();
        $agent = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agent)->create();

        Livewire::actingAs($user)
            ->test(AgentChat::class, ['agent' => $agent, 'conversation' => $conversation])
            ->set('message', 'Hi there')
            ->call('send')
            ->assertSet('error', 'The agent failed to respond. Please try again.');
    }

    public function test_mounting_with_a_conversation_belonging_to_a_different_agent_is_rejected(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $agentA = Agent::factory()->for($user->currentTeam)->create();
        $agentB = Agent::factory()->for($user->currentTeam)->create();
        $conversation = Conversation::factory()->for($user)->for($agentA)->create();

        $this->actingAs($user);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        Livewire::test(AgentChat::class, ['agent' => $agentB, 'conversation' => $conversation]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=AgentChatTest`
Expected: FAIL — `mount()` doesn't accept a `conversation` parameter yet, and the view is still missing.

- [ ] **Step 3: Fix `AgentChat::mount()`, remove `startOrContinue()`**

Modify `app/Livewire/Agents/AgentChat.php`:

```php
<?php

namespace App\Livewire\Agents;

use App\Models\Agent;
use App\Models\Conversation;
use App\Services\AgentChatService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class AgentChat extends Component
{
    public Agent $agent;

    public Conversation $conversation;

    #[Validate('required|string|max:4000')]
    public string $message = '';

    public bool $sending = false;

    public ?string $error = null;

    /**
     * Both an agent and an already-existing conversation are always
     * provided by the caller (see ConversationController::show) — every
     * conversation is created explicitly via ConversationController::store
     * before this component is ever mounted, so there is no ambiguous
     * "find or create the most recent one" case to handle here.
     */
    public function mount(Agent $agent, Conversation $conversation): void
    {
        abort_unless($conversation->agent_id === $agent->id, 404);

        $this->agent = $agent;
        $this->conversation = $conversation;
    }

    #[Computed]
    public function messages(): Collection
    {
        return $this->conversation->messages()->orderBy('created_at')->get();
    }

    public function send(): void
    {
        $this->validate();

        $this->sending = true;
        $this->error = null;
        $userMessage = $this->message;
        $this->message = '';

        $service = app(AgentChatService::class);
        $reply = $service->chat($this->conversation, $userMessage, auth()->id());

        if ($reply === null) {
            $this->error = 'The agent failed to respond. Please try again.';
        }

        $this->sending = false;
        unset($this->messages);
    }

    public function render(): View
    {
        return view('livewire.agents.agent-chat');
    }
}
```

- [ ] **Step 4: Write the chat view**

Create `resources/views/livewire/agents/agent-chat.blade.php`:

```blade
<div style="display:flex;flex-direction:column;height:calc(100vh - 4rem);">
    <div style="padding:1.25rem 1.5rem;border-bottom:1px solid var(--divider);">
        <h1 style="font-family:'Syne',sans-serif;font-size:1.2rem;font-weight:800;color:var(--text-primary);margin:0;">{{ $agent->name }}</h1>
        <p style="font-size:0.75rem;color:var(--text-muted);margin:0.15rem 0 0;">{{ $conversation->title }}</p>
    </div>

    <div style="flex:1;overflow-y:auto;padding:1.5rem;display:flex;flex-direction:column;gap:0.85rem;">
        @forelse($this->messages as $msg)
            <div style="max-width:70%;align-self:{{ $msg->role === 'user' ? 'flex-end' : 'flex-start' }};">
                <div style="background:{{ $msg->role === 'user' ? 'linear-gradient(135deg,#e11d48,#9f1239)' : 'var(--card-bg)' }};border:1px solid {{ $msg->role === 'user' ? 'transparent' : 'var(--card-border)' }};border-radius:0.9rem;padding:0.65rem 0.9rem;color:{{ $msg->role === 'user' ? '#fff' : 'var(--text-primary)' }};font-size:0.85rem;line-height:1.5;white-space:pre-wrap;">{{ $msg->content }}</div>
            </div>
        @empty
            <p style="color:var(--text-muted);font-size:0.8rem;text-align:center;margin-top:2rem;">Say hello to get started.</p>
        @endforelse

        <div wire:loading wire:target="send" style="align-self:flex-start;color:var(--text-muted);font-size:0.78rem;">
            {{ $agent->name }} is typing…
        </div>
    </div>

    @if($error)
    <div style="padding:0.6rem 1.5rem;color:#f87171;font-size:0.78rem;">{{ $error }}</div>
    @endif

    <form wire:submit="send" style="padding:1rem 1.5rem;border-top:1px solid var(--divider);display:flex;gap:0.6rem;">
        <input
            type="text"
            wire:model="message"
            placeholder="Message {{ $agent->name }}…"
            wire:loading.attr="disabled"
            wire:target="send"
            style="flex:1;border-radius:0.6rem;background:var(--input-bg);border:1px solid var(--input-border);color:var(--text-primary);padding:0.6rem 0.85rem;font-size:0.85rem;"
        />
        @error('message') <span style="color:#f87171;font-size:0.72rem;align-self:center;">{{ $message }}</span> @enderror
        <button
            type="submit"
            wire:loading.attr="disabled"
            wire:target="send"
            style="padding:0.6rem 1.35rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.8rem;font-weight:700;color:#fff;border:none;cursor:pointer;"
        >
            Send
        </button>
    </form>
</div>
```

- [ ] **Step 5: Wire the placeholder view up to the real component**

Modify `resources/views/agents/chat.blade.php` to mount the finished component:

```blade
<x-app-layout>
    <livewire:agents.agent-chat :agent="$agent" :conversation="$conversation" />
</x-app-layout>
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --compact --filter="AgentChatTest|ConversationControllerTest"`
Expected: PASS, all tests.

- [ ] **Step 7: Run the full suite**

Run: `php artisan test --compact`
Expected: PASS, all tests (this is the first point where every piece of the feature exists together).

- [ ] **Step 8: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/Agents/AgentChat.php resources/views/livewire/agents/agent-chat.blade.php resources/views/agents/chat.blade.php tests/Feature/Livewire/AgentChatTest.php
git commit -m "feat: finish AgentChat -- real view, explicit conversation, loading/error states"
```

---

### Task 6: Wire up navigation — sidebar link, dashboard's dead buttons

**Files:**
- Modify: `resources/views/layouts/app.blade.php`
- Modify: `resources/views/dashboard.blade.php`
- Test: `tests/Feature/DashboardTest.php` (extend existing)

**Interfaces:**
- Consumes: `agents.index`, `agents.create`, `agents.show` routes (Task 4).

- [ ] **Step 1: Add the "Agents" sidebar nav item**

Modify `resources/views/layouts/app.blade.php` — add a new `nav-item` link inside `<nav class="sidebar-nav">`, before the existing `control-rooms.index` link (AI agents were the platform's original domain, listed first):

```blade
            <a href="{{ route('agents.index') }}" class="nav-item {{ request()->routeIs('agents.*') ? 'active' : '' }}">
                <span class="material-symbols-rounded" style="font-size:18px;">smart_toy</span>
                Agents
            </a>
```

- [ ] **Step 2: Point the dashboard's dead links at real routes**

Modify `resources/views/dashboard.blade.php`. `grep -n 'href="#"' resources/views/dashboard.blade.php` finds 6 occurrences today; 5 get a real route, 1 (`Add Skill`) stays as-is. Four of the five have exact, already-confirmed anchor text below — find-and-replace `href="#"` with the shown route on that exact line. The fifth (the header "Create Agent" button, near the top of the file, styled with the same rose gradient as the others) needs a quick look at its surrounding markup first since its full line wasn't captured verbatim during design — same fix, `href="{{ route('agents.create') }}"`, once located.

1. Agents-panel "View all" link:
   ```blade
   <a href="#" style="font-size:0.72rem;color:#e11d48;text-decoration:none;font-weight:600;">View all</a>
   ```
   → `href="{{ route('agents.index') }}"`

2. Empty-state "Create your first AI agent" button (the `<a>` immediately before `<span class="material-symbols-rounded" ...>add_circle</span>` inside the "No agents yet" block):
   ```blade
   <a href="#" style="display:inline-flex;align-items:center;gap:0.4rem;padding:0.6rem 1.25rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.78rem;font-weight:700;color:#fff;text-decoration:none;">
   ```
   → `href="{{ route('agents.create') }}"`

3. Each per-agent row's "Open" button, inside the `@foreach($agents as $agent)` loop:
   ```blade
   <a href="#" style="display:inline-flex;align-items:center;gap:0.3rem;padding:0.35rem 0.9rem;border-radius:9999px;border:1px solid rgba(225,29,72,0.35);color:#fda4af;font-size:0.7rem;font-weight:700;text-decoration:none;font-family:'Syne',sans-serif;transition:all 0.15s;" onmouseover="this.style.background='rgba(225,29,72,0.1)'" onmouseout="this.style.background='transparent'">
   ```
   → `href="{{ route('agents.show', $agent) }}"` (this one is inside the loop, so it must reference `$agent`, not a fixed route — the other four don't take a parameter)

4. Bottom quick-start CTA "Get started with Dot.Central" button:
   ```blade
   <a href="#" style="flex-shrink:0;display:inline-flex;align-items:center;gap:0.5rem;padding:0.75rem 1.5rem;border-radius:9999px;background:linear-gradient(135deg,#e11d48,#9f1239);font-family:'Syne',sans-serif;font-size:0.82rem;font-weight:700;color:#fff;text-decoration:none;box-shadow:0 8px 24px rgba(225,29,72,0.3);white-space:nowrap;">
   ```
   → `href="{{ route('agents.create') }}"`

Leave the "Add Skill" link (`href="#"`, styled with a dashed purple border, near the skills panel) untouched — `AgentSkill` management is explicitly out of scope (see Global Constraints).

- [ ] **Step 3: Write a test proving the dashboard links to real routes**

Modify `tests/Feature/DashboardTest.php` — add:

```php
    public function test_dashboard_links_to_the_real_agent_routes_not_placeholder_links(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        \App\Models\Agent::factory()->for($user->currentTeam)->create(['name' => 'Support Bot']);

        $response = $this->actingAs($user)->get('/dashboard');

        $response->assertOk();
        $response->assertSee(route('agents.create'), false);
        $response->assertSee(route('agents.index'), false);
    }
```

(Check the top of `DashboardTest.php` for its existing `use` statements before adding — it likely already imports `User`; add `use App\Models\Agent;` if not already present via a fully-qualified reference as above.)

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact --filter=DashboardTest`
Expected: PASS.

- [ ] **Step 5: Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add resources/views/layouts/app.blade.php resources/views/dashboard.blade.php tests/Feature/DashboardTest.php
git commit -m "feat: wire dashboard and sidebar to the real agent routes"
```

---

### Task 7: Final verification + wiki.md update

**Files:**
- Modify: `wiki.md`

- [ ] **Step 1: Run the full backend suite**

Run: `php artisan test --compact`
Expected: PASS, all tests (report the real total — this plan adds roughly 17 new tests across Tasks 1-6: 1 in Task 1, 2 in Task 2, 4 in Task 3, 6 in Task 4, 3 in Task 5, 1 in Task 6; confirm the actual count from the output rather than assuming).

- [ ] **Step 2: Pint, repo-wide**

Run: `vendor/bin/pint --format agent`
Expected: no changes needed beyond what earlier tasks already fixed (each task ran `--dirty` on its own files; this is a final full-repo check).

- [ ] **Step 3: Live verification**

Start the app locally (`php artisan serve` + `npm run dev` if not already running) and walk through the real flow in a browser: log in → click "Agents" in the sidebar → create an agent with a system prompt and at least one skill → land on its show page → click "New Conversation" → send a real message → confirm a reply appears (real Claude reply if `ANTHROPIC_API_KEY` is set locally, otherwise the documented mock-echo reply — both are correct, confirm whichever applies) → go back to the agent's show page → confirm the conversation now appears in the list → reopen it → confirm prior messages are still there. Confirm zero console errors.

- [ ] **Step 4: Update `wiki.md`**

Read `wiki.md` §1, §3, §4, §7, and the Change Log's most recent entry before editing, to match its established voice and format exactly (see the 0.9.0 entry for the most recent example of this platform's own tone).

Update:
- §1 "Status": change "AI command-centre scaffolding... is implemented and running" to something that distinguishes what was true before this plan (schema + service only, no reachable UI) from what's true now (a real, working create-agent-and-chat flow) — don't just delete the old caveat, say what changed and why it was wrong before.
- §4 "What's Not Built Yet": remove nothing that's still genuinely not built (streaming, multi-agent routing, public API, `AgentKnowledge` all remain accurate), but the section's framing currently implies the *rest* of the AI-agent domain was already working — correct that.
- §7 Roadmap: add a new `[x]` entry for this work, matching the existing entries' format (`~~strikethrough~~` + explanation).
- Bump the frontmatter `version:` (currently `0.9.0`) and add a Change Log row with today's date, summarizing: what was found (the gap), what was built (per-task summary), test counts (real, from Step 1), and the live-verification walkthrough from Step 3.

- [ ] **Step 5: Commit**

```bash
git add wiki.md
git commit -m "docs: update wiki.md for the real agent creation + chat UI"
```

Do not push without explicit confirmation — matches this platform's established pattern of committing locally and waiting to be told to push.
