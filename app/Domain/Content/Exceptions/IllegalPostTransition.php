<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use LogicException;

class IllegalPostTransition extends LogicException
{
    public static function between(Post $post, PostState $from, PostState $to): self
    {
        return new self("Post #{$post->getKey()} cannot move from {$from->value} to {$to->value}.");
    }

    /**
     * The row was no longer in the state the model believed, because another
     * process moved it first.
     */
    public static function stale(Post $post, PostState $expected, PostState $to): self
    {
        return new self("Post #{$post->getKey()} was no longer {$expected->value} when it was moved to {$to->value}.");
    }

    public static function direct(string $attribute): self
    {
        return new self("Post attribute [{$attribute}] cannot be written directly. Use the transition methods on Post.");
    }

    public static function notStartedAsDraft(): self
    {
        return new self('A post must be created as an unapproved draft.');
    }

    public static function missingTime(Post $post): self
    {
        return new self("Post #{$post->getKey()} cannot be scheduled without a time.");
    }
}
