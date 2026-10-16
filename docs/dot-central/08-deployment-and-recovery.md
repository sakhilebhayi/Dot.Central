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

## 2. First deployment (built 2026-10-16, `.github/workflows/bootstrap.yml`)

Dot.Central deploys to the shared cPanel account that already hosts Dot.Mines: the docroot is `~/central.infodot.co.za`, the repository is cloned to `DEPLOY_PATH` (`~/central.infodot.co.za/central`), and the docroot carries an `index.php` shim that requires `central/public/index.php`, a copy of `public/.htaccess`, and symlinks for `build`, `images`, the favicons and `robots.txt`. The repository's root `.htaccess` denies every HTTP request under the application directory (`AppDirectoryIsNotServedTest`); never put anything that must be fetchable inside it.

Database: SQLite (owner decision 2026-10-16: almost everything is rebuilt from Dot.Mines). `config/database.php` sets WAL journaling and a 5 s busy timeout so the scheduler, the worker and web requests can write concurrently (`SqliteConnectionSettingsTest`).

Owner steps, once:

1. Set five repository secrets on `sakhilebhayi/Dot.Central`: `DEPLOY_HOST` (`central.infodot.co.za`), `DEPLOY_USER` (the hosting account user), `DEPLOY_PATH`, `DEPLOY_SSH_KEY` (a dedicated deploy key whose public half is in the account's `authorized_keys`), `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -H central.infodot.co.za`). The Dot.Mines key and user may be reused.
2. Run `gh workflow run bootstrap.yml -f confirm=bootstrap`. It builds the assets on the runner, clones and installs, writes `.env` only if none exists (no secrets in it; `APP_KEY` is generated on the server), creates the database, migrates, caches, wires the docroot (the placeholder page is kept as `index.coming-soon.bak`), installs two cron lines with the absolute path of the PHP binary (cron runs with a minimal PATH): `schedule:run` every minute and `queue:work --stop-when-empty --tries=3 --max-time=50` every minute, each writing its last output to `storage/logs/cron-*.log`; the nightly database copy is the scheduled `central:backup-database` command (SQLite online backup API into `storage/backups`, 14 copies kept). Re-running the bootstrap rewrites the cron lines, ships `public/build`, then checks `GET /up` is 200 and `/central/database/database.sqlite` is refused.
3. Register the first administrator through the registration page, create the control room, paste a Dot.Mines token on the connection page, and register the webhook endpoint in Dot.Mines (`https://central.infodot.co.za/webhooks/mines/<connection id>`).
4. Open Health: both heartbeats green, the connection verified, fleet mirrored.

Issue I-32 closed on 2026-10-16 with the first green bootstrap run (`/up` 200, `/login` 200, `/central/database/database.sqlite` refused with 403, built assets served from the docroot symlink).

## 3. Upgrades (`.github/workflows/deploy.yml`)

Every merge to `main` deploys itself: `deploy.yml` runs when the `CI` workflow finishes with success on `main` (`workflow_run`), so a red CI run never reaches the server. It can also be run by hand with `gh workflow run deploy.yml -f confirm=deploy`. Steps: build assets on the runner, `artisan down`, `git reset --hard origin/main`, `composer install --no-dev`, `migrate --force`, caches, rsync `public/build`, refresh the docroot symlinks, `queue:restart`, `artisan up`, then `GET /up` must be 200. Two deploys never run at once (concurrency group `central-production-deploy`). `server-logs.yml` tails the newest log read-only when a run fails and prints the app's crontab lines, which PHP cron resolves, and the last output of each cron line.

Guarded by `DeploymentWorkflowsTest`: triggers, the quality gate, and that no secret is ever passed as an SSH command-line argument.

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

## 8. Crontab rules for the shared account

The hosting account has one crontab shared by every Dot app. On 2026-08-31 a sibling app's cron pipeline installed an empty crontab when its read failed and Dot.Mines' scheduler was dead for six weeks. Rules: the crontab is written only by deploy workflows; each workflow touches only lines carrying its own marker (`# dot-central`); the read is checked before the write and the result is read back after it, restoring the previous crontab if any other line went missing; never add a line by hand and never pipe into `crontab -`. Both `bootstrap.yml` and `deploy.yml` re-assert Dot.Central's two lines on every run. `/up/scheduler` is a public probe of the two heartbeats (200 fresh, 503 stale) and `scheduler-watchdog.yml` asks it every 15 minutes from GitHub, failing the run so the owner is emailed.

**Addendum 2026-10-16 (crontab reads).** `crontab -l` on the host can transiently answer empty, even under the lock and right after a successful write. The block retries every read up to five times, two seconds apart, and trusts only a non-empty answer or an explicit "no crontab for"; a persistently empty read refuses to write rather than guess. A failed verification still restores the previous crontab, but the deploy stops before `artisan up`, so a failed deploy leaves the site in maintenance mode: re-run the deploy once the cause is understood.
