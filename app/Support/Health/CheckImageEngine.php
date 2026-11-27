<?php

namespace App\Support\Health;

use App\Domain\Content\Media\MediaConfig;
use RuntimeException;

/**
 * Makes /up fail when the configured image engine is not in the container,
 * so that an image built without it is reported as unhealthy when it
 * starts, with a sentence in the log that says what to do, and not by the
 * first person who uploads a picture.
 */
class CheckImageEngine
{
    public function handle(): void
    {
        $engine = MediaConfig::engine();

        if (! $engine->available()) {
            throw new RuntimeException($engine->missing());
        }
    }
}
