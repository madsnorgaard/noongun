<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\CurrentWorkspace;
use App\Domain\Workspaces\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes images that no post uses: an upload that was abandoned in the
 * editor, an image that was taken off its last post, the images of a post
 * that was deleted.
 *
 * Nothing is removed the moment it becomes unused. A run notes when it
 * first sees an image on no post (unreferenced_since), forgets the note
 * when the image is in use again, and removes the image once the note is
 * older than noongun.media.unused_grace_hours. An image somebody is about
 * to attach therefore has at least that long.
 *
 * An image that is on any variant is never touched, whatever the state of
 * the post. A published post cannot be deleted, so its images stay as long
 * as it does. The database refuses to delete a row that is attached, and
 * saving a draft holds a lock on the images it names, so an image cannot
 * be removed in the moment it is being attached.
 *
 * The row goes first and the files after it. If removing the files fails,
 * the files are left behind on the disk and the paths are logged; a row
 * never points at a file that is gone.
 */
class PruneUnusedMedia extends Command
{
    protected $signature = 'noongun:prune-unused-media';

    protected $description = 'Remove stored images that no post uses';

    public function handle(CurrentWorkspace $currentWorkspace): int
    {
        $removed = 0;
        $errors = 0;

        foreach (Workspace::query()->lazyById(200) as $workspace) {
            try {
                $removed += $currentWorkspace->run($workspace, fn (): int => $this->prune());
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info("Removed {$removed} unused image(s).");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prune(): int
    {
        Media::query()->whereNotNull('unreferenced_since')->whereHas('attachments')->update(['unreferenced_since' => null]);
        Media::query()->whereNull('unreferenced_since')->whereDoesntHave('attachments')->update(['unreferenced_since' => now()]);

        $removed = 0;
        $before = now()->subSeconds(MediaConfig::unusedGraceSeconds());

        $candidates = Media::query()
            ->where('unreferenced_since', '<=', $before)
            ->whereDoesntHave('attachments')
            ->orderBy('id')
            ->lazyById(200);

        foreach ($candidates as $candidate) {
            $media = DB::transaction(function () use ($candidate, $before): ?Media {
                // Lock the row, then look again in a statement of its own.
                // A draft that is being saved with this image holds a
                // shared lock on the row until it has attached it, so once
                // the lock is ours the attachment is there to see.
                $media = Media::query()
                    ->whereKey($candidate->id)
                    ->where('unreferenced_since', '<=', $before)
                    ->lockForUpdate()
                    ->first();

                if ($media === null || $media->attachments()->exists()) {
                    return null;
                }

                $media->delete();

                return $media;
            });

            if ($media === null) {
                continue;
            }

            $removed++;

            try {
                $media->deleteFiles();
            } catch (Throwable $e) {
                Log::warning('The files of a removed image could not be deleted from the media disk. Remove them by hand.', [
                    'workspace_id' => $media->workspace_id,
                    'disk' => $media->disk,
                    'path' => $media->path,
                    'thumb_path' => $media->thumb_path,
                    'exception' => $e::class,
                ]);
            }
        }

        return $removed;
    }
}
