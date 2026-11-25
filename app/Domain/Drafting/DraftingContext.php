<?php

namespace App\Domain\Drafting;

/**
 * Everything a DraftGenerator needs besides the request: which provider and
 * model to call, with which limits, which prompt version, and the key.
 * Built for one workspace by DraftGenerators::contextFor(). Every value
 * comes from config/noongun.php or from a stored credential.
 */
final readonly class DraftingContext
{
    public function __construct(
        public string $provider,
        public string $model,
        public int $maxTokens,
        public int $timeoutSeconds,
        public string $promptVersion,
        public LlmKey $key,
    ) {}
}
