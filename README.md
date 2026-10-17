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
| UI | Livewire 3, Alpine.js, Tailwind CSS 4 via Vite |
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

## Tailwind CSS 4

Tailwind is v4, built by `@tailwindcss/vite` (`vite.config.js`). There is no `tailwind.config.js`, `postcss.config.js` or autoprefixer: everything that used to be config lives in `resources/css/app.css`.

- `@import 'tailwindcss' source(none)` plus four `@source` lines (the old `content` globs, nothing wider: automatic detection would scan `app/`, `docs/` and `tests/`), `@plugin` for forms and typography, and `@custom-variant dark (&:where(.dark, .dark *))` so night stays the `dark` class on `<html>`.
- **The cascade is the v3 one on purpose.** The shell's `@layer base` block (tokens, `:root.dark`) comes after Tailwind's preflight, utilities sit above it, and the shell's plain classes stay unlayered, so they sit above every layer exactly as they sat after `@tailwind utilities` in v3. Do not wrap the plain classes in a layer.
- **Every v4 default that moved a pixel is put back**, each with a comment in `app.css`: border colour (gray-200, not `currentColor`), pointer cursor on buttons, placeholder colour, `hover:` on touch screens, `ring` (3px blue-500/50), the `shadow`/`rounded`/`blur` scale renames, the transparent `outline-none`, the v3 `transition` property lists, the browser's own padding on `th`/`td`/`option`/the file button, `ltr:`/`rtl:` needing an explicit `dir`, the v3 `font-sans`/`font-display`/`font-mono` stacks (declared `@theme inline`, because the shell defines its own `--font-*` variables), the v3 palette hex values (`tailwind-v3-palette.css`; v4 moved to oklch) and the v3 colours of `prose`. The migration's rule is NO visual change: fix a difference in `app.css`, never by redesigning.
- Things v4 does differently that a view can trip over, not compensated: `space-x-*`/`space-y-*` and `divide-*` put the margin or border on every child but the LAST instead of every child but the first (a hidden last child leaves a trailing gap); `bg-opacity-*`, `ring-opacity-*` and friends no longer exist (use `bg-black/5`); `flex-shrink-*`/`flex-grow-*` are `shrink-*`/`grow-*`; the important marker goes last (`grid!`); `scale-*`, `translate-*` and `rotate-*` set their own properties, not `transform`.
- `@tailwindcss/typography` still pins a flagged `postcss-selector-parser`; `package.json` `overrides` pins it to ^7.1.6 so `npm audit --audit-level=moderate` is clean. Leave it until the plugin itself moves.
- Browser floor: Chrome 111, Safari 16.4, Firefox 128 (cascade layers, `@property`, `color-mix`).

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
