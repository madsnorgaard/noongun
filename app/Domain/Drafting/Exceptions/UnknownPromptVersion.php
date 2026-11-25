<?php

namespace App\Domain\Drafting\Exceptions;

use RuntimeException;

/**
 * The prompt version has no templates in resources/prompts/, or is not a
 * name a version can have.
 */
class UnknownPromptVersion extends RuntimeException
{
    public static function malformed(string $version): self
    {
        return new self("[{$version}] is not a prompt version. Use lower case letters, digits, hyphens and underscores, for example v1.");
    }

    public static function missing(string $prompt, string $version, string $part): self
    {
        return new self("Prompt [{$prompt}] has no version [{$version}]: resources/prompts/{$prompt}/{$version}/{$part}.blade.php does not exist.");
    }
}
