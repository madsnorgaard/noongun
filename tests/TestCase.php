<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A page test must not depend on public/build: the manifest of a
        // checkout can be older than the newest page, and then every test
        // that renders that page fails for a reason that is not in the
        // code. That a page component exists is still checked, by Inertia
        // itself, against resources/js/pages (inertia.testing.ensure_pages_exist).
        $this->withoutVite();
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
