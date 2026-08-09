<?php

use App\Console\Commands\ScanStaleSessions;
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
