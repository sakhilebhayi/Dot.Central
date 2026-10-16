# Dot.Central: Deployment and Recovery

**Date:** 2026-10-15 · closes F-28 and the deployment half of I-17. Everything here was derived from the code (`routes/console.php`, `config/mines.php`, `app/Jobs`, `IntegrationHealthController`); nothing has been exercised on a server yet: the first staging deployment must walk this list and correct it.

## 1. What a running Dot.Central consists of

| Process | Why it must run | Proof it runs |
|---|---|---|
| Web (PHP-FPM / Octane behind nginx) | The application | the login page |
| `php artisan schedule:run` every minute (cron, one server) | `mines:sync` every `sync_interval_minutes` (default 5), `mines:refresh-hours` every 15 min, `rules:evaluate` every 5 min, stale-session scan hourly, heartbeats | Health page: "Scheduler last ran" (red when older than 3 min) |
| `php artisan queue:work --tries=3 --timeout=120` (supervisor-managed, at least one) | `SyncMinesConnection` and `RefreshEngineHours` are queued; nothing from Dot.Mines arrives without a worker | Health page: "Queue worker last ran" (red when older than 12 min), "Failed queue jobs" |
| Database (PostgreSQL in production; `.env.example` defaults to database queue and cache) | Everything, including the queue and the cache the heartbeats use | migrations status |
| Inbound HTTPS for `POST /webhooks/mines/{connection}` | Dot.Mines webhooks (public URL; Dot.Mines refuses private addresses) | Health page: last delivery per connection |

Real-time is polling only (Livewire `wire:poll`); no Reverb/Echo process is required.

## 2. First deployment

1. `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build` (Vite; the built assets are committed per release, not to git).
2. `.env`: `APP_KEY`, `APP_URL` (used in webhook URLs shown to administrators), database, `QUEUE_CONNECTION=database` or redis, `CACHE_STORE=database` or redis, mail (notifications), `MINES_*` overrides only if the defaults in `config/mines.php` are wrong for the site.
3. `php artisan migrate --force`. All migrations are forward-only safe on a populated database (`add_team_id_to_agents_table` backfills before the NOT NULL change).
4. `php artisan config:cache route:cache view:cache`.
5. Cron entry on exactly one server: `* * * * * cd /app && php artisan schedule:run >> /dev/null 2>&1`.
6. Queue workers under supervisord: `php artisan queue:work --sleep=3 --tries=3 --max-time=3600`.
7. Create the first team and administrator through registration (or `LocalPilotSeeder` locally only).
8. Per control room: an administrator opens Control Rooms → room → Configure connection, pastes a Dot.Mines token minted by a member of the matching Dot.Mines team (`04-dot-mines-integration.md` §2 lists the role the minting user needs), then Health → Test credential → Sync now.
9. In Dot.Mines, register the webhook endpoint `https://<APP_URL>/webhooks/mines/<connection id>` with the secret stored on the connection, events `alert.triggered`, `machine.offline`, `geofence.*`, `maintenance.predicted`.
10. Open Health: both heartbeats green, the connection verified, fleet mirrored.

## 3. Upgrades

`php artisan down --retry=30` → pull → `composer install` → `npm run build` → `migrate --force` → caches → `queue:restart` → `up`. `queue:restart` is mandatory: workers hold old code until restarted.

## 4. Backups and retention

- Database nightly dump plus WAL/point-in-time if available. Tables that cannot be rebuilt from Dot.Mines: `audit_log`, `shifts`, `shift_handovers`, `shift_unit_summaries`, `shift_lineups`, `lineup_*`, `shift_operator_assignments`, `downtime_classifications`, `operational_exceptions`, `exception_events`, `alert_events`, `operators`, `control_room_settings`, `shift_types`, `downtime_reasons`, `mines_connections` (encrypted tokens; `APP_KEY` must be backed up with them).
- Mirror tables (`equipment`, `equipment_metrics`, `equipment_status_events`, `production_records`, `mine_areas`, `geofences`, `integration_*`) rebuild from Dot.Mines on the next sync, except `equipment_status_events`, which are observations of transitions and are lost if dropped (stoppage history for closed shifts is preserved in `shift_unit_summaries`).
- Keep `integration_webhook_deliveries` and `integration_sync_runs` for at least 90 days; prune older rows with a scheduled job when volume requires it (not built).

## 5. Secrets

- `APP_KEY` encrypts Dot.Mines tokens and webhook secrets; rotating it requires re-entering every connection token.
- Dot.Mines tokens: re-mint per connection when the minting user leaves the Dot.Mines team. Health shows `credential_invalid` and a system alert is raised on HTTP 401/403.
- Never put tokens in logs: `MinesSynchroniser::fail()` sanitises error text; keep it that way.

## 6. Recovery playbook

| Symptom (Health page) | Likely cause | Action |
|---|---|---|
| Scheduler last ran: never / red | cron missing or `schedule:run` failing | fix cron; run `php artisan schedule:run` by hand and read the output |
| Queue worker last ran: red, failed jobs 0 | no worker | start/restart workers (`queue:restart`) |
| Failed queue jobs > 0 | sync/hours job exceptions | `php artisan queue:failed`, read the exception, `queue:retry all` after the cause is fixed |
| Connection `credential_invalid` | token revoked or user left the team | mint a new token, Test credential, Sync now |
| Stale/missing fleet everywhere, connection verified | Dot.Mines down or provider feed stopped | nothing to do in Dot.Central; machines show Stale/Unknown, never "stopped" |
| Mirror corrupted | bad data | set the connection's `sync_cursor` to 0 (tinker) and Sync now; a full `/machines` refresh runs every `full_refresh_every` runs anyway |
| Shift closed by mistake | none | supervisor: End Shift → Reopen with a reason (audited) |
| Webhook deliveries failing | wrong secret or URL | compare the secret on the connection with the Dot.Mines endpoint; replays are ignored by delivery id |

## 7. Local development

`php artisan migrate && php artisan db:seed` creates the pilot (`LocalPilotSeeder`, local environment only: administrator, supervisor, operator, a provisioned room, two operators; no Dot.Mines token). Dot.Mines runs from `~/Dot/mines` (launch entry `mines`, port 8010); mint a token for its fixture team and paste it on the connection page. Webhooks cannot be received locally (Dot.Mines rejects private URLs); `MinesWebhookTest` covers the receiver with signed fixtures.
