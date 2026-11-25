<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostPreconditions;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/**
 * Writes a draft by hand: creates one, or replaces the planned time and the
 * per-channel texts of an existing one. It never changes the state of a
 * post, and it only works on a draft.
 *
 * An existing post is read again under a row lock first. A sign-off that
 * holds the same lock while it checks and approves can therefore never be
 * followed, or overtaken, by an edit: what was approved is what is sent.
 *
 * The planned time is an instant; convert the user's wall-clock time with
 * Workspace::toUtc() first. Whether the texts fit their platforms is not
 * checked here, so that unfinished work can be saved. The readiness check
 * runs when the post is approved.
 */
final class SaveDraft
{
    public function __construct(
        private readonly PostPreconditions $preconditions,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * One entry per channel. Variants of channels that are not listed are
     * removed. A variant that is already on its platform is never changed
     * or removed, whatever is passed. A variant whose outcome is unknown
     * (the platform was called and may hold the post) can be reworded but
     * is never removed: it and its error text are the only trace.
     *
     * @param  list<array{channel_id: int, body: string, link: string|null}>  $variants
     *
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws PostNotEditable the post is not a draft
     * @throws ModelNotFoundException the post is gone
     */
    public function handle(User $actor, ?Post $post, ?DateTimeInterface $plannedAt, array $variants): Post
    {
        if ($post === null) {
            $this->authorizeNew($actor);
        } else {
            $this->preconditions->authorize($actor, $post);
        }

        $plannedAt = $plannedAt !== null ? CarbonImmutable::instance($plannedAt)->utc() : null;

        return DB::transaction(function () use ($post, $plannedAt, $variants): Post {
            if ($post === null) {
                $post = Post::query()->create(['scheduled_at' => $plannedAt]);
            } else {
                $post = Post::query()->lockForUpdate()->findOrFail($post->id);

                if (! $post->isEditable()) {
                    throw PostNotEditable::inState($post);
                }

                $post->scheduled_at = $plannedAt;
                $post->save();
            }

            $this->syncVariants($post, $variants);

            return $post;
        });
    }

    /**
     * @param  list<array{channel_id: int, body: string, link: string|null}>  $variants
     */
    private function syncVariants(Post $post, array $variants): void
    {
        $wanted = [];

        foreach ($variants as $variant) {
            $wanted[$variant['channel_id']] = $variant;
        }

        $existing = $post->variants()->get()->keyBy('channel_id');

        foreach ($existing as $channelId => $variant) {
            if (! isset($wanted[$channelId]) && ! $variant->isPublished() && ! $variant->hasUnknownOutcome()) {
                $variant->delete();
            }
        }

        foreach ($wanted as $channelId => $input) {
            $variant = $existing->get($channelId);

            if ($variant === null) {
                $post->variants()->create([
                    'channel_id' => $channelId,
                    'body' => $input['body'],
                    'link' => $input['link'],
                ]);

                continue;
            }

            if ($variant->isPublished()) {
                continue;
            }

            $variant->fill(['body' => $input['body'], 'link' => $input['link']])->save();
        }
    }

    private function authorizeNew(User $actor): void
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw new PostActionNotAllowed("User #{$actor->getKey()} may not write posts in workspace #{$workspace->id}.");
        }
    }
}
