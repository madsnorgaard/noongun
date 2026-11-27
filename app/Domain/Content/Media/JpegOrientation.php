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
    /**
     * The orientation, 1 to 8 as EXIF numbers them, or 1 when there is none
     * or it cannot be read.
     */
    public static function of(string $path): int
    {
        return self::fromBytes(JpegSegments::head($path));
    }

    public static function fromBytes(string $data): int
    {
        foreach (JpegSegments::leading($data) as [$marker, $payload]) {
            if ($marker === 0xE1 && substr($payload, 0, 6) === "Exif\0\0") {
                return self::fromTiff(substr($payload, 6));
            }
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
