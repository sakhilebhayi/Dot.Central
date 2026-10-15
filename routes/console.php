<?php

use App\Console\Commands\ScanStaleSessions;
use App\Console\Commands\SyncMines;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
