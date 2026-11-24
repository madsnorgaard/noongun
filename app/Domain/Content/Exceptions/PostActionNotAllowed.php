<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Post;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The user is not an owner or editor of the post's workspace. Laravel
 * renders an AuthorizationException as a 403 response without further code.
 */
class PostActionNotAllowed extends AuthorizationException
{
    public static function for(Post $post, User $user): self
    {
        return new self("User #{$user->getKey()} may not change post #{$post->getKey()}.");
    }
}
