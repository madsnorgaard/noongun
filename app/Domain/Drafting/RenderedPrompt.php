<?php

namespace App\Domain\Drafting;

/**
 * The two texts a provider is sent for one request, and the version of the
 * templates they came from.
 *
 * $marksPerformance says whether the prompt tells the model which examples
 * did well. PromptRenderer finds out by rendering, not from the name of
 * the version, so it is true only when the request holds such an example
 * and the template of this version makes something of it.
 */
final readonly class RenderedPrompt
{
    public function __construct(
        public string $system,
        public string $user,
        public string $version,
        public bool $marksPerformance = false,
    ) {}
}
