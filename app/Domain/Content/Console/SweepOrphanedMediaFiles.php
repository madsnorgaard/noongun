<?php

namespace App\Domain\Content\Console;

use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Models\Media;
use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use League\Flysystem\StorageAttributes;
use Throwable;

/**
 * Removes files on the media disk that no row points to.
 *
 * An upload writes its two files and then its row. A process that is killed
 * in between leaves the files: no row, so no quota counts them and the
 * clean-up of unused images, which works from rows, never sees them. The
 * same is left when the files of a removed image could not be deleted, and
 * when a whole workspace is deleted, which removes rows and no files.
 *
 * A file is removed only when all of this holds:
 *
 * - its path has the form StoreMedia gives a file, so that nothing else on
 *   a shared disk or bucket is ever touched;
 * - no row on that disk names it, as an image or as a small copy, in any
 *   workspace;
 * - it is older than noongun.media.orphan_file_hours, which is days where
 *   the gap between a file and its row is a second.
 *
 * It works through the disk only: a listing, the time of each file, a
 * delete. A local folder and a bucket are swept the same way. On a bucket
 * the cost is one listing of the media files, which is why this runs once
 * a week and not every hour.
 */
class SweepOrphanedMediaFiles extends Command
{
    /** What StoreMedia calls a file: workspace, year, month, forty hex characters. */
    public const STORED_PATH = '#^w\d+/\d{4}/\d{2}/[0-9a-f]{40}(_thumb\.jpg|\.jpg|\.png)$#';

    /** How many paths are held against the table at once. */
    private const BATCH = 500;

    /** The most files one run removes. What is left is taken by the next run. */
    private const MAX_REMOVED = 10000;

    protected $signature = 'noongun:sweep-orphaned-media-files {--dry-run : Only say what would be removed}';

    protected $description = 'Remove files on the media disk that no stored image points to';

    public function handle(): int
    {
        $removed = 0;
        $errors = 0;

        foreach ($this->disks() as $name) {
            try {
                $removed += $this->sweep($name, self::MAX_REMOVED - $removed);
            } catch (Throwable $e) {
                $errors++;
                report($e);
            }
        }

        $this->info(($this->option('dry-run') ? 'Would remove ' : 'Removed ')."{$removed} file(s) that no image points to.");

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The disk new images go to, and every disk an image is stored on.
     *
     * @return list<string>
     */
    private function disks(): array
    {
        $names = [MediaConfig::diskName(), ...Media::query()->withoutGlobalScopes()->distinct()->pluck('disk')->all()];

        return array_values(array_unique(array_filter(
            $names,
            fn (mixed $name): bool => is_string($name) && $name !== 'public' && is_array(config("filesystems.disks.{$name}")),
        )));
    }

    private function sweep(string $name, int $limit): int
    {
        $disk = MediaConfig::disk($name);

        if (! $disk instanceof FilesystemAdapter || $limit < 1) {
            return 0;
        }

        $before = now()->subSeconds(MediaConfig::orphanFileSeconds())->getTimestamp();
        $removed = 0;
        $batch = [];

        foreach ($disk->getDriver()->listContents('', true) as $item) {
            if ($this->isOldStoredFile($disk, $item, $before)) {
                $batch[] = $item->path();
            }

            if (count($batch) >= self::BATCH) {
                $removed += $this->removeUnreferenced($disk, $name, $batch, $limit - $removed);
                $batch = [];
            }

            if ($removed >= $limit) {
                return $removed;
            }
        }

        return $removed + $this->removeUnreferenced($disk, $name, $batch, $limit - $removed);
    }

    private function isOldStoredFile(FilesystemAdapter $disk, StorageAttributes $item, int $before): bool
    {
        if (! $item->isFile() || preg_match(self::STORED_PATH, $item->path()) !== 1) {
            return false;
        }

        try {
            $modified = $item->lastModified() ?? $disk->lastModified($item->path());
        } catch (Throwable) {
            // A file whose age is not known is left alone.
            return false;
        }

        return $modified <= $before;
    }

    /**
     * @param  list<string>  $paths
     */
    private function removeUnreferenced(FilesystemAdapter $disk, string $name, array $paths, int $limit): int
    {
        if ($paths === [] || $limit < 1) {
            return 0;
        }

        $known = [];

        $rows = Media::query()
            ->withoutGlobalScopes()
            ->where('disk', $name)
            ->where(fn ($query) => $query->whereIn('path', $paths)->orWhereIn('thumb_path', $paths))
            ->get(['path', 'thumb_path']);

        foreach ($rows as $row) {
            $known[$row->path] = true;
            $known[(string) $row->thumb_path] = true;
        }

        $orphans = array_slice(array_values(array_filter($paths, fn (string $path): bool => ! isset($known[$path]))), 0, $limit);

        if ($orphans === [] || $this->option('dry-run')) {
            return count($orphans);
        }

        $disk->delete($orphans);

        Log::notice('Files on the media disk that no image points to were removed.', [
            'disk' => $name,
            'count' => count($orphans),
            'first' => $orphans[0],
        ]);

        return count($orphans);
    }
}
