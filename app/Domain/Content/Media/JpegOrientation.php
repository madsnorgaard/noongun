<?php

namespace App\Domain\Content\Media;

/**
 * Reads the one EXIF value that changes how a photo must be shown: its
 * orientation. A camera held upright stores the picture lying down and notes
 * the turn here. Re-encoding drops the note, so the turn is applied first.
 *
 * Written out by hand so that nothing else of the EXIF block is parsed: no
 * extension reads untrusted metadata, and every read is bounds-checked.
 */
final class JpegOrientation
{
    /** EXIF sits in the first segments of a file. Nothing further in is read. */
    private const READ_BYTES = 262144;

    private const MAX_SEGMENTS = 64;

    /**
     * The orientation, 1 to 8 as EXIF numbers them, or 1 when there is none
     * or it cannot be read.
     */
    public static function of(string $path): int
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return 1;
        }

        $data = (string) fread($handle, self::READ_BYTES);
        fclose($handle);

        return self::fromBytes($data);
    }

    public static function fromBytes(string $data): int
    {
        $length = strlen($data);

        if ($length < 4 || substr($data, 0, 2) !== "\xFF\xD8") {
            return 1;
        }

        $offset = 2;

        for ($segment = 0; $segment < self::MAX_SEGMENTS && $offset + 4 <= $length; $segment++) {
            if ($data[$offset] !== "\xFF") {
                return 1;
            }

            $marker = ord($data[$offset + 1]);

            // The image data starts: no metadata after this.
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }

            $size = self::uint16($data, $offset + 2, false);

            if ($size === null || $size < 2) {
                return 1;
            }

            if ($marker === 0xE1 && substr($data, $offset + 4, 6) === "Exif\0\0") {
                return self::fromTiff(substr($data, $offset + 10, $size - 8));
            }

            $offset += 2 + $size;
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        $order = substr($tiff, 0, 2);

        if ($order !== 'II' && $order !== 'MM') {
            return 1;
        }

        $little = $order === 'II';

        if (self::uint16($tiff, 2, $little) !== 42) {
            return 1;
        }

        $directory = self::uint32($tiff, 4, $little);
        $entries = $directory !== null ? self::uint16($tiff, $directory, $little) : null;

        if ($directory === null || $entries === null) {
            return 1;
        }

        for ($i = 0; $i < min($entries, 256); $i++) {
            $entry = $directory + 2 + $i * 12;

            if (self::uint16($tiff, $entry, $little) !== 0x0112) {
                continue;
            }

            $value = self::uint16($tiff, $entry + 8, $little);

            return $value !== null && $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }

    private static function uint16(string $data, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 2 > strlen($data)) {
            return null;
        }

        $value = unpack($little ? 'v' : 'n', substr($data, $offset, 2));

        return is_array($value) && is_int($value[1]) ? $value[1] : null;
    }

    private static function uint32(string $data, int $offset, bool $little): ?int
    {
        if ($offset < 0 || $offset + 4 > strlen($data)) {
            return null;
        }

        $value = unpack($little ? 'V' : 'N', substr($data, $offset, 4));

        return is_array($value) && is_int($value[1]) ? $value[1] : null;
    }
}
