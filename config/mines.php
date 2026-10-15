<?php

/*
|--------------------------------------------------------------------------
| Dot.Mines integration
|--------------------------------------------------------------------------
| Credentials are never configured here: each control room holds its own
| Dot.Mines connection (mines_connections, encrypted). These values tune
| the synchronisation. See docs/dot-central/04-dot-mines-integration.md.
*/

return [
    // How often the scheduler dispatches a sync per usable connection.
    'sync_interval_minutes' => (int) env('MINES_SYNC_INTERVAL_MINUTES', 5),

    // Page size for list endpoints (Dot.Mines caps at 100).
    'page_size' => (int) env('MINES_PAGE_SIZE', 100),

    // HTTP timeout in seconds for every request to Dot.Mines.
    'request_timeout' => (int) env('MINES_REQUEST_TIMEOUT', 10),

    // Machines whose engine hours are refreshed per run (one metrics call each),
    // kept well inside Dot.Mines' 60 requests/minute budget.
    'hours_batch' => (int) env('MINES_HOURS_BATCH', 20),

    // A full machine-resource refresh (not just the /sync delta) every Nth run.
    'full_refresh_every' => (int) env('MINES_FULL_REFRESH_EVERY', 12),

    // Webhook signatures older or newer than this are rejected.
    'webhook_skew_seconds' => (int) env('MINES_WEBHOOK_SKEW_SECONDS', 300),

    // The longest the synchroniser waits on a 429 before giving up the run.
    'max_retry_after_seconds' => (int) env('MINES_MAX_RETRY_AFTER', 60),
];
