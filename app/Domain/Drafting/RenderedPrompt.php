<?php

namespace App\Domain\Drafting;

/**
 * The two texts a provider is sent for one request, and the version of the
 * templates they came from.
 */
final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
        public string $version,
    ) {}
}
