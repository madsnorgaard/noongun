<?php

namespace App\Domain\Content\Media;

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

    public static function memoryLimit(): string
    {
        $limit = config('noongun.media.memory_limit');

        return is_string($limit) && $limit !== '' ? $limit : '512M';
    }

    /**
     * Bytes all images of one workspace may take together, or null for no limit.
     */
    public static function quotaBytes(): ?int
    {
        $quota = self::int('quota_bytes', 0, 0);

        return $quota > 0 ? $quota : null;
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
