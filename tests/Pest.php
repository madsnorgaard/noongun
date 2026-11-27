<?php

use App\Domain\Content\Media\ImageEngine;
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

        // The application processes images with libvips. A container
        // without it runs the tests with GD. A test that takes the
        // dataset "image engines" runs with the engine it was given.
        if (! ImageEngine::Vips->available()) {
            config(['noongun.media.engine' => ImageEngine::Gd->value]);
        }

        foreach ($this->providedData() as $value) {
            if ($value instanceof ImageEngine) {
                useImageEngine($value);
            }
        }
    })
    ->in('Feature');
