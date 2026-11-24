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
 * are on their platform keep their result; the others are marked as failed
 * with a text that tells the user to look at the channel first, because the
 * last call may have gone through without its result being recorded. The
 * post then becomes published or failed through finishPublishing(), and only
 * a human can send it again.
 *
 * Runs inside the post's workspace (CurrentWorkspace::run()).
 */
class SettleInterruptedPost
{
    public const MESSAGE = 'Publishing was interrupted before a result was recorded. Check the channel for this post before you try again: it may already be there.';

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
                $variant->markFailed(match (true) {
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
