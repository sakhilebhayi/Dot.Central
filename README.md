# Dot.Central

Dot.Central is the control-room operating system for a mining operation. It reads the fleet from Dot.Mines and turns it into the work of a shift: who is on which machine, which machines can run, what stopped and why, and what the next shift needs to know. Operators act on what they see. Nothing in Dot.Central edits a Dot.Mines fact.

`wiki.md` is the maintained picture of the platform. The product documents in `docs/dot-central/` are the source of truth for the control-room build.

## What it does

- Shifts: start, review, close and hand over, with open issues carried forward.
- Line-up: faces from configured geofences or mine areas, loading units and haul trucks per face, standby with reasons, operator per machine pushed to Dot.Mines.
- Availability board: which machines can be operated and what each one carries.
- Derived readings from Dot.Mines: equipment status, engine hours, production, stoppages with operator classification, provider freshness.
- Rule engine with an exception ledger and supervisor approvals.
- Alerts mirrored from Dot.Mines; acknowledge and resolve pushed back with the outcome recorded.
- Six reports with CSV export.
- Room administration: shift types, downtime reasons, thresholds, operators, equipment roles, members.
- Append-only audit log on every operational action.
- An agent hub (Claude-backed agents, conversations, usage logs) kept under its own navigation section.

## Stack

| Layer | Technology |
|---|---|
| Framework | Laravel 13, PHP 8.3+ |
| UI | Livewire 3, Alpine.js, Tailwind CSS via Vite |
| Database | PostgreSQL in production, SQLite in tests |
| Auth | Jetstream teams, Sanctum, ecosystem sign-in from the InfoDot hub |
| Dot.Mines | `/api/v1` bearer token per control room, scheduled sync, signed webhooks |
| Agents | Anthropic Messages API through `App\Services\AgentChatService` |

## Getting started

```bash
git clone https://github.com/sakhileb/Dot.Central.git
cd Dot.Central
cp .env.example .env
composer install
npm install && npm run build
php artisan key:generate
php artisan migrate
php artisan db:seed
php artisan serve
```

Locally, `db:seed` creates a pilot control room with example users; see `database/seeders/LocalPilotSeeder.php`. Connecting a room to Dot.Mines needs a token minted on the Dot.Mines side (`integration:mint-token`) and is entered on the room's connection page, never in source or configuration files.

## Checks

```bash
php artisan test --compact
vendor/bin/pint --dirty --format agent
vendor/bin/phpstan analyse
```

`ProhibitedReferencesTest` fails the build if any customer, site or person name appears in the code or the product documents.

## Documents

| File | Purpose |
|---|---|
| `docs/dot-central/01` to `07` | Requirements, current state, architecture, Dot.Mines integration, feature matrix, roadmap, security |
| `docs/dot-central/08` | Deployment and recovery |
| `docs/dot-central/09` | Requests made of Dot.Mines and their status |
| `docs/dot-central/10` | Outstanding issues register |
| `docs/dot-central/11` to `13` | Operator experience, derived operations, shift line-up |

## License

MIT, SK Digital / BluPin Incorporated.
