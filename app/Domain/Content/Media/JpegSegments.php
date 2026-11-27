<?php

namespace App\Domain\Content\Media;

/**
 * Walks the segments at the start of a JPEG file, up to where the image
 * data begins. That is where a file keeps what describes it: EXIF, comments
 * and the tables its quality can be read from.
 *
 * Every read is bounds-checked and the walk has a fixed upper limit, so a
 * file that lies about its lengths ends the walk and nothing else.
 */
final class JpegSegments
{
    /** Nothing further into a file than this is looked at. */
    public const READ_BYTES = 262144;

    private const MAX_SEGMENTS = 64;

    /**
     * The first bytes of a file, enough to hold its leading segments.
     */
    public static function head(string $path): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $data = (string) fread($handle, self::READ_BYTES);
        fclose($handle);

        return $data;
    }

    /**
     * Each leading segment as [marker, payload, offset of the segment in
     * $data, length of the whole segment]. Empty for anything that is not a
     * JPEG.
     *
     * @return list<array{int, string, int, int}>
     */
    public static function leading(string $data): array
    {
        $length = strlen($data);

        if ($length < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return [];
        }

        $segments = [];
        $offset = 2;

        for ($i = 0; $i < self::MAX_SEGMENTS && $offset + 4 <= $length; $i++) {
            if ($data[$offset] !== "\xFF") {
                break;
            }

            $marker = ord($data[$offset + 1]);

            // The image data starts, or the file ends.
            if ($marker === 0xDA || $marker === 0xD9) {
                break;
            }

            $size = unpack('n', substr($data, $offset + 2, 2));
            $size = is_array($size) && is_int($size[1]) ? $size[1] : 0;

            if ($size < 2 || $offset + 2 + $size > $length) {
                break;
            }

            $segments[] = [$marker, substr($data, $offset + 4, $size - 2), $offset, 2 + $size];
            $offset += 2 + $size;
        }

        return $segments;
    }
}
