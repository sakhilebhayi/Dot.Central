# CI/CD Auto-Deploy Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Every green merge to `main` deploys Dot.Central and Dot.Mines to the shared cPanel host; Dot.Central gets a one-time confirmed bootstrap.

**Architecture:** GitHub Actions over SSH with git-based releases (the pattern live for Dot.Mines, Dot.Doc and Dot.Dopemine). Dot.Central assets are built on the runner and shipped with rsync; the cPanel docroot is wired to the app with an `index.php` shim and symlinks; the scheduler, an idle-exiting queue worker and a nightly SQLite backup run from cron. Deploy workflows trigger on `workflow_run` of the CI workflow finishing green on `main`.

**Tech Stack:** GitHub Actions, bash over ssh/rsync, Laravel 13 on PHP 8.5, SQLite (WAL), PHPUnit tests that parse the workflow YAML with `symfony/yaml` (already installed through Laravel).

## Global Constraints

- Spec: `docs/superpowers/specs/2026-10-16-cicd-auto-deploy-design.md`.
- No customer, site or person names anywhere (`ProhibitedReferencesTest`).
- No secrets in source, logs or chat. Secret values are never passed as SSH command arguments; only `DEPLOY_PATH` is.
- Dot.Central `public/build` is not committed; Dot.Mines `public/build` is.
- `APP_URL=https://central.infodot.co.za`; `DB_CONNECTION=sqlite`; queue, cache and sessions on the database driver.
- Health check endpoint: `GET /up` (Laravel health route configured in `bootstrap/app.php`).
- Pint, PHPStan and the full suite must pass before each commit.

---

### Task 1: Deny web access to the application directory (Dot.Central)

**Files:**
- Create: `.htaccess` (repository root)
- Test: `tests/Feature/AppDirectoryIsNotServedTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * On the shared host the application directory sits inside the docroot,
 * so this file is the only thing between the internet and the SQLite
 * database, the logs and the source. Presence and content are guarded
 * here; the live check is a request for /central/database/database.sqlite
 * answering 403.
 */
class AppDirectoryIsNotServedTest extends TestCase
{
    public function test_root_htaccess_denies_every_request(): void
    {
        $path = base_path('.htaccess');

        $this->assertFileExists($path);

        $contents = (string) file_get_contents($path);

        $this->assertStringContainsString('Require all denied', $contents);
        $this->assertStringContainsString('RewriteRule ^ - [F,L]', $contents);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/AppDirectoryIsNotServedTest.php`
Expected: FAIL, file does not exist.

- [ ] **Step 3: Create the root `.htaccess`**

```apache
# The application directory lives INSIDE the cPanel docroot
# (~/central.infodot.co.za/central). Nothing under it may be fetched over
# HTTP: PHP reads the filesystem, so the app still boots through the
# docroot's index.php shim. Both directives so that either Apache 2.4
# authorisation or mod_rewrite alone is enough to refuse the request.
Require all denied

<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteRule ^ - [F,L]
</IfModule>
```

- [ ] **Step 4: Run the test**

Run: `php artisan test --compact tests/Feature/AppDirectoryIsNotServedTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add .htaccess tests/Feature/AppDirectoryIsNotServedTest.php
git commit -m "Deny web access to the application directory on shared hosting"
```

### Task 2: SQLite settings for concurrent writers (Dot.Central)

**Files:**
- Modify: `config/database.php:41-42`
- Test: `tests/Unit/SqliteConnectionSettingsTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * Production runs on SQLite with three writers (scheduler, queue worker,
 * web). WAL journaling lets readers proceed during a write and the busy
 * timeout makes a second writer wait instead of failing with
 * "database is locked".
 */
class SqliteConnectionSettingsTest extends TestCase
{
    public function test_sqlite_connection_waits_for_locks_and_uses_wal(): void
    {
        $this->assertSame(5000, config('database.connections.sqlite.busy_timeout'));
        $this->assertSame('WAL', config('database.connections.sqlite.journal_mode'));
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Unit/SqliteConnectionSettingsTest.php`
Expected: FAIL, null is not 5000.

- [ ] **Step 3: Change the config**

In `config/database.php` replace

```php
            'busy_timeout' => null,
            'journal_mode' => null,
```

