<?php

namespace App\Domain\Scheduling;

use App\Domain\Content\Exceptions\IllegalPostTransition;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\Events\PostFailedToPublish;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Log;

/**
 * Ends the publishing of a post whose job will not finish it: the worker
 * died, the job threw, or it was never queued.
 *
 * It never calls a platform and never sends anything again. Variants that
 * are on their platform keep their result. A variant whose outcome is
 * unknown (the platform was called and no result was recorded) is marked as
 * failed with a text that tells the user to look at the channel first. A
 * variant that was never sent in this round says so, and one that the
 * platform refused keeps that error. The post then becomes published or
 * failed through finishPublishing(), and only a human can send it again.
 *
 * Runs inside the post's workspace (CurrentWorkspace::run()).
 */
class SettleInterruptedPost
{
    public const MESSAGE = 'Publishing was interrupted before a result was recorded. Check the channel for this post before you try again: it may already be there.';

    public const NOT_SENT = 'Publishing was interrupted before this channel was tried. Nothing was sent to it.';

    /**
     * Returns the state the post ended in, or null when it was left alone:
     * it is no longer publishing, or a job still holds one of its variants.
     */
    public function handle(Post $post): ?PostState
    {
        /** @var list<Lock> $locks */
        $locks = [];

        try {
            foreach ($post->variants()->unpublished()->orderBy('id')->pluck('id') as $variantId) {
                $lock = VariantLock::for((int) $variantId, PublishingConfig::lockSeconds());

                if (! $lock->get()) {
                    return null;
                }

                $locks[] = $lock;
            }

            // Read again now that no job can be in the middle of a call.
            $post = Post::query()->find($post->id);

            if ($post === null || $post->state !== PostState::Publishing) {
                return null;
            }

            foreach ($post->variants()->unpublished()->orderBy('id')->get() as $variant) {
                // The mark is set, under this lock, before every platform
                // call. Without it nothing from this variant is in doubt.
                $variant->markFailed(match (true) {
                    ! $variant->hasUnknownOutcome() => $variant->error ?? self::NOT_SENT,
                    $variant->error === null => self::MESSAGE,
                    str_starts_with($variant->error, self::MESSAGE) => $variant->error,
                    default => self::MESSAGE.' Last recorded error: '.$variant->error,
                });
            }

            $state = $post->finishPublishing();
        } catch (IllegalPostTransition) {
            return null;
        } finally {
            foreach ($locks as $lock) {
                $lock->release();
            }
        }

        Log::warning('An interrupted post was settled.', [
            'workspace_id' => $post->workspace_id,
            'post_id' => $post->id,
            'state' => $state->value,
        ]);

        if ($state === PostState::Failed) {
            event(new PostFailedToPublish($post->workspace_id, $post->id));
        }

        return $state;
    }
}
