<?php

use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\AssertionFailedError;

/**
 * Tests\TestCase turns Vite off, so that a page test does not depend on a
 * build that may be older than the newest page. These prove that this did
 * not take away the check that matters: a page whose component file does
 * not exist under resources/js/pages still fails its test.
 */
function renderedPage(string $component): TestResponse
{
    return TestResponse::fromBaseResponse(Inertia::render($component)->toResponse(request()));
}

it('still fails a page test when the component of the page does not exist', function () {
    $response = renderedPage('insights/DoesNotExist');

    expect(config('inertia.testing.ensure_pages_exist'))->toBeTrue()
        ->and(fn () => $response->assertInertia(fn (AssertableInertia $page) => $page->component('insights/DoesNotExist')))
        ->toThrow(AssertionFailedError::class);
});

it('passes for a component that exists, without a build', function () {
    renderedPage('Dashboard')->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard'));
});
