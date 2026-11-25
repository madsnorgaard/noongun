<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Drafting\DraftUsage;

/**
 * The provider answered, but not with a draft that can be used: the output
 * does not match DraftSchema, a channel is missing or unknown, or a text is
 * empty or too long for its platform.
 *
 * Thrown by DraftResult::fromArray(). A generator asks once more and, if the
 * second answer is invalid too, lets this exception out with the usage of
 * both attempts. It is never retryable beyond that.
 *
 * The problems name fields and limits. They never quote the output.
 */
class InvalidDraftOutput extends DraftingException
{
    /**
     * @param  non-empty-list<string>  $problems
     */
    public static function because(string $provider, array $problems, ?DraftUsage $usage = null): static
    {
        return new static(
            $provider,
            'The generated draft could not be used: '.implode(' ', $problems),
            false,
            $usage,
            ['problems' => $problems],
        );
    }

    /**
     * The same failure with the usage of every attempt, for the generator
     * to throw after its last attempt.
     */
    public function withUsage(?DraftUsage $usage): static
    {
        return static::because($this->provider, $this->problems(), $usage);
    }

    /**
     * @return non-empty-list<string>
     */
    public function problems(): array
    {
        $problems = [];

        foreach ((array) ($this->context()['problems'] ?? []) as $problem) {
            if (is_string($problem)) {
                $problems[] = $problem;
            }
        }

        return $problems === [] ? [$this->getMessage()] : $problems;
    }
}
