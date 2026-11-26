<?php

namespace App\Domain\Content\Actions;

use App\Domain\Content\Exceptions\MediaRefused;
use App\Domain\Content\Exceptions\PostActionNotAllowed;
use App\Domain\Content\Media\ImageProcessor;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores one uploaded image for the current workspace.
 *
 * The file that was sent is never kept. ImageProcessor decodes it and
 * writes a new image, and that is what goes to the disk, under a name made
 * here: a random one, below a folder for the workspace. Nothing of the
 * original name, of the type the browser claimed or of the metadata in the
 * file is stored anywhere.
 *
 * The image belongs to no post yet. The editor names it when a draft is
 * saved; an image that never gets onto a post is removed by the clean-up.
 */
final class StoreMedia
{
    public function __construct(
        private readonly ImageProcessor $processor,
        private readonly CurrentWorkspace $currentWorkspace,
    ) {}

    /**
     * @param  string  $uploadedPath  where PHP put the upload, on the local disk
     *
     * @throws PostActionNotAllowed the actor is not an owner or editor of the workspace
     * @throws MediaRefused the file is not an image that can be stored, or the workspace is out of room
     */
    public function handle(User $actor, string $uploadedPath): Media
    {
        $workspace = $this->currentWorkspace->getOrFail();

        if (! $actor->exists || ! ($workspace->roleOf($actor)?->canEditContent() ?? false)) {
            throw new PostActionNotAllowed("User #{$actor->getKey()} may not add images in workspace #{$workspace->id}.");
        }

        $size = @filesize($uploadedPath);

        if ($size === false || $size > MediaConfig::maxBytes()) {
            throw MediaRefused::tooManyBytes(MediaConfig::maxBytes());
        }

        // Before the work of decoding: a workspace that is full is told so
        // at once. The check that counts is made again below, under a lock.
        $this->assertRoom(0);

        $image = $this->processor->process($uploadedPath);

        $diskName = MediaConfig::diskName();
        $disk = MediaConfig::disk($diskName);
        $name = sprintf('w%d/%s/%s', $workspace->id, now()->format('Y/m'), bin2hex(random_bytes(20)));
        $path = "{$name}.{$image->extension}";
        $thumbPath = "{$name}_thumb.jpg";

        try {
            $disk->writeStream($path, $image->image);
            $disk->writeStream($thumbPath, $image->thumbnail);

            return DB::transaction(function () use ($workspace, $actor, $image, $diskName, $path, $thumbPath): Media {
                // One upload at a time per workspace gets past this point,
                // so two uploads cannot both take the last of the room.
                Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();

                $this->assertRoom($image->byteSize);

                return Media::query()->create([
                    'uploaded_by' => $actor->id,
                    'disk' => $diskName,
                    'path' => $path,
                    'thumb_path' => $thumbPath,
                    'mime' => $image->mime,
                    'byte_size' => $image->byteSize,
                    'width' => $image->width,
                    'height' => $image->height,
                    'content_hash' => $image->hash,
                ]);
            });
        } catch (Throwable $e) {
            try {
                $disk->delete([$path, $thumbPath]);
            } catch (Throwable) {
                Log::warning('A file of an upload that was not stored could not be removed from the media disk.', [
                    'workspace_id' => $workspace->id,
                    'disk' => $diskName,
                    'path' => $path,
                ]);
            }

            throw $e;
        } finally {
            $image->close();
        }
    }

    /**
     * @throws MediaRefused
     */
    private function assertRoom(int $adding): void
    {
        $quota = MediaConfig::quotaBytes();

        if ($quota === null) {
            return;
        }

        $used = (int) Media::query()->sum('byte_size');

        if ($used + $adding > $quota || ($adding === 0 && $used >= $quota)) {
            throw MediaRefused::quotaReached($quota);
        }
    }
}
