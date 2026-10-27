<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // These tests assert Inertia props and status codes, never asset URLs
        // — but rendering app.blade.php runs @vite, which needs a manifest that
        // only exists after `npm run build`. That made the suite pass or fail
        // depending on whether the person running it had happened to build the
        // frontend first, which is not a property a test suite should have.
        $this->withoutVite();
        // A pre-existing SSR bundle must not make these PHP integration tests
        // contact an external renderer. The browser controls build and exercise
        // the frontend separately; PHP tests assert the Inertia response props.
        config(['inertia.ssr.enabled' => false]);
    }
}
