<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        // The shared sign-in package's own suite (packages/dot-sso-client/tests) opens /tasks/N deep links; this app has no
        // such page, so the test run (only) allows that one path in addition to the app's real allowlist.
        config(['dotsso.deep_link.allow' => [...(array) config('dotsso.deep_link.allow', []), '#^/tasks(/|\?|$)#']]);
    }
}
