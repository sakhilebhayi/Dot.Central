<div align="center">

<img src="docs/logo.svg" alt="Dot.Central" width="320" />

<br /><br />

**Create, configure, and converse with specialised AI agents powered by Claude.**

<br />

![Laravel](https://img.shields.io/badge/Laravel-12-FF2D20?style=flat-square&logo=laravel&logoColor=white) ![PHP](https://img.shields.io/badge/PHP-8.4-777BB4?style=flat-square&logo=php&logoColor=white) ![Livewire](https://img.shields.io/badge/Livewire-3-FB70A9?style=flat-square) ![PostgreSQL](https://img.shields.io/badge/PostgreSQL-16-336791?style=flat-square&logo=postgresql&logoColor=white)

<br /><br />

**Part of the [InfoDot Ecosystem](https://github.com/sakhileb/InfoDot)** &nbsp;·&nbsp; `central.infodot.app`

</div>

---

## What is Dot.Central?

Dot.Central hosts two domains side by side. See [`wiki.md`](wiki.md) for the full, actively-maintained picture — this README is a quick orientation, not the source of truth.

1. **AI command centre** — teams create specialised Claude-powered agents with custom system prompts and skills, then converse with them and track token usage.
2. **Mining-dispatch scaffold (MVP)** — control rooms, dispatch decisions, alerts, and operator sessions, scoped to Jetstream teams. Data model and CRUD only — no event emission or Dot.Mines integration yet.

`wiki.md` also documents a known, tracked discrepancy: Dot.Brain's ingested view of this platform (`platforms/dot-central.md`) describes it as an operational-intelligence center for mining dispatch, while it was originally built as the AI-agent command centre, with the mining-dispatch domain added later as a scaffold on top. That gap is intentionally left open for a human decision, not resolved here.

## Core Features

- Agent builder — system prompt, persona, and skill assignment
- Conversation interface, per-conversation message history
- Usage and cost tracking per agent and per team member
- Mining-dispatch control rooms — dispatch decisions, alerts (with in-app notifications), operator sessions
- Ecosystem SSO from the InfoDot hub

Not yet built (tracked in `wiki.md` §4/§7): knowledge-base grounding per agent, multi-agent routing/chaining, streaming chat responses, a public per-agent API endpoint, and Dot.Mines event integration for the dispatch domain.

## Domain Models

AI command centre: `Agent`, `AgentSkill`, `Conversation`, `Message`, `AgentUsageLog`.

Mining dispatch (MVP scaffold): `ControlRoom`, `DispatchDecision`, `Alert`, `OperatorSession`.

## Tech Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 12, PHP 8.4 |
| Frontend | Livewire 3 · Alpine.js 3 · Tailwind CSS |
| Database | PostgreSQL 16 (shared across ecosystem) |
| Auth | Laravel Jetstream (teams) + Sanctum, ecosystem SSO from the InfoDot hub |
| AI | Anthropic Claude, via direct HTTP call in `App\Services\AgentChatService` (falls back to a mock reply when `ANTHROPIC_API_KEY` is unset) |
| Realtime (planned) | Laravel Reverb |
| Search (planned) | Laravel Scout · Meilisearch |
| Queue (planned) | Redis · Laravel Horizon |

## Quick Start

```bash
git clone https://github.com/sakhileb/Dot.Central.git
cd Dot.Central
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan serve
```

> **Ecosystem SSO:** Set `DB_*` env vars to the shared InfoDot PostgreSQL instance and `APP_URL=https://central.infodot.app`. Users authenticated through InfoDot gain access automatically via Sanctum handoff tokens.

## Ecosystem

**Dot.Central** is one of **21 platforms** in the InfoDot ecosystem, connected via shared PostgreSQL and Sanctum SSO. Visit [InfoDot](https://github.com/sakhileb/InfoDot) to explore the full platform map.

## License

MIT © [SK Digital / BluPin Incorporated](https://github.com/sakhileb)
