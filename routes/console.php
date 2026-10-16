<?php

use App\Console\Commands\BackupSqliteDatabase;
use App\Console\Commands\EvaluateRules;
use App\Console\Commands\RefreshMinesHours;
use App\Console\Commands\ScanStaleSessions;
use App\Console\Commands\SyncMines;
use App\Jobs\QueueHeartbeat;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process -- see
// docs/superpowers/specs/2026-08-09-stale-session-alert-gate.md.
Schedule::command(ScanStaleSessions::class)
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();

// Dot.Mines synchronisation: one queued job per usable connection, every
// few minutes (config/mines.php). See docs/dot-central/04-dot-mines-integration.md §5.
Schedule::command(SyncMines::class)
    ->cron('*/'.max(1, (int) config('mines.sync_interval_minutes', 5)).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

// Engine hours come from Dot.Mines metrics, one call per machine, so a small
// batch per connection every 15 minutes stays inside the API budget.
Schedule::command(RefreshMinesHours::class)
    ->everyFifteenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Rules also run after every sync; this is the backstop for rooms whose
// connection is paused and for time-based rules (unacknowledged alerts,
// overrun shifts).
Schedule::command(EvaluateRules::class)->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Liveness of the platform itself (issue I-17): the Health page shows when
// the scheduler last ticked and when a queue worker last ran a job.
Schedule::call(fn () => Cache::forever('dot.scheduler_heartbeat', now()->toIso8601String()))->everyMinute()->name('scheduler-heartbeat');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('queue-heartbeat');

// Nightly copy of the SQLite file (shifts, line-ups and the audit log are
// not rebuilt from Dot.Mines). No-op on other database drivers.
Schedule::command(BackupSqliteDatabase::class)->dailyAt('02:15')->onOneServer();
