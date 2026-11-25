<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

require_once __DIR__.'/Support/workspaces.php';
require_once __DIR__.'/Support/channels.php';
require_once __DIR__.'/Support/drafting.php';

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // Project rule 6: tests never call a real external API. A request
        // that no fake answers fails the test instead of leaving the machine.
        Http::preventStrayRequests();
    })
    ->in('Feature');
