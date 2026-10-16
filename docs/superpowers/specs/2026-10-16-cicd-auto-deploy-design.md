# CI/CD: automatic deployment of Dot.Central and Dot.Mines

**Date:** 2026-10-16. **Owner decisions:** keep the shared cPanel hosting account and the GitHub Actions over SSH pattern already live for Dot.Mines, Dot.Doc and Dot.Dopemine; Dot.Central runs on SQLite because almost all of its data is rebuilt from Dot.Mines; deploy on every merge to `main` once CI is green.

## 1. Goal

A merge to `main` in either repository becomes a production deployment without a manual click, and a red CI run can never deploy. The first Dot.Central deployment is a one-time, confirmed bootstrap that replaces the static "Coming Soon" page at `central.infodot.co.za`.

## 2. Hosting facts the pipeline relies on

- Both domains resolve to the same cPanel account. The deploy user is the account user; there is no sudo, no systemd and no supervisor.
- The docroot of a subdomain is `~/<domain>`; the application lives in a subdirectory of it (`~/<domain>/<app>`), so the docroot gets an `index.php` shim that requires `<app>/public/index.php`, a copy of `public/.htaccess`, and symlinks for the static entries of `public/`.
- Because the application directory is inside the docroot, a root `.htaccess` in the repository must deny every web request under it (Dot.Doc lesson: the SQLite file and logs were downloadable).
- `php` on the account is new enough for Laravel 13 (Dot.Doc runs on it).
- Dot.Mines commits `public/build`; Dot.Central does not, so its assets are built on the runner and shipped with rsync.

## 3. Dot.Mines

One change: `deploy.yml` gains an automatic trigger. It runs when the workflow "CI - Security & Static Analysis" completes with success on `main` (`workflow_run`), and keeps `workflow_dispatch` with the typed confirmation. The deploy steps themselves are unchanged. The repository's Quality Gate job is the gate; adding it as a required status check on `main` is recommended but is an owner action.

## 4. Dot.Central

### 4.1 `ci.yml`
Triggers: pull requests and pushes to `main`. Jobs: test suite (PHP 8.5, SQLite in memory, `npm ci && npm run build` first because views need the Vite manifest), Pint `--test`, PHPStan against `phpstan-baseline.neon`, Composer and npm audits (report only, `continue-on-error`), and a `Quality Gate (all checks must pass)` job that needs the blocking jobs. `ProhibitedReferencesTest` runs inside the suite.

### 4.2 `bootstrap.yml` (manual, typed confirmation `bootstrap`, idempotent)
1. Build assets on the runner.
2. Over SSH: clone or reset the repository at `DEPLOY_PATH`, `composer install --no-dev`.
3. Write `.env` only if none exists: `APP_ENV=production`, `APP_URL=https://central.infodot.co.za`, `DB_CONNECTION=sqlite`, database queue, cache and sessions, `LOG_CHANNEL=daily`, `MAIL_MAILER=log`, `SESSION_SECURE_COOKIE=true`; then `key:generate`. No value in it is secret, so it is written in a quoted heredoc on the server.
4. Create `database/database.sqlite`, migrate, cache config, routes, views and events.
5. Docroot wiring: move any existing `index.html` aside as `index.coming-soon.bak`, write the shim, copy `.htaccess`, symlink `build`, `images`, favicons, `robots.txt`.
6. Cron, added once with a marker comment: `schedule:run` every minute; `queue:work --stop-when-empty --tries=3 --max-time=50` every minute; a nightly `sqlite3 .backup` of the database into `storage/backups` keeping 14 copies.
7. rsync `public/build/` into `DEPLOY_PATH/public/build/`.
8. Health check: `GET /up` must return 200.

### 4.3 `deploy.yml`
Triggers: `workflow_run` of `CI` on `main` with success, plus manual dispatch with confirmation. Steps: build assets on the runner; over SSH `artisan down`, `git reset --hard origin/main`, `composer install --no-dev`, `migrate --force`, caches; rsync `public/build/`; refresh symlinks; `queue:restart`; `artisan up`; health check `GET /up`. Concurrency group `central-production-deploy`, never cancelled.

### 4.4 `server-logs.yml`
Manual, read-only tail of the newest Laravel log, identical in shape to Dot.Mines.

### 4.5 SQLite settings
`config/database.php` sqlite connection gains `busy_timeout` 5000 ms and `journal_mode` WAL so the scheduler, the worker and web requests can write concurrently. Verified by a unit test on the config.

### 4.6 Repository root `.htaccess`
`Require all denied` plus a rewrite to 403, with a feature test guarding presence and content.

### 4.7 Documentation
`docs/dot-central/08-deployment-and-recovery.md` §2 and §3 rewritten to describe the real pipeline, the SQLite decision and the backup cron. The register notes I-32 as resolved by the first green bootstrap, not by this PR.

## 5. Secrets the owner sets on Dot.Central
`DEPLOY_HOST` (`central.infodot.co.za`), `DEPLOY_USER`, `DEPLOY_PATH` (`/home/<account>/central.infodot.co.za/central`), `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS` (`ssh-keyscan -H central.infodot.co.za`). The same key and user as Dot.Mines may be reused.

## 6. Order of operations
1. Merge the Dot.Central PR (workflows, root `.htaccess`, SQLite settings, docs). Nothing deploys yet.
2. Owner sets the five secrets, runs `bootstrap.yml` once, opens `/up` and the login page.
3. Merge the Dot.Mines PR; its next merge to `main` deploys automatically.
4. From then on every green merge deploys itself.

## 7. Out of scope
Blue-green releases, PostgreSQL, Reverb (Dot.Central polls), branch protection changes, Dot.Mines deploy step changes.
