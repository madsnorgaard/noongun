<?php

namespace App\Domain\Content\Media;

/**
 * Estimates the quality a JPEG was saved with, so that it is not stored at
 * a higher one: writing a quality 60 file again at quality 90 adds bytes
 * and no detail.
 *
 * A JPEG does not store its quality. It stores the table its brightness
 * values were divided by, and a coarser table means a lower quality. The
 * estimate holds that table against the standard one the common encoders
 * scale (libjpeg and what is built on it), and reverses their formula. For
 * such a file it is right to within a point or two. An encoder with tables
 * of its own gets the quality whose standard table is as coarse as its own,
 * which is the comparison that matters here.
 *
 * The estimate is only ever used to lower the quality, never below a floor,
 * so a wrong one costs a little sharpness on one file and nothing more.
 */
final class JpegQuality
{
    /** The standard luminance table of the JPEG specification, Annex K. */
    private const STANDARD_TABLE = [
        16, 11, 10, 16, 24, 40, 51, 61,
        12, 12, 14, 19, 26, 58, 60, 55,
        14, 13, 16, 24, 40, 57, 69, 56,
        14, 17, 22, 29, 51, 87, 80, 62,
        18, 22, 37, 56, 68, 109, 103, 77,
        24, 35, 55, 64, 81, 104, 113, 92,
        49, 64, 78, 87, 103, 121, 120, 101,
        72, 92, 95, 98, 112, 100, 103, 99,
    ];

    /**
     * The estimate, 1 to 100, or null when the file has no table that can
     * be read.
     */
    public static function of(string $path): ?int
    {
        return self::fromBytes(JpegSegments::head($path));
    }

    public static function fromBytes(string $data): ?int
    {
        foreach (JpegSegments::leading($data) as [$marker, $payload]) {
            if ($marker !== 0xDB) {
                continue;
            }

            $table = self::luminanceTable($payload);

            if ($table !== null) {
                return self::fromSum(array_sum($table));
            }
        }

        return null;
    }

    /**
     * The table with id 0 of a DQT segment, when it has 8-bit values.
     *
     * @return list<int>|null
     */
    private static function luminanceTable(string $payload): ?array
    {
        $offset = 0;
        $length = strlen($payload);

        while ($offset < $length) {
            $head = ord($payload[$offset]);
            $wide = ($head >> 4) !== 0;
            $size = $wide ? 128 : 64;

            if ($offset + 1 + $size > $length) {
                return null;
            }

            if (($head & 0x0F) === 0 && ! $wide) {
                $values = unpack('C64', substr($payload, $offset + 1, 64));

                return is_array($values) ? array_values(array_map(intval(...), $values)) : null;
            }

            $offset += 1 + $size;
        }

        return null;
    }

    private static function fromSum(int|float $sum): ?int
    {
        if ($sum <= 0) {
            return null;
        }

        // The scale in percent that turns the standard table into this one,
        // and the quality libjpeg derives that scale from.
        $scale = 100 * $sum / array_sum(self::STANDARD_TABLE);
        $quality = $scale <= 100 ? (200 - $scale) / 2 : 5000 / $scale;

        return (int) max(1, min(100, round($quality)));
    }
}
