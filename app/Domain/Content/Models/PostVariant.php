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
 * failed when error is set, and waiting otherwise. Content (body, link, utm,
 * channel) can only change while the post is a draft and the variant is not
 * published. The outcome columns (external_id, permalink, published_at,
 * error) are not fillable and are written only by markPublished() and
 * markFailed().
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $post_id
 * @property int $channel_id
 * @property string $body
 * @property string|null $link
 * @property array<string, string>|null $utm
 * @property string|null $external_id
 * @property string|null $permalink
 * @property CarbonImmutable|null $published_at
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Post|null $post
 * @property-read Channel|null $channel
 */
#[Fillable(['post_id', 'channel_id', 'body', 'link', 'utm'])]
#[UseFactory(PostVariantFactory::class)]
class PostVariant extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<PostVariantFactory> */
    use HasFactory;

    private const CONTENT = ['channel_id', 'body', 'link', 'utm'];

    private const OUTCOME = ['external_id', 'permalink', 'published_at', 'error'];

    protected static function booted(): void
    {
        static::saving(function (PostVariant $variant): void {
            if ($variant->isDirty(self::OUTCOME)) {
                throw PostNotEditable::outcomeWrittenDirectly($variant);
            }

            if ($variant->exists && $variant->isDirty('post_id')) {
                throw PostNotEditable::movedToAnotherPost($variant);
            }

            if (! $variant->exists || $variant->isDirty(self::CONTENT)) {
                $variant->guardEditable();
            }
        });

        static::deleting(function (PostVariant $variant): void {
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
            ]);

        $this->refresh();

        if ($updated !== 1) {
            throw VariantAlreadyPublished::for($this);
        }

        return $this;
    }

    /**
     * Records why the last publish attempt failed. The text is redacted
     * before it is stored. Does nothing to a variant that is published.
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