with

```php
            'busy_timeout' => (int) env('DB_BUSY_TIMEOUT', 5000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
```

- [ ] **Step 4: Run the test and the whole suite** (the suite uses an in-memory SQLite database, where the WAL pragma is a no-op)

Run: `php artisan test --compact`
Expected: all pass.

- [ ] **Step 5: Commit**

```bash
git add config/database.php tests/Unit/SqliteConnectionSettingsTest.php
git commit -m "SQLite: WAL journaling and a 5 s busy timeout for concurrent writers"
```

### Task 3: Workflow guard test and `ci.yml` (Dot.Central)

**Files:**
- Create: `.github/workflows/ci.yml`
- Test: `tests/Feature/DeploymentWorkflowsTest.php`

**Interfaces:**
- Produces: the workflow named `CI` with the job `quality-gate` named `Quality Gate (all checks must pass)`; later tasks' `deploy.yml` triggers on this workflow name.

- [ ] **Step 1: Write the failing test** (it also covers Tasks 4 and 5; those assertions fail until their files exist)

```php
<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * The pipeline is configuration, and configuration drifts. These checks
 * pin the parts that make "a green merge deploys itself, a red one never
 * does" true, and the parts that keep secrets off SSH command lines.
 */
class DeploymentWorkflowsTest extends TestCase
{
    /** @return array<string, mixed> */
    private function workflow(string $file): array
    {
        $path = base_path('.github/workflows/'.$file);
        $this->assertFileExists($path);

        /** @var array<string, mixed> $parsed */
        $parsed = Yaml::parseFile($path);

        return $parsed;
    }

    public function test_ci_runs_on_pull_requests_and_main_and_has_a_quality_gate(): void
    {
        $ci = $this->workflow('ci.yml');

        $this->assertSame('CI', $ci['name']);
        $this->assertArrayHasKey('pull_request', $ci['on']);
        $this->assertSame(['main'], $ci['on']['push']['branches']);

        $gate = $ci['jobs']['quality-gate'];
        $this->assertSame('Quality Gate (all checks must pass)', $gate['name']);
        $this->assertEqualsCanonicalizing(['tests', 'code-style', 'static-php'], $gate['needs']);
    }

    public function test_deploy_runs_only_after_ci_succeeds_on_main_or_by_confirmed_dispatch(): void
    {
        $deploy = $this->workflow('deploy.yml');

        $this->assertSame(['CI'], $deploy['on']['workflow_run']['workflows']);
        $this->assertSame(['completed'], $deploy['on']['workflow_run']['types']);
        $this->assertSame(['main'], $deploy['on']['workflow_run']['branches']);
        $this->assertArrayHasKey('workflow_dispatch', $deploy['on']);
        $this->assertArrayNotHasKey('push', $deploy['on']);

        $job = $deploy['jobs']['deploy'];
        $this->assertStringContainsString("github.event.workflow_run.conclusion == 'success'", $job['if']);
        $this->assertSame('central-production-deploy', $deploy['concurrency']['group']);
        $this->assertFalse($deploy['concurrency']['cancel-in-progress']);
    }

    public function test_bootstrap_is_manual_only(): void
    {
        $bootstrap = $this->workflow('bootstrap.yml');

        $this->assertSame(['workflow_dispatch'], array_keys($bootstrap['on']));
        $this->assertSame('central-production-deploy', $bootstrap['concurrency']['group']);
    }

    public function test_server_logs_workflow_is_manual_and_read_only(): void
    {
        $logs = $this->workflow('server-logs.yml');

        $this->assertSame(['workflow_dispatch'], array_keys($logs['on']));
        $this->assertStringNotContainsString('artisan', (string) file_get_contents(base_path('.github/workflows/server-logs.yml')));
    }

    public function test_no_workflow_passes_a_secret_as_an_ssh_argument(): void
    {
        foreach (['bootstrap.yml', 'deploy.yml', 'server-logs.yml'] as $file) {
            $text = (string) file_get_contents(base_path('.github/workflows/'.$file));
            preg_match_all('/^\s*([A-Z_]+)="\$\{\{ secrets\.([A-Z_]+)[^}]*\}\}" \\\\$/m', $text, $matches);
            foreach ($matches[2] as $secret) {
                $this->assertSame('DEPLOY_PATH', $secret, "$file passes secrets.$secret on the ssh command line");
            }
        }
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --compact tests/Feature/DeploymentWorkflowsTest.php`
Expected: FAIL on every test, files missing.

