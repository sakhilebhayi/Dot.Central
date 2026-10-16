<?php

return [
    'request_timeout' => (int) env('MEMORY_REQUEST_TIMEOUT', 10),
    'context_timeout' => (int) env('MEMORY_CONTEXT_TIMEOUT', 3),
    'context_cache_minutes' => (int) env('MEMORY_CONTEXT_CACHE_MINUTES', 10),
    'outbox_retention_days' => (int) env('MEMORY_OUTBOX_RETENTION_DAYS', 30),
];
