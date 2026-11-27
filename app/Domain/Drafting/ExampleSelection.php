<?php

namespace App\Domain\Drafting;

/**
 * The examples chosen for one request, the posts they were taken from, and
 * which of those posts were chosen because they did well. The post ids
 * stay on this side: a draft run notes them, a provider is never sent
 * them.
 */
final readonly class ExampleSelection
{
    /**
     * @param  list<ExamplePost>  $examples  in the order of the target channels; for each, those that did well, best first, and then the others, newest first
     * @param  list<int>  $postIds  each post once, in ascending order
     * @param  list<int>  $performancePostIds  those of $postIds with a text that was chosen because it did well and is among $examples, in ascending order
     * @param  int  $performanceLeftOut  how many texts were chosen because they did well and then left out for the limit on characters
     */
    public function __construct(
        public array $examples = [],
        public array $postIds = [],
        public array $performancePostIds = [],
        public int $performanceLeftOut = 0,
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