- [ ] **Step 3: Create `.github/workflows/ci.yml`**

```yaml
name: CI

on:
  push:
    branches: [main]
  pull_request:

jobs:
  tests:
    name: Test suite
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: pdo_sqlite, sqlite3, bcmath, gd, zip, mbstring, intl, pcntl
          coverage: none

      - name: Install composer dependencies
        run: composer install --no-interaction --no-progress --prefer-dist

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      # Views reference the Vite manifest, so the suite needs a build.
      - name: Build frontend assets
        run: |
          npm ci --silent
          npm run build

      - name: Generate throwaway CI app key
        run: echo "APP_KEY=base64:$(openssl rand -base64 32)" >> "$GITHUB_ENV"

      - name: Run the test suite
        run: php artisan test --compact

  code-style:
    name: Pint (code style)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: bcmath, mbstring
          coverage: none
      - name: Install composer dependencies
        run: composer install --no-interaction --no-progress --prefer-dist
      - name: Run Pint
        run: vendor/bin/pint --test

  static-php:
    name: PHPStan (static analysis)
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          extensions: bcmath, mbstring
          coverage: none
      - name: Install composer dependencies
        run: composer install --no-interaction --no-progress --prefer-dist
      - name: Run PHPStan
        run: vendor/bin/phpstan analyse --memory-limit=1G --error-format=github

  composer-audit:
    name: Composer dependency audit
    runs-on: ubuntu-latest
    # Reports advisories, never blocks: a dependency upgrade is a separate
    # decision, not something an unrelated PR should be forced into.
    continue-on-error: true
    steps:
      - uses: actions/checkout@v4
      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: '8.5'
          coverage: none
      - name: Install composer dependencies
        run: composer install --no-interaction --no-progress --prefer-dist
      - name: composer audit
        run: composer audit

  npm-audit:
    name: npm dependency audit
    runs-on: ubuntu-latest
    continue-on-error: true
    steps:
      - uses: actions/checkout@v4
      - uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm
      - name: npm audit
        run: npm audit --audit-level=moderate

  quality-gate:
    name: Quality Gate (all checks must pass)
    runs-on: ubuntu-latest
    needs: [tests, code-style, static-php]
    if: always()
    steps:
      - name: Fail if any blocking job did not succeed
        run: |
          echo "tests=${{ needs.tests.result }} code-style=${{ needs.code-style.result }} static-php=${{ needs.static-php.result }}"
          [ "${{ needs.tests.result }}" = "success" ] || exit 1
          [ "${{ needs.code-style.result }}" = "success" ] || exit 1
          [ "${{ needs.static-php.result }}" = "success" ] || exit 1
```

- [ ] **Step 4: Run the first test method**

Run: `php artisan test --compact --filter=test_ci_runs_on_pull_requests_and_main_and_has_a_quality_gate`
Expected: PASS (the other methods still fail until Tasks 4 and 5).

- [ ] **Step 5: Commit**

```bash
git add .github/workflows/ci.yml tests/Feature/DeploymentWorkflowsTest.php
git commit -m "CI workflow with a quality gate; guard tests for the pipeline"
```

### Task 4: `bootstrap.yml` (Dot.Central, one-time first deployment)

**Files:**
- Create: `.github/workflows/bootstrap.yml`

**Interfaces:**
- Consumes: secrets `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_PATH`, `DEPLOY_SSH_KEY`, `DEPLOY_KNOWN_HOSTS`.
- Produces: the server layout `deploy.yml` assumes (`.env`, database, docroot shim, cron lines).

- [ ] **Step 1: Create the workflow**

