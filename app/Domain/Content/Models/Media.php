<?php

namespace App\Domain\Content\Models;

use App\Domain\Content\Exceptions\MediaFileMissing;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Workspaces\Concerns\BelongsToWorkspace;
use App\Domain\Workspaces\Policies\ContentPolicy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\MediaFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Throwable;

/**
 * One stored image: a file on a disk that this application wrote itself
 * (see ImageProcessor), and what is known about it. A file is never changed
 * after it is stored.
 *
 * The file is reached through the disk only: `disk` and `path`, never an
 * address and never a path on a machine (ADR 0003). Read it with
 * readStream(). What a post says about an image, its alternative text and
 * its place among the others, is on the attachment, because that is content
 * a person approves. One image can be on several variants.
 *
 * Rows are created by StoreMedia and removed by the clean-up of unused
 * images, which also removes the files.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string|null $thumb_path
 * @property string $mime
 * @property int $byte_size
 * @property int $width
 * @property int $height
 * @property string $content_hash
 * @property CarbonImmutable|null $unreferenced_since
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read User|null $uploader
 */
#[Fillable(['uploaded_by', 'disk', 'path', 'thumb_path', 'mime', 'byte_size', 'width', 'height', 'content_hash'])]
#[UseFactory(MediaFactory::class)]
#[UsePolicy(ContentPolicy::class)]
class Media extends Model
{
    use BelongsToWorkspace;

    /** @use HasFactory<MediaFactory> */
    use HasFactory;

    protected $table = 'media';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
            'unreferenced_since' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return HasMany<MediaAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(MediaAttachment::class);
    }

    public function pixels(): int
    {
        return $this->width * $this->height;
    }

    /**
     * Opens the stored file for reading, or its small copy. The caller
     * closes the stream. Works the same on a local disk and on object
     * storage.
     *
     * @return resource
     *
     * @throws MediaFileMissing
     */
    public function readStream(bool $thumbnail = false)
    {
        $path = $thumbnail && $this->thumb_path !== null ? $this->thumb_path : $this->path;

        try {
            $stream = MediaConfig::disk($this->disk)->readStream($path);
        } catch (Throwable) {
            $stream = null;
        }

        if (! is_resource($stream)) {
            throw MediaFileMissing::for($this);
        }

        return $stream;
    }

    public function fileExists(): bool
    {
        try {
            return MediaConfig::disk($this->disk)->exists($this->path);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Removes the stored files. Only for a row that is deleted: nothing
     * else may leave a row without its file.
     */
    public function deleteFiles(): void
    {
        MediaConfig::disk($this->disk)->delete(array_values(array_filter([$this->path, $this->thumb_path])));
    }

    /**
     * A file name made here, for the Content-Disposition of a response and
     * for a platform that wants one. Nothing of the name the file was
     * uploaded with is kept anywhere.
     */
    public function fileName(): string
    {
        return "image-{$this->id}.".($this->mime === 'image/png' ? 'png' : 'jpg');
    }
}
