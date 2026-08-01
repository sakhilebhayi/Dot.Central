---
title: Dot.Central — Platform Wiki
version: 0.3.2
status: draft
owners: [Central Platform Lead]
platform-id: dot-central
last-review: 2026-08-01
---

# Dot.Central

Purpose: this is Dot.Central's own knowledge home — owned and maintained by the Dot.Central team. It describes what this platform actually is, how it's built, and how it connects to the wider Dot Ecosystem. Dot.Brain never edits this file; it only reads what we choose to publish.

> **Related:** [Dot.Brain's ingested view of this platform](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-central.md)

---

## 1. What Dot.Central Is

Dot.Central now hosts two domains side by side in the same codebase:

1. **AI command centre** — the place teams build, configure, and converse with specialised Claude-powered agents, then route work from other Dot platforms through them.
2. **Mining-dispatch scaffold (MVP, added 2026-08-01)** — the operational-intelligence data model described in Dot.Brain's `platforms/dot-central.md`: control rooms, dispatch decisions, alerts, and operator sessions. This is a data-model-and-CRUD scaffold only; see §3a and §7 for exactly what is and isn't built.

It is a Laravel 12 application (Livewire 3, Alpine.js, Tailwind, PostgreSQL) with Jetstream-based teams/auth and Sanctum-backed ecosystem SSO.

**Status:** early build on both domains. AI command-centre scaffolding — agents, skills, conversations, messages, usage tracking, a dashboard, and ecosystem SSO — is implemented and running. Deeper AI-domain features (knowledge-base grounding per agent, multi-agent routing/chaining, streaming responses, per-agent public API endpoints) are named in the README as intended direction but not yet built out in code. The mining-dispatch domain has migrations, models, basic CRUD (controllers + Blade views), factories, and a seeder standing, scoped to Jetstream teams the same way the rest of the app is — but no event emission, no Dot.Mines integration, and no Knowledge Pack publishing (see §3a).

## 2. Architecture

| Layer | Technology |
|---|---|
| Framework | Laravel 12, PHP 8.4 |
| Frontend | Livewire 3, Alpine.js 3, Tailwind CSS |
| Database | PostgreSQL 16 (shared ecosystem instance) |
| Auth | Laravel Jetstream (teams) + Sanctum, with `EcosystemAuthController` handling SSO handoff from the InfoDot hub |
| AI | Anthropic Claude via direct HTTP call to `https://api.anthropic.com/v1/messages` (default model `claude-sonnet-4-6`), wrapped in `App\Services\AgentChatService` |
| Realtime (planned) | Laravel Reverb |
| Search (planned) | Laravel Scout / Meilisearch |
| Queue (planned) | Redis / Laravel Horizon |

`AgentChatService::chat()` persists the user message, calls the Anthropic Messages API with the agent's `system_prompt` and full conversation history, persists the assistant reply, and logs token usage. When `ANTHROPIC_API_KEY` is unset it falls back to a mock echo reply so the app runs without a live key — a deliberate dev-mode fallback, not a bug.

## 3. Domain Entities

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Agent | `agents` | `name`, `slug`, `system_prompt`, `model`, `capabilities` (json), `is_active` | The Claude-backed persona; `capabilities` is an open JSON bag, not yet schema-constrained |
| Agent Skill | `agent_skills` | `name`, `slug`, `icon` | Tag/category attached to agents many-to-many via `agent_agent_skill` |
| Conversation | `conversations` | `user_id`, `agent_id`, `title` | One thread between a user and an agent |
| Message | `messages` | `conversation_id`, `role` (`user`/`assistant`), `content`, `tokens_used` | Ordered by `created_at`; full history is replayed to Claude on each turn (no truncation/summarisation yet) |
| Agent Usage Log | `agent_usage_logs` | `user_id`, `agent_id`, `tokens_input`, `tokens_output` | Written once per assistant turn — the basis for per-agent/per-user cost tracking |

Teams, users, and invitations come from the stock Jetstream scaffold and are shared-schema with the rest of the InfoDot ecosystem via the shared PostgreSQL instance.

## 3a. Domain Entities — Mining Dispatch (MVP scaffold, added 2026-08-01)

Added alongside the AI-agent domain above, not in place of it. Mirrors the entity list in Dot.Brain's `platforms/dot-central.md` §2, kept deliberately minimal:

| Entity | Table | Key fields | Notes |
|---|---|---|---|
| Control Room | `control_rooms` | `team_id`, `name`, `slug`, `mines_site_ref`, `is_active` | Tenant root, scoped to a Jetstream team (same tenancy mechanism as the rest of this app); `mines_site_ref` is a plain string pointer to a Dot.Mines site, not a live integration |
| Dispatch Decision | `dispatch_decisions` | `control_room_id`, `workflow_type` (enum), `sequence`, `decided_at`, `decided_by_user_id`, `summary` | The unit of record — identity is room + timestamp + sequence, enforced via a unique `(control_room_id, sequence)` index |
| Dispatch Workflow | — | — | Not a table. Modeled as the four-value `workflow_type` enum on `dispatch_decisions` (`assign`, `reroute`, `hold`, `stagger`) — a lookup concept, not an entity with its own lifecycle, per Dot.Brain §2 |
| Alert | `alerts` | `control_room_id`, `severity` (enum), `title`, `description`, `triggered_at`, `cleared_at` | Threshold/sentinel trips |
| Operator Session | `operator_sessions` | `control_room_id`, `user_id`, `shift_label`, `started_at`, `ended_at` | Control-room staffing context; deliberately carries no individual performance fields, per Dot.Brain §8's privacy note |

Models: `App\Models\ControlRoom`, `DispatchDecision`, `Alert`, `OperatorSession`, all with `HasFactory` and relationships (`ControlRoom` has many of each child; `Team::controlRooms()` added). CRUD is via plain resource/nested controllers (`ControlRoomController`, `DispatchDecisionController`, `AlertController`, `OperatorSessionController`) and Blade views under `resources/views/control-rooms/`, not Livewire — the only existing Livewire component (`AgentChat`) is chat-specific, so plain controllers matched the rest of the app's convention (dashboard, teams, profile are all controller/Blade) better than introducing Livewire for basic forms. Routes are registered in `routes/web.php` under the same `auth:sanctum` + Jetstream session group as the dashboard. A `MiningDispatchSeeder` and matching factories seed one demo control room with decisions across all four workflow types, two alerts, and an operator session.

Explicitly out of scope for this pass (see §7): event emission (`mining.dispatch.decided` etc.), any Dot.Mines API call, and any Knowledge Pack publishing — this is the data model and CRUD standing up, nothing more.

## 4. What's Not Built Yet

Named in the README as ecosystem-facing capability but absent from the current codebase — tracked here so the gap is explicit rather than implied:

- Knowledge-base upload / document grounding per agent (no `AgentKnowledge` model exists yet despite being listed as a domain model)
- Multi-agent routing/chaining for complex workflows
- Streaming responses (current implementation is a single blocking HTTP call)
- Per-agent public API endpoint for external platform integration (`routes/api.php` currently exposes only the stock `/user` Sanctum route)
- Any Knowledge Pack publishing/subscribing pipeline toward Dot.Brain

## 5. Events Emitted

No event bus integration exists in code today. The nearest analogues are database writes that a future publisher would translate into Knowledge Pack payloads:

| Would-be event | Current signal | Trigger |
|---|---|---|
| `central.agent.created` | `agents` row inserted | New agent configured |
| `central.conversation.turn_completed` | `messages` row pair (user + assistant) inserted | Each chat exchange |
| `central.usage.recorded` | `agent_usage_logs` row inserted | Each assistant turn, with token counts |

None of these are published anywhere yet — no queue job, webhook, or Brain-facing pack exists. This section documents the shape the first integration would take, not a shipped capability.

## 6. Connecting to Dot.Brain

Dot.Central is registered in Dot.Brain's platform map, and Dot.Brain's ingested view already describes a fuller integration package (Knowledge Pack manifest, domain metrics, a mining-dispatch decision loop with Dot.Mines, a tenancy model, and a worked publish→PR round-trip) at [`platforms/dot-central.md`](https://github.com/sakhilebhayi/Dot.Brain/blob/main/platforms/dot-central.md).

That document is written from Dot.Brain's ingestion perspective and, as of version 0.2.0 of this wiki, described a different product surface than what existed in this repository at the time: it frames Dot.Central as an *operational intelligence center for mining dispatch* (control rooms, dispatch decisions, fleet/haul-cycle events, alert precision) working tightly with Dot.Mines, while the code was an **AI agent command centre** only, with no mining/dispatch/control-room schema.

As of 2026-08-01 that gap is partially closed: this repository now also carries a mining-dispatch data model (§3a) — control rooms, dispatch decisions, alerts, operator sessions — as an MVP scaffold alongside the AI-agent domain. The remaining gap to Dot.Brain's fuller framing is real and tracked in §4/§7/Open Questions: no event emission, no Dot.Mines integration, and no Knowledge Pack pipeline exist yet for either domain. We are noting what's actually built rather than treating Dot.Brain's document as already implemented.

Until that reconciliation happens, this wiki takes precedence over `platforms/dot-central.md` for what Dot.Central *is*, per the ecosystem's ownership rule: platforms own their local knowledge, Dot.Brain ingests what's published.

We have not yet published a Knowledge Pack of any kind. Before we do, we need our own DKP manifest (`platform.dkp.json`), signing key, and an actual event-emission path (see §5) — none of which exist in code today.

## 7. Roadmap

- [x] **Mining-dispatch MVP scaffold landed (2026-08-01):** the answer to the convergence question below is "both" — this repo now carries the AI-agent command centre and a mining-dispatch data model side by side. Migrations, models (`ControlRoom`, `DispatchDecision`, `Alert`, `OperatorSession`), basic CRUD (controllers + Blade views), factories, and a demo seeder are in place, scoped to Jetstream teams. See §3a for the full entity table and explicit MVP-scope boundaries.
- [ ] Decide and document longer-term: does the dispatch domain stay a scaffold inside this repo, or is the agent hub meant to become the delivery mechanism for a deployed "dispatch agent" configuration instead of (or alongside) its own tables? The MVP scaffold answers "coexist for now"; the durable architecture question is still open.
- [ ] Wire dispatch-domain event emission (`mining.dispatch.decided`, `mining.dispatch.outcome`, `mining.alert.raised/cleared`, `mining.controlroom.shift_summary` per Dot.Brain §3) — explicitly deferred out of the MVP pass
- [ ] Dot.Mines API/event integration for the operational lane (currently `mines_site_ref` is a bare string, no live connection)
- [ ] Build `AgentKnowledge` model and document-grounding pipeline
- [ ] Add streaming responses to `AgentChatService`
- [ ] Ship a public per-agent API endpoint for cross-platform routing
- [ ] Define and publish the first Knowledge Pack (`platform.dkp.json`, signing key, `observation` payload) once there's a real event to report — covers both domains
- [ ] Multi-agent routing/chaining

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.3.2 | 2026-08-01 | Central Platform Lead | Security scan (incremental pass, AI-agent domain only — the S=1 gap flagged in Dot.Brain's `os/15-MEGA-v2.md`, since the mining-dispatch side was already verified in its own prior passes). Reviewed `App\Livewire\Agents\AgentChat`, `App\Livewire\NotificationBell`, `App\Services\AgentChatService`, and the `Agent`/`AgentSkill`/`Conversation`/`Message`/`AgentUsageLog` models against three checks: (1) cross-tenant/cross-user isolation — `Conversation::firstOrCreate`/`create` in `AgentChat` always key on `auth()->id()`, the dashboard route's `Conversation`/`Message`/`AgentUsageLog` queries all filter by `where('user_id', auth()->id())`, and `Agent`/`AgentSkill` are an intentionally global, unscoped catalog (no per-user/team ownership to enforce — any authenticated user may converse with any active agent, by design); (2) the IDOR-via-Livewire-method-argument pattern just fixed in Dot.Agents/Dot.Pulse — the only externally-supplied identifier on this side is `AgentChat::mount(Agent $agent)`'s route-bound `$agent`, which resolves against the global agent catalog (no ownership check needed since agents aren't tenant data); `NotificationBell::markAsRead(string $notificationId)` already scopes its lookup through `auth()->user()->notifications()->where('id', ...)`, so a foreign notification ID resolves to null rather than leaking another user's row — no unscoped `Model::find($arg)` pattern found anywhere on this side; (3) response caching/logging — no `Cache::` usage anywhere in `app/`, and the only log call in `AgentChatService` (`Log::error('AgentChat API error', ...)`) logs the HTTP status and agent slug only, never conversation content or another user's data. Also noted: the `AgentChat` Livewire component and its view are not yet wired into any route (no controller, no `routes/web.php` entry, no `@livewire` reference found in `resources/views`) — dead code today, not a security issue, but worth flagging for whoever wires up the actual chat page next. No code changes made — this pass found the AI-agent domain clean. |
| 0.3.1 | 2026-08-01 | Central Platform Lead | First full UI/branding/tests/docs loop pass on top of the mining-dispatch scaffold. UI: added a KPI summary row to the control-rooms index (active control rooms, dispatch decisions, open alerts, active operator sessions), richer empty states, lightweight submit-loading states on the control-room forms, and a class/`data-theme`-based dark/light toggle in the shared app shell (`resources/views/layouts/app.blade.php`) with CSS custom properties threaded through the dashboard and control-room views. Branding: replaced the generic Jetstream placeholder logo (`application-mark`/`application-logo`/`authentication-card-logo` components) and the app `<head>` favicons with the real Dot.Central mark; removed an unreferenced leftover `public/dot_central.png` and an empty `public/favicon.ico`; fixed `composer.json`'s `name`/`description` (was still `laravel/laravel`). Notifications: added a minimal in-app bell (Laravel `database` channel, `App\Notifications\AlertRaisedNotification`) so raising an alert notifies the rest of the control room's team — first notification surface in this app, so the `notifications` table migration was also added; the AI-agent domain was left untouched, per the caution carried over from the earlier Dot.Agents pass in this loop. Tests: added Feature tests for the dashboard, control-room index/show/create, team-scoped access control, and the notification bell (`tests/Feature/DashboardTest.php`, `ControlRoomTest.php`, `NotificationBellTest.php`) — written and reviewed by hand but not executed, since this environment has no PHP/Composer. Docs: refreshed `README.md`, which had drifted (wrong model names, no mention of the mining-dispatch domain). No changes to Jetstream auth/teams internals, the AI-agent chat/governance domain, or either domain's core model structure. |
| 0.3.0 | 2026-08-01 | Central Platform Lead | Added the mining-dispatch domain MVP scaffold (control rooms, dispatch decisions, alerts, operator sessions) alongside the existing AI-agent command centre — migrations, models, basic CRUD, factories, seeder. No event emission, Dot.Mines integration, or Knowledge Pack publishing yet; see §3a and updated Roadmap. |
| 0.2.0 | 2026-08-01 | Central Platform Lead | Initial wiki, derived from the actual codebase (Laravel 12 AI agent command centre — agents, skills, conversations, messages, usage logs, ecosystem SSO). Flagged domain divergence from Dot.Brain's mining-dispatch framing in platforms/dot-central.md as an open reconciliation question rather than resolving it unilaterally. |

## Open Questions

- **Domain divergence (primary):** Dot.Brain's ingested view describes mining dispatch/control-room intelligence; this repo implements a general AI agent hub. Who decides the platform's actual scope — is `platforms/dot-central.md` describing a planned pivot, a different product entirely, or was it drafted against the wrong platform? Needs a decision from the Central Platform Lead in coordination with whoever owns the Dot.Mines integration story.
- Should `capabilities` (currently a free-form JSON column on `agents`) be schema-constrained once we know what an agent is actually allowed to do (tool access, data scope)?
- What does "Ecosystem SSO from InfoDot hub" mean for tenancy — is a `team_id` sufficient scoping key for future Knowledge Pack publishing, or do we need a dedicated tenant concept per the pattern other platforms use?
