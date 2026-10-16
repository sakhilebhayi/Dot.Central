<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schedule;
use Tests\TestCase;

/**
 * Shifts, line-ups and the audit log cannot be rebuilt from Dot.Mines, so
 * the production SQLite file is copied nightly by the scheduler. The copy
 * uses SQLite's online backup API (consistent even with writers active)
 * and keeps the newest N files.
 */
class BackupSqliteDatabaseTest extends TestCase
{
    public function test_it_copies_a_file_database_and_prunes_old_copies(): void
    {
        $dir = sys_get_temp_dir().'/central-backup-'.uniqid();
        mkdir($dir);
        $backups = $dir.'/backups';
        mkdir($backups);
        $db = $dir.'/database.sqlite';

        $source = new \SQLite3($db);
        $source->exec('create table t (id integer primary key, name text)');
        $source->exec("insert into t (name) values ('shift')");
        $source->close();

        config()->set('database.connections.sqlite.database', $db);
        $this->app->useStoragePath($dir);

        foreach (['2020-01-01', '2020-01-02', '2020-01-03'] as $day) {
            touch($backups."/central-{$day}.sqlite");
        }

        $this->artisan('central:backup-database', ['--keep' => 2])
            ->expectsOutputToContain('Backup written to')
            ->assertSuccessful();

        $today = $backups.'/central-'.now()->format('Y-m-d').'.sqlite';
        $this->assertFileExists($today);

        $copy = new \SQLite3($today, SQLITE3_OPEN_READONLY);
        $this->assertSame('shift', $copy->querySingle('select name from t'));
        $copy->close();

        $remaining = array_map('basename', glob($backups.'/central-*.sqlite') ?: []);
        sort($remaining);
        $this->assertSame(['central-2020-01-03.sqlite', basename($today)], $remaining);
    }

    public function test_it_does_nothing_for_an_in_memory_database(): void
    {
        $this->artisan('central:backup-database')
            ->expectsOutputToContain('nothing to back up')
            ->assertSuccessful();
    }

    public function test_it_is_scheduled_nightly(): void
    {
        $events = collect(Schedule::events())->map(fn ($e) => [$e->command, $e->expression]);

        $this->assertTrue($events->contains(fn ($e) => str_contains((string) $e[0], 'central:backup-database') && $e[1] === '15 2 * * *'));
    }
}