```yaml
name: Bootstrap Production

# One-time first deployment of Dot.Central to central.infodot.co.za on the
# shared cPanel account, following the layout live for Dot.Mines and
# Dot.Dopemine: the docroot is ~/central.infodot.co.za, the repository
# lives at <docroot>/central (DEPLOY_PATH), and the docroot gets an
# index.php shim, a copy of public/.htaccess and symlinks into
# central/public. SQLite by decision (2026-10-16): almost everything is
# rebuilt from Dot.Mines; what is not (shifts, line-ups, audit log) is
# copied nightly by cron. Safe to re-run: every step is idempotent and
# .env is never overwritten.
on:
  workflow_dispatch:
    inputs:
      confirm:
        description: 'Type "bootstrap" to confirm first-time production setup'
        required: true

concurrency:
  group: central-production-deploy
  cancel-in-progress: false

jobs:
  bootstrap:
    name: Bootstrap central.infodot.co.za
    runs-on: ubuntu-latest
    timeout-minutes: 20

    steps:
      - name: Refuse unconfirmed runs
        if: ${{ github.event.inputs.confirm != 'bootstrap' }}
        run: |
          echo '::error::Confirmation input was not "bootstrap" -- aborting.'
          exit 1

      - uses: actions/checkout@v4

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      - name: Build frontend assets
        run: |
          npm ci --silent
          npm run build

      - name: Set up SSH
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.DEPLOY_SSH_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          printf '%s\n' "${{ secrets.DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

      - name: Clone, install, configure, migrate, wire the docroot
        run: |
          ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes \
              "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}" \
              DEPLOY_PATH="${{ secrets.DEPLOY_PATH }}" \
              'bash -s' <<'BOOTSTRAP'
          set -euo pipefail
          APP="$DEPLOY_PATH"
          DOCROOT="$(dirname "$APP")"
          PHP=php

          echo "== PHP: $($PHP -v | head -1)"
          mkdir -p "$DOCROOT"

          if [ ! -d "$APP/.git" ]; then
            git clone https://github.com/sakhilebhayi/Dot.Central.git "$APP"
          else
            git -C "$APP" fetch origin main
            git -C "$APP" reset --hard origin/main
          fi
          cd "$APP"

          composer install --no-interaction --optimize-autoloader --no-dev

          # Nothing in this file is secret: APP_KEY is generated on the
          # server right after, and the database is a local file.
          if [ ! -f .env ]; then
            cat > .env <<'ENV'
          APP_NAME="Dot.Central"
          APP_ENV=production
          APP_KEY=
          APP_DEBUG=false
          APP_URL=https://central.infodot.co.za

          LOG_CHANNEL=daily
          LOG_LEVEL=info

          DB_CONNECTION=sqlite

          SESSION_DRIVER=database
          SESSION_LIFETIME=120
          SESSION_SECURE_COOKIE=true

          CACHE_STORE=database
          QUEUE_CONNECTION=database

          MAIL_MAILER=log
          ENV
            chmod 600 .env
            $PHP artisan key:generate --force
          fi

          mkdir -p database storage/backups
          touch database/database.sqlite

          $PHP artisan migrate --force
          $PHP artisan config:cache
          $PHP artisan route:cache
          $PHP artisan view:cache
          $PHP artisan event:cache

          # Docroot wiring: keep the static placeholder page as a backup,
          # then shim, rewrite rules and asset links (idempotent).
          if [ -f "$DOCROOT/index.html" ]; then
            mv -f "$DOCROOT/index.html" "$DOCROOT/index.coming-soon.bak"
          fi
          cat > "$DOCROOT/index.php" <<'SHIM'
          <?php require __DIR__.'/central/public/index.php';
          SHIM
          cp "$APP/public/.htaccess" "$DOCROOT/.htaccess"
          for target in build images favicon-16x16.png favicon-32x32.png apple-touch-icon.png robots.txt; do
            ln -sfn "central/public/${target}" "$DOCROOT/${target}"
          done

          # Cron: scheduler every minute, a queue worker that exits when
          # idle (no supervisor on shared hosting), a nightly SQLite backup
          # keeping 14 copies. Added once, recognised by the marker.
          MARK="# dot-central"
          if ! crontab -l 2>/dev/null | grep -q "$MARK"; then
            {
              crontab -l 2>/dev/null || true
              echo "* * * * * cd $APP && $PHP artisan schedule:run >> /dev/null 2>&1 $MARK scheduler"
              echo "* * * * * cd $APP && $PHP artisan queue:work --stop-when-empty --tries=3 --max-time=50 >> /dev/null 2>&1 $MARK queue"
              echo "15 2 * * * cd $APP && sqlite3 database/database.sqlite \".backup storage/backups/central-\$(date +\\%F).sqlite\" && ls -1t storage/backups/central-*.sqlite | tail -n +15 | xargs -r rm -f $MARK backup"
            } | crontab -
            echo "Cron lines installed."
          else
            echo "Cron lines already present."
          fi

          echo "== Code deployed: $(git rev-parse --short HEAD)"
          BOOTSTRAP

      - name: Transfer built assets
        run: |
          rsync -az --delete -e "ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes" \
            public/build/ \
            "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}:${{ secrets.DEPLOY_PATH }}/public/build/"

      - name: Health check
        run: |
          sleep 3
          code=$(curl -s -o /dev/null -w '%{http_code}' "https://${{ secrets.DEPLOY_HOST }}/up")
          echo "GET /up -> ${code}"
          [ "$code" = "200" ] || { echo "::error::The application did not answer 200 on /up."; exit 1; }
          denied=$(curl -s -o /dev/null -w '%{http_code}' "https://${{ secrets.DEPLOY_HOST }}/central/database/database.sqlite")
          echo "GET /central/database/database.sqlite -> ${denied}"
          [ "$denied" = "403" ] || [ "$denied" = "404" ] || { echo "::error::The application directory is reachable over HTTP."; exit 1; }
```

