<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use LogicException;

/**
 * Content was changed outside the draft state, or on a variant that is
 * already on its platform. What was approved is what gets published.
 */
class PostNotEditable extends LogicException
{
    public static function inState(Post $post): self
    {
        return new self("Post #{$post->getKey()} is {$post->state->value} and can only be edited as a draft. Return it to draft first.");
    }

    public static function originAlreadySet(Post $post): self
    {
        return new self("Post #{$post->getKey()} is not a draft without an origin, so no draft run can be recorded on it.");
    }

    public static function missingPost(PostVariant $variant): self
    {
        return new self("Variant #{$variant->getKey()} has no post in the current workspace.");
    }

    public static function alreadyPublished(PostVariant $variant): self
    {
        return new self("Variant #{$variant->getKey()} is already published and cannot be changed.");
    }

    public static function outcomeUnknown(PostVariant $variant): self
    {
        return new self("Variant #{$variant->getKey()} may already be on its platform. It cannot be removed or moved to another channel.");
    }

    public static function outcomeWrittenDirectly(PostVariant $variant): self
    {
        return new self("The publishing outcome of variant #{$variant->getKey()} cannot be written directly. Use markPublished() or markFailed().");
    }

    public static function movedToAnotherPost(PostVariant $variant): self
    {
        return new self("Variant #{$variant->getKey()} cannot be moved to another post.");
    }

    public static function uploadWrittenDirectly(MediaAttachment $attachment): self
    {
        return new self("The upload of attachment #{$attachment->getKey()} cannot be written directly. Use markUploaded() or forgetUpload().");
    }

    public static function attachmentMoved(MediaAttachment $attachment): self
    {
        return new self("Attachment #{$attachment->getKey()} cannot be moved to another variant or another image. Remove it and add a new one.");
    }

    public static function attachmentWithoutVariant(MediaAttachment $attachment): self
    {
        return new self("Attachment #{$attachment->getKey()} has no variant in the current workspace.");
    }
}
