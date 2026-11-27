<?php

namespace App\Domain\Content\Media;

use App\Support\Mode;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use LogicException;

/**
 * Typed access to config('noongun.media'), with values that make no sense
 * replaced by safe ones.
 */
final class MediaConfig
{
    /** The types an upload may be. Everything else is refused. */
    public const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

    /** The types a stored image can have: what every platform takes. */
    public const STORED_TYPES = ['image/jpeg', 'image/png'];

    /** Longest alternative text a request may carry. What a platform takes is checked on approval. */
    public const MAX_ALT_TEXT_LENGTH = 5000;

    /**
     * The name of the disk new images are written to. A stored image names
     * its own disk, so changing this moves nothing.
     *
     * @throws LogicException when the disk is the public one or does not exist
     */
    public static function diskName(): string
    {
        $name = config('noongun.media.disk');
        $name = is_string($name) && $name !== '' ? $name : 'media';

        self::assertPrivate($name);

        return $name;
    }

    public static function disk(?string $name = null): Filesystem
    {
        $name ??= self::diskName();

        self::assertPrivate($name);

        return Storage::disk($name);
    }

    /**
     * The largest upload in bytes: the configured limit, or what PHP itself
     * accepts when that is lower.
     */
    public static function maxBytes(): int
    {
        $limits = [self::int('max_bytes', 20 * 1024 * 1024, 1024)];

        foreach (['upload_max_filesize', 'post_max_size'] as $setting) {
            $bytes = self::iniBytes((string) ini_get($setting));

            if ($bytes > 0) {
                $limits[] = $bytes;
            }
        }

        return min($limits);
    }

    public static function maxPixels(): int
    {
        $megapixels = config('noongun.media.max_megapixels');

        return (int) (max(0.1, is_numeric($megapixels) ? (float) $megapixels : 50.0) * 1_000_000);
    }

    public static function maxSide(): int
    {
        return self::int('max_side', 16000, 16);
    }

    public static function storedSide(): int
    {
        return self::int('stored_side', 4096, 16);
    }

    public static function thumbnailSide(): int
    {
        return self::int('thumbnail_side', 480, 16);
    }

    public static function jpegQuality(): int
    {
        return min(100, self::int('jpeg_quality', 90, 50));
    }

    /**
     * The engine that processes uploads. Anything that is not a known
     * engine is the default one.
     */
    public static function engine(): ImageEngine
    {
        $engine = config('noongun.media.engine');

        return ImageEngine::tryFrom(is_string($engine) ? strtolower(trim($engine)) : '') ?? ImageEngine::Vips;
    }

    /**
     * How many threads one run of libvips works with.
     */
    public static function vipsConcurrency(): int
    {
        return min(16, self::int('vips_concurrency', 2, 1));
    }

    public static function memoryLimit(): string
    {
        $limit = config('noongun.media.memory_limit');

        return is_string($limit) && $limit !== '' ? $limit : '512M';
    }

    /**
     * The most pixels an opaque image from a lossless format may have to be
     * written as PNG and as JPEG, so that the smaller file can be kept.
     */
    public static function losslessChoicePixels(): int
    {
        $megapixels = config('noongun.media.lossless_choice_megapixels');

        return (int) (max(0.0, is_numeric($megapixels) ? (float) $megapixels : 9.0) * 1_000_000);
    }

    /**
     * How many images the whole instance decodes at once.
     */
    public static function concurrentUploads(): int
    {
        return self::int('concurrent_uploads', 2, 1);
    }

    public static function slotWaitSeconds(): int
    {
        return self::int('slot_wait_seconds', 5, 0);
    }

    public static function slotLeaseSeconds(): int
    {
        return self::int('slot_lease_seconds', 120, 10);
    }

    /**
     * Bytes all images of one workspace may take together, or null for no
     * limit. A setting decides. Without one, an instance its owner runs for
     * themselves has no limit: the disk is theirs, and a limit they did not
     * set would stop their uploads one day for no reason they chose. A
     * hosted instance has a limit until a plan says otherwise.
     */
    public static function quotaBytes(): ?int
    {
        $quota = config('noongun.media.quota_bytes');

        if (! is_numeric($quota)) {
            $quota = Mode::isSelfHosted() ? 0 : config('noongun.media.saas_quota_bytes');
        }

        return is_numeric($quota) && (int) $quota > 0 ? (int) $quota : null;
    }

    public static function uploadsPerMinute(): int
    {
        return self::int('uploads_per_minute', 30, 1);
    }

    public static function maxPerVariant(): int
    {
        return self::int('max_per_variant', 10, 1);
    }

    public static function unusedGraceSeconds(): int
    {
        return self::int('unused_grace_hours', 24, 1) * 3600;
    }

    /**
     * How old a file that no row points to must be before it is removed.
     * Never under an hour: a file is on the disk for a moment before its
     * row is written.
     */
    public static function orphanFileSeconds(): int
    {
        return self::int('orphan_file_hours', 48, 1) * 3600;
    }

    /**
     * Raises the memory limit of this process to what decoding one image
     * needs. Never lowers it.
     */
    public static function raiseMemoryLimit(): void
    {
        $wanted = self::iniBytes(self::memoryLimit());
        $current = self::iniBytes((string) ini_get('memory_limit'));

        if ($current > 0 && $wanted > $current) {
            ini_set('memory_limit', self::memoryLimit());
        }
    }

    private static function assertPrivate(string $name): void
    {
        if ($name === 'public' || config("filesystems.disks.{$name}.visibility") === 'public') {
            throw new LogicException("Images are private and cannot be stored on the public disk [{$name}]. Set NOONGUN_MEDIA_DISK to a private disk.");
        }

        if (! is_array(config("filesystems.disks.{$name}"))) {
            throw new LogicException("The media disk [{$name}] is not defined in config/filesystems.php.");
        }
    }

    /**
     * "32M" as bytes. Zero or negative means no limit and is returned as 0.
     */
    private static function iniBytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || ! is_numeric(rtrim($value, 'KMGkmg'))) {
            return 0;
        }

        $number = (int) rtrim($value, 'KMGkmg');

        return max(0, match (strtoupper(substr($value, -1))) {
            'K' => $number * 1024,
            'M' => $number * 1024 ** 2,
            'G' => $number * 1024 ** 3,
            default => $number,
        });
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.media.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