- [ ] **Step 2: Run the guard test**

Run: `php artisan test --compact --filter=test_bootstrap_is_manual_only`
Expected: PASS.

- [ ] **Step 3: Commit**

```bash
git add .github/workflows/bootstrap.yml
git commit -m "Bootstrap workflow: first deployment of Dot.Central to the shared host"
```

### Task 5: `deploy.yml` and `server-logs.yml` (Dot.Central)

**Files:**
- Create: `.github/workflows/deploy.yml`
- Create: `.github/workflows/server-logs.yml`

- [ ] **Step 1: Create `deploy.yml`**

```yaml
name: Deploy to Production

# Runs automatically after the CI workflow finishes GREEN on main (every
# merge), and manually from the Actions tab with a typed confirmation to
# redeploy the current main. Run bootstrap.yml once first on a new server:
# this workflow assumes .env, the database, the docroot wiring and the
# cron lines already exist.
on:
  workflow_run:
    workflows: [CI]
    types: [completed]
    branches: [main]
  workflow_dispatch:
    inputs:
      confirm:
        description: 'Type "deploy" to confirm deploying main to production'
        required: true

concurrency:
  group: central-production-deploy
  cancel-in-progress: false

jobs:
  deploy:
    name: Deploy main to central.infodot.co.za
    runs-on: ubuntu-latest
    timeout-minutes: 15
    # Only a successful CI run deploys; a manual run must be confirmed.
    if: >-
      (github.event_name == 'workflow_run' && github.event.workflow_run.conclusion == 'success')
      || (github.event_name == 'workflow_dispatch' && github.event.inputs.confirm == 'deploy')

    steps:
      - uses: actions/checkout@v4
        with:
          ref: main

      - name: Setup Node
        uses: actions/setup-node@v4
        with:
          node-version: '22'
          cache: npm

      - name: Build frontend assets
        run: |
          npm ci --silent
          npm run build

      - name: Set up SSH
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.DEPLOY_SSH_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          printf '%s\n' "${{ secrets.DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

      - name: Deploy code and run migrations
        run: |
          ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes \
              "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}" \
              DEPLOY_PATH="${{ secrets.DEPLOY_PATH }}" \
              'bash -s' <<'DEPLOY'
          set -euo pipefail
          cd "$DEPLOY_PATH"
          PHP=php
          DOCROOT="$(dirname "$DEPLOY_PATH")"

          echo "== Current release: $(git rev-parse --short HEAD)"
          git fetch origin main

          # Maintenance mode only for the risky window (migrations).
          $PHP artisan down --retry=30 || true

          git reset --hard origin/main
          composer install --no-interaction --optimize-autoloader --no-dev

          $PHP artisan migrate --force

          $PHP artisan config:cache
          $PHP artisan route:cache
          $PHP artisan view:cache
          $PHP artisan event:cache

          for target in build images favicon-16x16.png favicon-32x32.png apple-touch-icon.png robots.txt; do
            ln -sfn "central/public/${target}" "$DOCROOT/${target}"
          done
          DEPLOY

      - name: Transfer built assets
        run: |
          rsync -az --delete -e "ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes" \
            public/build/ \
            "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}:${{ secrets.DEPLOY_PATH }}/public/build/"

      - name: Restart workers and go live
        run: |
          ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes \
              "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}" \
              DEPLOY_PATH="${{ secrets.DEPLOY_PATH }}" \
              'bash -s' <<'RESTART'
          set -euo pipefail
          cd "$DEPLOY_PATH"
          PHP=php
          # The cron-driven worker exits when idle and starts fresh each
          # minute; queue:restart makes a running one finish and exit now.
          $PHP artisan queue:restart
          $PHP artisan up
          echo "== Deployed: $(git rev-parse --short HEAD)"
          RESTART

      - name: Health check
        run: |
          sleep 5
          code=$(curl -s -o /dev/null -w '%{http_code}' "https://${{ secrets.DEPLOY_HOST }}/up")
          echo "GET /up -> ${code}"
          if [ "$code" != "200" ]; then
            echo "::error::Health check failed on /up (HTTP ${code}). The site may still be in maintenance mode or the release is unhealthy."
            exit 1
          fi

      - name: Failure notice
        if: failure()
        run: |
          echo '::error::Deploy failed. If it failed during or after migrations the site may have been left in maintenance mode on purpose -- inspect the server before running `php artisan up`.'
```

