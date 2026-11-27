<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\AttachedMediaMissing;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
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
     * link_title and link_description are the card for the link on a
     * platform that builds none itself. Left out, they are stored as empty.
     *
     * media is the images of the text, in the order they are shown: the id
     * of each stored image and its alternative text. An empty list takes
     * every image off. Without the key the images are left as they are, so
     * that a caller that knows nothing of images cannot remove them. An
     * image is attached, never copied: the same id on two texts is one file.
     *
     * @param  list<array{channel_id: int, body: string, link: string|null, link_title?: string|null, link_description?: string|null, media?: list<array{id: int, alt_text?: string|null}>}>  $variants
     *
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws AttachedMediaMissing an image is not in the workspace or no longer exists
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
     * @param  list<array{channel_id: int, body: string, link: string|null, link_title?: string|null, link_description?: string|null, media?: list<array{id: int, alt_text?: string|null}>}>  $variants
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
            $content = [
                'body' => $input['body'],
                'link' => $input['link'],
                'link_title' => $input['link_title'] ?? null,
                'link_description' => $input['link_description'] ?? null,
            ];

            if ($variant === null) {
                $variant = $post->variants()->create(['channel_id' => $channelId, ...$content]);
            } elseif ($variant->isPublished()) {
                continue;
            } else {
                $variant->fill($content)->save();
            }

            if (array_key_exists('media', $input)) {
                $this->syncMedia($variant, $input['media']);
            }
        }
    }

    /**
     * Makes the images of a variant what the list says, in its order. An
     * attachment that stays keeps what the platform already holds of it,
     * unless its alternative text changed.
     *
     * @param  list<array{id: int, alt_text?: string|null}>  $media
     *
     * @throws AttachedMediaMissing
     */
    private function syncMedia(PostVariant $variant, array $media): void
    {
        $wanted = [];

        foreach ($media as $item) {
            $wanted[$item['id']] = self::altText($item['alt_text'] ?? null);
        }

        // Read under a lock that the clean-up of unused images waits for:
        // an image that is found here cannot be removed before this
        // transaction ends. The scope keeps it to the current workspace.
        $found = Media::query()->whereKey(array_keys($wanted))->sharedLock()->pluck('id')->all();
        $missing = array_values(array_diff(array_keys($wanted), $found));

        if ($missing !== []) {
            throw AttachedMediaMissing::for($missing);
        }

        // In use again from this moment. Without this an image that the
        // clean-up once saw unused would keep that old note, and would be
        // removed at the next run if it were taken off again before then.
        Media::query()->whereKey($found)->whereNotNull('unreferenced_since')->update(['unreferenced_since' => null]);

        $existing = $variant->attachments()->get()->keyBy('media_id');

        foreach ($existing as $mediaId => $attachment) {
            if (! array_key_exists($mediaId, $wanted)) {
                $attachment->delete();
            }
        }

        $position = 0;

        foreach ($wanted as $mediaId => $altText) {
            $position++;
            $attachment = $existing->get($mediaId);

            if ($attachment === null) {
                $variant->attachments()->create(['media_id' => $mediaId, 'position' => $position, 'alt_text' => $altText]);

                continue;
            }

            $attachment->fill(['position' => $position, 'alt_text' => $altText]);

            if ($attachment->isDirty()) {
                $attachment->save();
            }
        }

        $variant->unsetRelation('attachments');
    }

    private static function altText(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : '';

        return $value !== '' ? $value : null;
    }

    private function authorizeNew(User $actor): void
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw new PostActionNotAllowed("User #{$actor->getKey()} may not write posts in workspace #{$workspace->id}.");
        }
    }
}
