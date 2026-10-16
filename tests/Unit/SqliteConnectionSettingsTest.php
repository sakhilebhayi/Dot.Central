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
