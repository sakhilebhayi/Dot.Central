---
title: Dot.Central — Platform Wiki
version: 0.2.0
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

Dot.Central is the AI command centre of the ecosystem: the place teams build, configure, and converse with specialised Claude-powered agents, then route work from other Dot platforms through them. It is a Laravel 12 application (Livewire 3, Alpine.js, Tailwind, PostgreSQL) with Jetstream-based teams/auth and Sanctum-backed ecosystem SSO.

**Status:** early build. Core scaffolding — agents, skills, conversations, messages, usage tracking, a dashboard, and ecosystem SSO — is implemented and running. Deeper features (knowledge-base grounding per agent, multi-agent routing/chaining, streaming responses, per-agent public API endpoints) are named in the README as intended direction but not yet built out in code.

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

That document is written from Dot.Brain's ingestion perspective and currently describes a different product surface than what exists in this repository: it frames Dot.Central as an *operational intelligence center for mining dispatch* (control rooms, dispatch decisions, fleet/haul-cycle events, alert precision) working tightly with Dot.Mines. The code in this repository, as of this wiki, is an **AI agent command centre** — agent builder, conversations, usage tracking — with no mining, dispatch, or control-room domain model anywhere in the schema. We are noting this divergence explicitly rather than silently rewriting either side; see Open Questions below for how we intend to reconcile it.

Until that reconciliation happens, this wiki takes precedence over `platforms/dot-central.md` for what Dot.Central *is*, per the ecosystem's ownership rule: platforms own their local knowledge, Dot.Brain ingests what's published.

We have not yet published a Knowledge Pack of any kind. Before we do, we need our own DKP manifest (`platform.dkp.json`), signing key, and an actual event-emission path (see §5) — none of which exist in code today.

## 7. Roadmap

- [ ] Decide and document: does Dot.Central converge toward the mining-dispatch domain described in Dot.Brain's `platforms/dot-central.md`, stay an ecosystem-wide AI agent hub, or become both (agent hub as the delivery mechanism, dispatch/control-room as one deployed agent configuration)?
- [ ] Build `AgentKnowledge` model and document-grounding pipeline
- [ ] Add streaming responses to `AgentChatService`
- [ ] Ship a public per-agent API endpoint for cross-platform routing
- [ ] Define and publish the first Knowledge Pack (`platform.dkp.json`, signing key, `observation` payload) once there's a real event to report
- [ ] Multi-agent routing/chaining

## Change Log

| Version | Date | Author | Change |
|---|---|---|---|
| 0.2.0 | 2026-08-01 | Central Platform Lead | Initial wiki, derived from the actual codebase (Laravel 12 AI agent command centre — agents, skills, conversations, messages, usage logs, ecosystem SSO). Flagged domain divergence from Dot.Brain's mining-dispatch framing in platforms/dot-central.md as an open reconciliation question rather than resolving it unilaterally. |

## Open Questions

- **Domain divergence (primary):** Dot.Brain's ingested view describes mining dispatch/control-room intelligence; this repo implements a general AI agent hub. Who decides the platform's actual scope — is `platforms/dot-central.md` describing a planned pivot, a different product entirely, or was it drafted against the wrong platform? Needs a decision from the Central Platform Lead in coordination with whoever owns the Dot.Mines integration story.
- Should `capabilities` (currently a free-form JSON column on `agents`) be schema-constrained once we know what an agent is actually allowed to do (tool access, data scope)?
- What does "Ecosystem SSO from InfoDot hub" mean for tenancy — is a `team_id` sufficient scoping key for future Knowledge Pack publishing, or do we need a dedicated tenant concept per the pattern other platforms use?
