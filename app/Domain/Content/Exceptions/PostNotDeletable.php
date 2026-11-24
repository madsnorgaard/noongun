<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Post;
use LogicException;

/**
 * Only drafts and rejected posts with nothing on a platform can be deleted.
 * Everything else is, or is about to become, a record of something public.
 */
class PostNotDeletable extends LogicException
{
    public static function for(Post $post): self
    {
        return new self("Post #{$post->getKey()} is {$post->state->value} or has a published variant and cannot be deleted.");
    }
}
