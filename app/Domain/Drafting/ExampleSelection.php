<?php

namespace App\Domain\Drafting;

/**
 * The examples chosen for one request, and the posts they were taken from.
 * The post ids stay on this side: a draft run notes them, a provider is
 * never sent them.
 */
final readonly class ExampleSelection
{
    /**
     * @param  list<ExamplePost>  $examples  in the order of the target channels, newest first for each
     * @param  list<int>  $postIds  each post once, in ascending order
     */
    public function __construct(
        public array $examples = [],
        public array $postIds = [],
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function isEmpty(): bool
    {
        return $this->examples === [];
    }
}
