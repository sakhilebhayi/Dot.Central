<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use SQLite3;

class BackupSqliteDatabase extends Command
{
    protected $signature = 'central:backup-database {--keep=14 : How many dated copies to keep}';

    protected $description = 'Copy the SQLite database to storage/backups with the SQLite online backup API, keeping the newest N copies.';

    public function handle(): int
    {
        if (config('database.default') !== 'sqlite') {
            $this->warn('Database connection is not sqlite; nothing to back up.');

            return self::SUCCESS;
        }

        $source = (string) config('database.connections.sqlite.database');

        if ($source === ':memory:' || ! is_file($source)) {
            $this->warn("No SQLite file at {$source}; nothing to back up.");

            return self::SUCCESS;
        }

        $directory = storage_path('backups');

        if (! is_dir($directory) && ! mkdir($directory, 0750, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        $target = $directory.'/central-'.now()->format('Y-m-d').'.sqlite';

        DB::connection('sqlite')->getPdo();

        if (class_exists(SQLite3::class)) {
            $from = new SQLite3($source, SQLITE3_OPEN_READONLY);
            $to = new SQLite3($target);
            $copied = $from->backup($to);
            $to->close();
            $from->close();

            if (! $copied) {
                $this->error('SQLite backup API reported failure.');

                return self::FAILURE;
            }
        } elseif (! copy($source, $target)) {
            $this->error("Could not copy {$source} to {$target}.");

            return self::FAILURE;
        }

        $this->prune($directory, max(1, (int) $this->option('keep')));

        $this->info('Backup written to '.$target);

        return self::SUCCESS;
    }

    private function prune(string $directory, int $keep): void
    {
        $files = glob($directory.'/central-*.sqlite') ?: [];
        rsort($files);

        foreach (array_slice($files, $keep) as $old) {
            @unlink($old);
        }
    }
}
