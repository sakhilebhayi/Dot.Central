# Real Agent Creation + Chat UI — Design Spec

## Context

Found during a routine roadmap check, not assumed: despite `wiki.md` §1
describing the AI-agent command centre as "implemented and running," there
is currently **no working path for a real user to create an agent, open a
conversation, or chat with one.**

Confirmed directly against the code, not inferred from the wiki:

- `routes/web.php` has zero routes for agent CRUD or conversation/chat.
- Every action link on `resources/views/dashboard.blade.php` (the
  dashboard's own "Create Agent," "View all," "New chat," etc. buttons) is
  a literal `href="#"`.
- `app/Livewire/Agents/AgentChat.php` exists, and its logic
  (`mount`/`startOrContinue`/`send`/`newConversation`, backed by the real
  `App\Services\AgentChatService` Claude integration) is largely sound —
  but `render()` points at `view('livewire.agents.agent-chat')`, which does
  not exist anywhere in `resources/views`. It would throw
  `ViewNotFoundException` if it were ever actually rendered.
- Zero tests touch `AgentChat`, `Conversation`, `Agent` creation, or the
  chat flow at all. No factory exists for `Agent`, `AgentSkill`,
  `Conversation`, or `Message` either — this domain has never had test-data
  scaffolding, which is *why* nothing caught the missing view.

`App\Services\AgentChatService` itself is real and working (calls the
actual Anthropic Messages API, `claude-sonnet-4-6`, with a documented
mock-echo fallback when `ANTHROPIC_API_KEY` is unset) and was reviewed
clean in a prior security-audit pass (cross-tenant isolation via
`HasUserScope`/`HasConversationUserScope`, confirmed in `wiki.md`'s 0.3.2
changelog entry). The gap is entirely in the UI layer: nothing wires a real
user to this service.

## Goal

Build the actual UI: create an agent, browse/reopen past conversations
with it, and chat — using the real, already-working `AgentChatService`,
not a new one.

## Design

### 1. Data model

New migration adds `team_id` (required FK to `teams`) to `agents`.
`Agent` gets `HasTeamScope`, mirroring `ControlRoom` exactly — agents
become a shared resource within a team, not a fully global catalog
(the decision `wiki.md`'s current "ecosystem-wide agent catalog" framing
described, revisited here deliberately). `AgentSkill` stays global/
unscoped — it's a shared tag catalog, not team data; only the
*assignment* of skills to a team's agent is team-scoped, which falls out
naturally through `Agent`.

`Conversation`/`Message`/`AgentUsageLog` are untouched — deliberately kept
private per-user (`HasUserScope`/`HasConversationUserScope`, already
correct) even though the agent itself is now team-shared. A conversation
is your own chat history with a shared agent, not a shared team artifact —
matches how most chat-on-top-of-a-shared-tool products behave, and avoids
a real privacy trade-off that wasn't asked for.

New factories: `AgentFactory`, `AgentSkillFactory`, `ConversationFactory`,
`MessageFactory` — none exist today; required before any test below can be
written, and useful beyond this feature for local `db:seed` usability.

### 2. Screens & routes

- **`AgentController`** (plain controller + Blade, matching
  `ControlRoomController`'s established convention exactly — no reactivity
  needed for simple forms): `index` (team's agent list), `create`/`store`,
  `edit`/`update` (includes an `is_active` checkbox in the same update
  action, matching how `ControlRoomController::update()` already handles
  `is_active` — no separate deactivate route), `show` (agent detail +
  assigned skills + this user's past conversations with it). No `destroy`
  — a wrong system prompt gets fixed via `edit`, not delete/recreate;
  avoids orphaning conversation history. The create/edit form's fields:
  `name`, `description`, `system_prompt`, `model` (a plain text input,
  defaulting to `Agent`'s existing `claude-sonnet-4-6` column default —
  no hardcoded model dropdown to keep in sync), and a multi-select
  *assigning* existing `AgentSkill` rows (checkboxes against whatever
  skills already exist — see "explicitly out of scope" for the
  distinction between assigning existing skills, which is in scope, and
  managing/creating skill tags themselves, which isn't).
- **`ConversationController`** (new, small, single-purpose): `store`
  creates a fresh `Conversation` (title format matches
  `AgentChat::newConversation()`'s existing convention, `"Chat with
  {agent} — {date}"`) and redirects into the chat screen for it. `show`
  renders the chat screen for one specific, already-existing conversation.
- **Navigation is fully explicit — no "guess which conversation" logic.**
  Every conversation is created once (`store`) and reopened by ID
  (`show`). This matters because, as written today,
  `AgentChat::startOrContinue()` calls `Conversation::firstOrCreate`
  keyed only on `(user_id, agent_id)` — that would always resolve to the
  *same* (oldest) conversation regardless of which one a user clicks,
  silently breaking "browse your past conversations, pick one to
  continue." This route design sidesteps that entirely rather than
  patching the existing method: `AgentChat::mount()` changes from
  `mount(Agent $agent)` to `mount(Agent $agent, Conversation $conversation)`
  — both required, since one now always exists by the time the chat
  screen loads. `startOrContinue()` becomes dead code once every call
  site provides a conversation explicitly; remove it rather than leave
  unreachable logic behind (the exact kind of half-finished code that
  caused this gap in the first place).
- Routes, all inside the existing `auth:sanctum` + Jetstream session
  group: `Route::resource('agents', AgentController::class)->except('destroy')`,
  `POST agents/{agent}/conversations` (`agents.conversations.store`),
  `GET agents/{agent}/chat/{conversation}` (`agents.chat`).
- New "Agents" sidebar nav item in `resources/views/layouts/app.blade.php`,
  alongside Dashboard/Control Rooms/Notifications — none exists today.
- Dashboard's dead `href="#"` action links get pointed at real routes
  (`agents.create`, `agents.index`, etc.) as part of this work, since
  they're the most visible symptom of the underlying gap.
- The chat screen's Blade view (`livewire.agents.agent-chat`) and the new
  `agents/*` Blade views follow the **authenticated app shell's real
  design tokens** — confirmed directly from `layouts/app.blade.php` and
  the existing `livewire/notification-bell.blade.php`: `--card-bg`,
  `--card-border`, `--text-primary`/`--text-secondary`/`--text-muted`,
  `--divider`, `--accent` (`#7dd3fc`), Syne (headings) + Inter (body) +
  JetBrains Mono, dark/light via `data-theme`. **Not** the separate
  ink/gold/cyan/Sora+IBM-Plex token set used on the public marketing pages
  (welcome/auth/error pages) — those are a different, deliberately
  distinct system for a different audience.

### 3. Data flow & error handling

Sending a message: input → `wire:submit="send"` → Livewire's automatic
validation (the `#[Validate('required|string|max:4000')]` attribute
already on `$message`) → `AgentChatService::chat()` — a real, synchronous
HTTP call to Anthropic (up to the service's own 30s timeout). Because this
blocks for real network time, the chat view needs a visible loading state,
not a frozen form: `wire:loading`/`wire:target="send"` disabling the input
and showing a sending indicator, using the `$sending` property the
component already exposes.

Failure paths are already handled in the service layer; the new view only
needs to render them: API failure → `chat()` returns `null` → `send()`
already sets `$error` to a plain-language message — display it. No
`ANTHROPIC_API_KEY` configured → the existing mock-echo fallback fires
automatically, no error at all (the documented, deliberate dev-mode
behavior — nothing new to build).

Authorization matches this codebase's own established, already-proven
convention: no current team → `403` on agent creation (same as
`ControlRoomController::store()`). Cross-team agent access, cross-user
conversation access → `404`, not `403` — both already fall out of
`HasTeamScope`/`HasUserScope` being global scopes (a foreign row is
invisible to route-model binding before any explicit check runs), the
same behavior already proven for `ControlRoom`/`Alert`/
`StaleSessionProposal`. One explicit check still needed:
`ConversationController::show()` verifying the URL's `{agent}` and
`{conversation}` actually correspond (`$conversation->agent_id ===
$agent->id`) → `404` on mismatch, matching this app's own
already-documented "mismatch is invisible, not forbidden" posture.

### 4. Testing

- `HasTeamScopeTest`-style addition: a cross-team `Agent` read is blocked
  by the scope alone — mirrors the existing test proving this for
  `ControlRoom`.
- `AgentControllerTest`: create/list/edit/deactivate, team-scoping,
  cross-team 404 — mirrors `ControlRoomTest`'s shape.
- `ConversationControllerTest`: `store` creates a real, correctly-scoped
  `Conversation`; `show` renders; agent/conversation URL mismatch 404s;
  another user's conversation 404s (via `HasUserScope` alone, not an
  explicit check).
- A Livewire component test for `AgentChat` (`Livewire::test(AgentChat::class,
  ['agent' => ..., 'conversation' => ...])`), with `Http::fake()` stubbing
  the Anthropic call — no real API calls in tests, matching this
  ecosystem's established testing convention for outbound HTTP.

## Explicitly out of scope

- Streaming responses, multi-agent routing/chaining, a public per-agent
  API endpoint, `AgentKnowledge`/document grounding — all separate,
  already-tracked `wiki.md` §7 roadmap items, none blocking a working
  chat UI.
- `AgentSkill` *management* (creating/editing skill tags themselves,
  distinct from *assigning* existing ones to an agent, which is in
  scope — see §2) — no UI or controller exists for that today and it
  isn't part of the reported gap (which is "can't chat," not "can't tag
  agents"). If no `AgentSkill` rows exist yet in a given environment, the
  agent form's multi-select is simply empty — not a blocker for this
  feature.
- A hard-delete path for agents (see §2 above — deliberate, not deferred).
- Any change to `AgentChatService` itself — it's already correct and
  reviewed; this spec only wires real UI to it.
