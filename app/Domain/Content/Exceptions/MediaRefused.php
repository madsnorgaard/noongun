<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * An upload that is not stored. The message is a sentence for the person
 * who uploaded it and never holds a file name or anything else from the
 * file itself.
 */
class MediaRefused extends RuntimeException
{
    public static function notAnImage(): self
    {
        return new self(self::text('The file is not a JPEG, PNG or WebP image.'));
    }

    public static function animated(): self
    {
        return new self(self::text('GIF images are not supported, because an animation would be published as a still picture. Save the image as PNG or JPEG.'));
    }

    public static function animation(): self
    {
        return new self(self::text('Animated images are not supported, because an animation would be published as a still picture. Save one frame as PNG or JPEG.'));
    }

    public static function unreadable(): self
    {
        return new self(self::text('The image could not be read. It may be damaged.'));
    }

    public static function nothingSent(int $maxBytes): self
    {
        return new self(self::text('Choose one image to upload. The limit is :limit MB.', ['limit' => round($maxBytes / 1024 / 1024, 1)]));
    }

    public static function interrupted(): self
    {
        return new self(self::text('The upload was interrupted before the whole file arrived. Try again.'));
    }

    public static function serverCannotStore(): self
    {
        return new self(self::text('The server cannot take uploads right now: it has no room or no place to put them. Nothing is wrong with the image. Tell the person who runs this instance.'));
    }

    public static function tooManyPixels(int $width, int $height, int $maxPixels, int $maxSide): self
    {
        return new self(self::text('The image is :width x :height pixels. The limit is :megapixels megapixels and :side pixels on a side.', [
            'width' => $width,
            'height' => $height,
            'megapixels' => round($maxPixels / 1_000_000, 1),
            'side' => $maxSide,
        ]));
    }

    public static function tooManyBytes(int $maxBytes): self
    {
        return new self(self::text('The file is too large. The limit is :limit MB.', ['limit' => round($maxBytes / 1024 / 1024, 1)]));
    }

    public static function quotaReached(int $quotaBytes): self
    {
        return new self(self::text('The workspace has used its :limit MB of storage for images, and images on posts are kept. If you run this instance, raise NOONGUN_MEDIA_QUOTA_BYTES in its settings, or set it to 0 for no limit. If you do not, ask the person who does.', [
            'limit' => (int) round($quotaBytes / 1024 / 1024),
        ]));
    }

    public static function notSupportedHere(): self
    {
        return new self(self::text('This instance cannot process images: its image library is missing. Ask the person who runs it to rebuild the Docker image.'));
    }

    /**
     * @param  array<string, string|int|float>  $replace
     */
    private static function text(string $key, array $replace = []): string
    {
        $text = __($key, $replace);

        return is_string($text) ? $text : $key;
    }
}
