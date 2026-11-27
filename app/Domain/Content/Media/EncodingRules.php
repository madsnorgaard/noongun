<?php

namespace App\Domain\Content\Media;

/**
 * What a stored image is written as, decided the same way whatever engine
 * decodes it:
 *
 * - An image with a pixel that is not opaque is a PNG.
 * - An opaque image that came in a lossless format and is not large is
 *   written both ways, and the smaller file is kept. A screenshot or a
 *   drawing stays a sharp PNG. A photograph that was saved as PNG becomes
 *   a JPEG, a tenth of the size, which a platform will take.
 * - Everything else is a JPEG, at the configured quality, or at the quality
 *   it came with when that is lower and the image is not scaled down.
 *
 * An engine looks at the pixels and writes the files. The numbers and the
 * two decisions that need no pixel are here.
 */
final class EncodingRules
{
    /** A JPEG is never stored below this quality, whatever its source says. */
    public const QUALITY_FLOOR = 60;

    public const THUMBNAIL_QUALITY = 80;

    /** The zlib level of a stored PNG. */
    public const PNG_COMPRESSION = 6;

    /**
     * The quality a JPEG of this image is written with. Writing a file
     * again at a higher quality than it came with adds bytes and no detail.
     * A scaled image is a new image and gets the configured quality.
     */
    public static function jpegQuality(ImageFacts $facts, bool $scaled): int
    {
        $quality = MediaConfig::jpegQuality();

        if ($facts->jpegQuality !== null && ! $scaled) {
            $quality = min($quality, max(self::QUALITY_FLOOR, $facts->jpegQuality));
        }

        return $quality;
    }

    /**
     * Whether an opaque image of this many pixels is also written as a PNG,
     * to keep the smaller of the two files.
     */
    public static function triesPng(ImageFacts $facts, int $pixels): bool
    {
        return $facts->lossless && $pixels <= MediaConfig::losslessChoicePixels();
    }
}
