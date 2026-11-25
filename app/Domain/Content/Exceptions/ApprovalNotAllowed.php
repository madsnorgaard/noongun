<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Post;
use App\Models\User;
use RuntimeException;

/**
 * The given user is not a member of the post's workspace with a role that
 * may edit content, so cannot approve it.
 */
class ApprovalNotAllowed extends RuntimeException
{
    public static function for(Post $post, User $user): self
    {
        return new self("User #{$user->getKey()} may not approve post #{$post->getKey()}.");
    }
}
