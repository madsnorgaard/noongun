<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Exceptions\MediaFileMissing;
use App\Domain\Content\Exceptions\PostNotEditable;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use Carbon\CarbonImmutable;
use Database\Factories\MediaAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One image on one variant: which image, its place among the others and
 * its alternative text. This is content like the text of the variant. It
 * can only be added, changed or removed while the post is a draft and the
 * variant is not published, and it is part of what an approval covers.
 *
 * external_id and uploaded_at are not content. They record that the file
 * was sent to the platform for this variant and what the platform calls it,
 * so that a publish that is tried again does not send the file again. They
 * are written only by markUploaded() and forgetUpload(), and forgotten
 * whenever the alternative text changes, because a platform may have
 * stored the text with the upload.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $post_variant_id
 * @property int $media_id
 * @property int $position
 * @property string|null $alt_text
 * @property string|null $external_id
 * @property CarbonImmutable|null $uploaded_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read PostVariant|null $variant
 * @property-read Media|null $media
 */
#[Fillable(['post_variant_id', 'media_id', 'position', 'alt_text'])]
#[UseFactory(MediaAttachmentFactory::class)]
class MediaAttachment extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MediaAttachmentFactory> */
    use HasFactory;

    protected $table = 'post_variant_media';

    private const CONTENT = ['post_variant_id', 'media_id', 'position', 'alt_text'];

    private const UPLOAD = ['external_id', 'uploaded_at'];

    protected static function booted(): void
    {
        static::saving(function (MediaAttachment $attachment): void {
            if ($attachment->isDirty(self::UPLOAD)) {
                throw PostNotEditable::uploadWrittenDirectly($attachment);
            }

            if ($attachment->exists && $attachment->isDirty(['post_variant_id', 'media_id'])) {
                throw PostNotEditable::attachmentMoved($attachment);
            }

            if (! $attachment->exists || $attachment->isDirty(self::CONTENT)) {
                $attachment->guardEditable();
            }
        });

        static::updated(function (MediaAttachment $attachment): void {
            if ($attachment->wasChanged('alt_text')) {
                $attachment->forgetUpload();
            }
        });

        static::deleting(function (MediaAttachment $attachment): void {
            $attachment->guardEditable();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PostVariant, $this>
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(PostVariant::class, 'post_variant_id');
    }

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    /**
     * The stored image. An attachment cannot exist without one.
     *
     * @throws MediaFileMissing
     */
    public function image(): Media
    {
        return $this->media ?? throw new MediaFileMissing("Attachment #{$this->getKey()} has no image in the current workspace.");
    }

    /**
     * Opens the file for reading. The caller closes the stream.
     *
     * @return resource
     *
     * @throws MediaFileMissing
     */
    public function readStream()
    {
        return $this->image()->readStream();
    }

    /**
     * Whether the platform holds this image for this variant from an
     * earlier step, and it can still be used.
     *
     * @param  int|null  $lifetime  seconds a platform keeps an image that is on no post yet; null when it keeps it
     */
    public function isUploaded(?int $lifetime = null): bool
    {
        if ($this->external_id === null || $this->uploaded_at === null) {
            return false;
        }

        return $lifetime === null || $this->uploaded_at->gt(now()->subSeconds($lifetime));
    }

    /**
     * Records what the platform calls this image. Written at once, by
     * itself, so that it survives whatever happens to the rest of the publish.
     */
    public function markUploaded(string $externalId): static
    {
        static::guardWorkspace($this);

        static::query()
            ->whereKey($this->getKey())
            ->update(['external_id' => $externalId, 'uploaded_at' => now()]);

        return $this->refresh();
    }

    /**
     * Forgets the upload, so that the next publish sends the file again.
     */
    public function forgetUpload(): static
    {
        static::guardWorkspace($this);

        static::query()
            ->whereKey($this->getKey())
            ->update(['external_id' => null, 'uploaded_at' => null]);

        return $this->refresh();
    }

    private function guardEditable(): void
    {
        $variant = PostVariant::query()->whereKey($this->post_variant_id)->first();

        if ($variant === null) {
            throw PostNotEditable::attachmentWithoutVariant($this);
        }

        if ($variant->isPublished()) {
            throw PostNotEditable::alreadyPublished($variant);
        }

        $post = Post::query()->find($variant->post_id);

        if ($post === null) {
            throw PostNotEditable::missingPost($variant);
        }

        if (! $post->isEditable()) {
            throw PostNotEditable::inState($post);
        }
    }
}
