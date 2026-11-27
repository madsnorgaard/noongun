<?php

use App\Domain\Content\Media\ImageEngine;

require_once __DIR__.'/../Support/media.php';

/*
 * Every image engine the application can be set to. A test that takes this
 * dataset runs once with each, and is skipped for one that the container
 * does not have. The engine is set before the test starts (see Pest.php)
 * and is also passed to the test, as its last argument.
 *
 *     it('...', function () { ... })->with('image engines');
 *     it('...', function (Closure $bytes, ImageEngine $engine) { ... })->with([...])->with('image engines');
 */
dataset('image engines', [
    'libvips' => [ImageEngine::Vips],
    'GD' => [ImageEngine::Gd],
]);
