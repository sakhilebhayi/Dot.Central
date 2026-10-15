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

    // Metrics history fetched per machine per refresh (one request each).
    'metrics_hours_back' => (int) env('MINES_METRICS_HOURS_BACK', 24),
    'metrics_limit' => (int) env('MINES_METRICS_LIMIT', 200),

    // Per-room defaults; administrators override them per control room
    // (control_room_settings). Minutes and hours are plain numbers.
    'room_defaults' => [
        'offline_minutes' => 30,          // EQ-01 offline during a shift
        'stale_minutes' => 60,            // TEL-02 stale telemetry
        'idle_minutes_threshold' => 240,  // EQ-04 idle while engine running
        'zero_loads_hours' => 5,          // EQ-03 haul unit ran without loads
        'classify_after_minutes' => 60,   // DT-01 stoppage left unclassified
        'unack_minutes' => 15,            // AL-01 critical alert unacknowledged
        'engine_hours_budget' => 10,      // reference line on the engine-hours table
        'haul_types' => ['haul_truck', 'adt', 'truck'],
    ],
];
