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
