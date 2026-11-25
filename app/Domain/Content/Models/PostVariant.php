<?php

namespace App\Domain\Content\Models;

use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Content\Exceptions\VariantAlreadyPublished;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\PostVariantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one post says on one channel, and what became of it there.
 *
 * A variant has no state column. It is published when external_id is set,
 * failed when error is set, and waiting otherwise. Content (body, link, the
 * link card, utm, channel) can only change while the post is a draft and the variant is not
 * published. The outcome columns (external_id, permalink, published_at,
 * error, outcome_unknown_since) are not fillable and are written only by
 * beginAttempt(), markPublished(), markRefused() and markFailed().
 *
 * outcome_unknown_since is the durable record of "this may be public without
 * the app knowing". It is set before a platform is called and cleared only
 * when the platform certainly made no post or the result is recorded. A
 * variant that carries it cannot be deleted or moved to another channel.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $post_id
 * @property int $channel_id
 * @property string $body
 * @property string|null $link
 * @property string|null $link_title
 * @property string|null $link_description
 * @property array<string, string>|null $utm
 * @property string|null $external_id
 * @property string|null $permalink
 * @property CarbonImmutable|null $published_at
 * @property string|null $error
 * @property CarbonImmutable|null $outcome_unknown_since
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Post|null $post
 * @property-read Channel|null $channel
 */
#[Fillable(['post_id', 'channel_id', 'body', 'link', 'link_title', 'link_description', 'utm'])]
#[UseFactory(PostVariantFactory::class)]
class PostVariant extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<PostVariantFactory> */
    use HasFactory;

    private const CONTENT = ['channel_id', 'body', 'link', 'link_title', 'link_description', 'utm'];

    private const OUTCOME = ['external_id', 'permalink', 'published_at', 'error', 'outcome_unknown_since'];

    protected static function booted(): void
    {
        static::saving(function (PostVariant $variant): void {
            if ($variant->isDirty(self::OUTCOME)) {
                throw PostNotEditable::outcomeWrittenDirectly($variant);
            }

            if ($variant->exists && $variant->isDirty('post_id')) {
                throw PostNotEditable::movedToAnotherPost($variant);
            }

            if ($variant->exists && $variant->isDirty('channel_id') && $variant->getOriginal('outcome_unknown_since') !== null) {
                throw PostNotEditable::outcomeUnknown($variant);
            }

            if (! $variant->exists || $variant->isDirty(self::CONTENT)) {
                $variant->guardEditable();
            }
        });

        static::deleting(function (PostVariant $variant): void {
            if ($variant->getOriginal('outcome_unknown_since') !== null) {
                throw PostNotEditable::outcomeUnknown($variant);
            }

            $variant->guardEditable();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'utm' => 'array',
            'published_at' => 'datetime',
            'outcome_unknown_since' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Post, $this>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @param  Builder<PostVariant>  $query
     */
    #[Scope]
    protected function unpublished(Builder $query): void
    {
        $query->whereNull('external_id');
    }

    public function isPublished(): bool
    {
        return $this->external_id !== null;
    }

    public function hasFailed(): bool
    {
        return ! $this->isPublished() && $this->error !== null;
    }

    /**
     * True when a platform was called for this variant and nothing says how
     * that ended: the post may be public. Such a variant is kept, with its
     * error text, until a publish succeeds.
     */
    public function hasUnknownOutcome(): bool
    {
        return ! $this->isPublished() && $this->outcome_unknown_since !== null;
    }

    /**
     * Call this, inside the variant's lock, immediately before the platform
     * is called. From here until markPublished() or markRefused() the
     * outcome counts as unknown, also if the process dies.
     *
     * Returns true when this call set the mark, false when an earlier
     * attempt had already left it. Only the caller that set it may clear it
     * with markRefused().
     *
     * @throws VariantAlreadyPublished
     */
    public function beginAttempt(): bool
    {
        static::guardWorkspace($this);

        $marked = static::query()
            ->whereKey($this->getKey())
            ->whereNull('external_id')
            ->whereNull('outcome_unknown_since')
            ->update(['outcome_unknown_since' => now()]) === 1;

        $this->refresh();

        if ($this->isPublished()) {
            throw VariantAlreadyPublished::for($this);
        }

        return $marked;
    }

    /**
     * Records a failure after which the platform certainly holds no post
     * from this attempt (it answered with a refusal), and clears the mark
     * that beginAttempt() set. Does nothing to a variant that is published.
     */
    public function markRefused(string $error): static
    {
        static::guardWorkspace($this);

        static::query()
            ->whereKey($this->getKey())
            ->whereNull('external_id')
            ->update(['error' => ChannelException::redact($error), 'outcome_unknown_since' => null]);

        return $this->refresh();
    }

    /**
     * Records a successful publish and clears any earlier error. One UPDATE
     * that only matches while external_id is still empty, so a second result
     * for the same variant can never overwrite the first.
     *
     * @throws VariantAlreadyPublished
     */
    public function markPublished(PublishResult $result): static
    {
        static::guardWorkspace($this);

        $updated = static::query()
            ->whereKey($this->getKey())
            ->whereNull('external_id')
            ->update([
                'external_id' => $result->externalId,
                'permalink' => $result->permalink,
                'published_at' => $result->publishedAt,
                'error' => null,
                'outcome_unknown_since' => null,
            ]);

        $this->refresh();

        if ($updated !== 1) {
            throw VariantAlreadyPublished::for($this);
        }

        return $this;
    }

    /**
     * Records why the last publish attempt failed. The text is redacted
     * before it is stored. Does nothing to a variant that is published, and
     * leaves the mark of an unknown outcome as it is.
     */
    public function markFailed(string $error): static
    {
        static::guardWorkspace($this);

        static::query()
            ->whereKey($this->getKey())
            ->whereNull('external_id')
            ->update(['error' => ChannelException::redact($error)]);

        return $this->refresh();
    }

    private function guardEditable(): void
    {
        if ($this->getOriginal('external_id') !== null) {
            throw PostNotEditable::alreadyPublished($this);
        }

        $post = Post::query()->find($this->post_id);

        if ($post === null) {
            throw PostNotEditable::missingPost($this);
        }

        if (! $post->isEditable()) {
            throw PostNotEditable::inState($post);
        }
    }
}
