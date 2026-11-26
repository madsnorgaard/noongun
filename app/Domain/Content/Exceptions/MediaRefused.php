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

    public static function unreadable(): self
    {
        return new self(self::text('The image could not be read. It may be damaged, or it is an animation.'));
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
        return new self(self::text('The workspace has used its :limit MB of storage for images. Images that no post uses are removed after a day; delete posts you no longer need, or ask the person who runs this instance to raise the limit.', [
            'limit' => (int) round($quotaBytes / 1024 / 1024),
        ]));
    }

    public static function notSupportedHere(): self
    {
        return new self(self::text('This instance cannot process images: the image library of PHP is missing. Ask the person who runs it to rebuild the Docker image.'));
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
