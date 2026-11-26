<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\Exceptions\ChannelException;

/**
 * The answer to one read inside a batch request: the decoded body when it
 * was answered, or else how it failed and the exception that a single call
 * would have thrown, which is safe to log.
 */
final readonly class GraphAnswer
{
    /**
     * @param  array<array-key, mixed>  $body  empty unless the read was answered
     */
    public function __construct(
        public GraphOutcome $outcome,
        public array $body = [],
        public ?ChannelException $failure = null,
    ) {}

    public function answered(): bool
    {
        return $this->outcome === GraphOutcome::Answered;
    }
}