- [ ] **Step 2: Create `server-logs.yml`**

```yaml
name: Server Logs

# Read-only: tail the newest Laravel log so failures are diagnosable
# without shell access. Fixed command on purpose; this workflow must never
# become a general remote shell.
on:
  workflow_dispatch:

jobs:
  logs:
    name: Tail latest Laravel log
    runs-on: ubuntu-latest
    timeout-minutes: 5

    steps:
      - name: Set up SSH
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.DEPLOY_SSH_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          printf '%s\n' "${{ secrets.DEPLOY_KNOWN_HOSTS }}" > ~/.ssh/known_hosts

      - name: Tail log
        run: |
          ssh -i ~/.ssh/deploy_key -o StrictHostKeyChecking=yes \
              "${{ secrets.DEPLOY_USER }}@${{ secrets.DEPLOY_HOST }}" \
              DEPLOY_PATH="${{ secrets.DEPLOY_PATH }}" \
              'bash -s' <<'LOGS'
          set -euo pipefail
          cd "$DEPLOY_PATH"
          latest=$(ls -1t storage/logs/*.log 2>/dev/null | head -1 || true)
          if [ -z "$latest" ]; then
            echo "No log files in storage/logs."
            exit 0
          fi
          echo "== $latest (last 100 lines)"
          tail -100 "$latest"
          LOGS
```

- [ ] **Step 3: Run the guard tests and the suite**

Run: `php artisan test --compact tests/Feature/DeploymentWorkflowsTest.php` then `php artisan test --compact`
Expected: all pass.

- [ ] **Step 4: Commit**

```bash
git add .github/workflows/deploy.yml .github/workflows/server-logs.yml
git commit -m "Deploy on green CI; read-only server log workflow"
```

### Task 6: Documentation (Dot.Central)

**Files:**
- Modify: `docs/dot-central/08-deployment-and-recovery.md` §2 and §3
- Modify: `docs/dot-central/10-outstanding-issues.md` row I-32

- [ ] **Step 1: Rewrite §2 "First deployment" and §3 "Upgrades"** to describe the bootstrap and deploy workflows, the five secrets, the SQLite decision with the WAL settings, the cron lines, and that I-32 closes when the first bootstrap is green. Keep the hard rule from the spec: never fetch anything under the application directory over HTTP.

- [ ] **Step 2: Update I-32** to "Pipeline built 2026-10-16; closes on the first green bootstrap".

- [ ] **Step 3: Run `ProhibitedReferencesTest` and commit**

```bash
php artisan test --compact tests/Feature/ProhibitedReferencesTest.php
git add docs/dot-central/08-deployment-and-recovery.md docs/dot-central/10-outstanding-issues.md
git commit -m "Docs: deployment pipeline and SQLite decision"
```

### Task 7: Dot.Mines deploys on green CI

**Files:**
- Modify: `/Users/sakhilebhayi/Dot/mines/.github/workflows/deploy.yml` (trigger and the confirmation step)
- Modify: `/Users/sakhilebhayi/Dot/mines/wiki.md` (change-log row)
- Test: `/Users/sakhilebhayi/Dot/mines/tests/Feature/DeployWorkflowTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

/**
 * A green CI run on main deploys itself; nothing else does. Pins the
 * trigger so a later edit cannot quietly turn pushes into deploys without
 * the gate, or drop the manual path.
 */
class DeployWorkflowTest extends TestCase
{
    public function test_deploy_triggers_on_successful_ci_or_confirmed_dispatch(): void
    {
        /** @var array<string, mixed> $deploy */
        $deploy = Yaml::parseFile(base_path('.github/workflows/deploy.yml'));

        $this->assertSame(['CI - Security & Static Analysis'], $deploy['on']['workflow_run']['workflows']);
        $this->assertSame(['completed'], $deploy['on']['workflow_run']['types']);
        $this->assertSame(['main'], $deploy['on']['workflow_run']['branches']);
        $this->assertArrayHasKey('workflow_dispatch', $deploy['on']);
        $this->assertArrayNotHasKey('push', $deploy['on']);
        $this->assertStringContainsString("workflow_run.conclusion == 'success'", $deploy['jobs']['deploy']['if']);
    }
}
```

- [ ] **Step 2: Run it**

Run: `php artisan test --filter=DeployWorkflowTest`
Expected: FAIL, no `workflow_run` key.

- [ ] **Step 3: Edit the trigger**

Replace the header comment and `on:` block of `deploy.yml` with:

```yaml
# Runs automatically after "CI - Security & Static Analysis" finishes GREEN
# on main (every merge), and manually from the Actions tab (or
# `gh workflow run deploy.yml -f confirm=deploy`) to redeploy the current
# main. A red CI run never deploys: the job's `if` below checks the
# conclusion. Adding "Quality Gate (all checks must pass)" as a required
# status check on main keeps a red PR from being mergeable at all.
on:
  workflow_run:
    workflows: ["CI - Security & Static Analysis"]
    types: [completed]
    branches: [main]
  workflow_dispatch:
    inputs:
      confirm:
        description: 'Type "deploy" to confirm deploying main to production'
        required: true
```

Add to the `deploy` job, after `timeout-minutes: 15`:

```yaml
    if: >-
      (github.event_name == 'workflow_run' && github.event.workflow_run.conclusion == 'success')
      || (github.event_name == 'workflow_dispatch' && github.event.inputs.confirm == 'deploy')
```

Delete the "Refuse unconfirmed runs" step (the `if` above replaces it).

- [ ] **Step 4: Run the test, Pint and the wiki row**

Run: `php artisan test --filter=DeployWorkflowTest && vendor/bin/pint --dirty --format agent`
Add a `0.4.2` change-log row to `wiki.md`: deploy.yml now runs after a green CI run on main; manual path unchanged.

- [ ] **Step 5: Commit on a branch and open the PR**

```bash
git switch -c chore/deploy-on-green-ci main
git add .github/workflows/deploy.yml tests/Feature/DeployWorkflowTest.php wiki.md
git commit -m "Deploy automatically after a green CI run on main"
git push -u origin chore/deploy-on-green-ci
gh pr create --base main --head chore/deploy-on-green-ci --title "Deploy automatically after a green CI run on main"
```

### Task 8: Dot.Central PR

- [ ] **Step 1: Full gates**

Run: `php artisan test --compact && vendor/bin/pint --dirty --format agent && vendor/bin/phpstan analyse`
Expected: all green.

- [ ] **Step 2: Push and open the PR** from `feature/cicd-auto-deploy` to `main`, describing the owner steps: set the five secrets, run `bootstrap.yml` once, open `/up` and the login page.
