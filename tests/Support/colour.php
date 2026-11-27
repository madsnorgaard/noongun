<?php

use App\Domain\Content\Media\VipsCommand;

require_once __DIR__.'/media.php';

/**
 * Helpers for images whose numbers do not mean sRGB: files with a colour
 * profile, CMYK files and files with sixteen bits a channel. Loaded with
 * require_once.
 *
 * Nothing here is a photo and nothing is read from tests/Fixtures. The
 * profile is written out by hand from the published numbers of Display P3,
 * and the files GD cannot write are made with libvips where a test is only
 * about libvips anyway.
 *
 *     $jpeg = flatJpegBytes(p3Patch());                       // one colour
 *     $sent = jpegWithProfile($jpeg, displayP3Profile());     // tagged as Display P3
 *     expect(colourDistance(pixelOf($stored, 16, 16), srgbPatch()))->toBeLessThanOrEqual(3);
 */

/**
 * A colour as sRGB, and the numbers the same colour has in Display P3.
 * Display P3 reaches further into red and green, so the same red is a
 * smaller number there. A file with the second set of numbers and the
 * Display P3 profile shows the first colour; read without the profile it
 * shows a duller one.
 *
 * @return array{int, int, int}
 */
function srgbPatch(): array
{
    return [200, 60, 40];
}

/**
 * @return array{int, int, int}
 */
function p3Patch(): array
{
    return [186, 71, 50];
}

/**
 * How far two colours are apart: the largest difference in one channel.
 *
 * @param  array<int, int>  $a
 * @param  array<int, int>  $b
 */
function colourDistance(array $a, array $b): int
{
    return max(abs($a[0] - $b[0]), abs($a[1] - $b[1]), abs($a[2] - $b[2]));
}

/**
 * A Display P3 profile, version 4 of the ICC format: the primaries of P3
 * adapted to D50 and the tone curve of sRGB. About 500 bytes.
 */
function displayP3Profile(): string
{
    $fixed = fn (float ...$numbers): string => implode('', array_map(fn (float $number): string => pack('N', (int) round($number * 65536) & 0xFFFFFFFF), $numbers));
    $text = fn (string $words): string => 'mluc'.pack('N4', 0, 1, 12, 0x656E5553).pack('NN', strlen($words) * 2, 28).mb_convert_encoding($words, 'UTF-16BE', 'ASCII');
    $xyz = fn (float $x, float $y, float $z): string => 'XYZ '.pack('N', 0).$fixed($x, $y, $z);

    $tags = [
        'desc' => $text('Display P3'),
        'cprt' => $text('No copyright, use freely'),
        'wtpt' => $xyz(0.9642, 1.0, 0.8249),
        'rXYZ' => $xyz(0.51512, 0.24120, -0.00105),
        'gXYZ' => $xyz(0.29198, 0.69225, 0.04189),
        'bXYZ' => $xyz(0.15710, 0.06657, 0.78407),
        'rTRC' => 'para'.pack('Nnn', 0, 3, 0).$fixed(2.4, 1 / 1.055, 0.055 / 1.055, 1 / 12.92, 0.04045),
    ];

    $table = '';
    $data = '';
    $offset = 128 + 4 + 12 * (count($tags) + 2);

    foreach ($tags as $name => $tag) {
        $tag = str_pad($tag, (int) ceil(strlen($tag) / 4) * 4, "\0");
        $table .= $name.pack('NN', $offset + strlen($data), strlen($tag));

        // The three channels share one curve.
        if ($name === 'rTRC') {
            $table .= 'gTRC'.pack('NN', $offset + strlen($data), strlen($tag)).'bTRC'.pack('NN', $offset + strlen($data), strlen($tag));
        }

        $data .= $tag;
    }

    $header = pack('N3', 0, 0, 0x04200000).'mntrRGB XYZ '
        .pack('n6', 2026, 1, 1, 0, 0, 0).'acsp'.str_repeat("\0", 28)
        .$fixed(0.9642, 1.0, 0.8249).str_repeat("\0", 48);

    $profile = str_pad($header, 128, "\0").pack('N', count($tags) + 2).$table.$data;

    return pack('N', strlen($profile)).substr($profile, 4);
}

/**
 * A JPEG of one colour, at a quality that keeps it.
 *
 * @param  array{int, int, int}  $colour
 */
function flatJpegBytes(array $colour, int $width = 32, int $height = 32): string
{
    ob_start();
    imagejpeg(flatImage($colour, $width, $height), null, 100);

    return (string) ob_get_clean();
}

/**
 * @param  array{int, int, int}  $colour
 */
function flatPngBytes(array $colour, int $width = 32, int $height = 32): string
{
    ob_start();
    imagepng(flatImage($colour, $width, $height));

    return (string) ob_get_clean();
}

/**
 * @param  array{int, int, int}  $colour
 */
function flatImage(array $colour, int $width, int $height): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, ($colour[0] << 16) | ($colour[1] << 8) | $colour[2]);

    return $image;
}

/**
 * The JPEG with a colour profile in it, as a camera or an editor writes
 * one: an APP2 segment after the start of the file.
 */
function jpegWithProfile(string $jpeg, string $profile): string
{
    $segment = "ICC_PROFILE\0\x01\x01".$profile;

    return substr($jpeg, 0, 2)."\xFF\xE2".pack('n', strlen($segment) + 2).$segment.substr($jpeg, 2);
}

/**
 * The PNG with a colour profile in it: an iCCP chunk after the header.
 */
function pngWithProfile(string $png, string $profile): string
{
    return pngWithChunk($png, 'iCCP', "Display P3\0\0".gzcompress($profile));
}

/**
 * The PNG with one more chunk after its header.
 */
function pngWithChunk(string $png, string $type, string $data): string
{
    // The signature is 8 bytes and the header chunk 25.
    return substr($png, 0, 33).pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data)).substr($png, 33);
}

/**
 * A PNG of one colour with sixteen bits a channel, which GD cannot write.
 *
 * @param  array{int, int, int}  $colour  as eight bit numbers
 */
function sixteenBitPngBytes(array $colour, int $width = 32, int $height = 32): string
{
    $header = pack('NN', $width, $height)."\x10\x02\0\0\0";
    $row = "\0".str_repeat(pack('n3', $colour[0] * 257, $colour[1] * 257, $colour[2] * 257), $width);
    $chunk = fn (string $type, string $data): string => pack('N', strlen($data)).$type.$data.pack('N', crc32($type.$data));

    return "\x89PNG\r\n\x1a\n".$chunk('IHDR', $header).$chunk('IDAT', gzcompress(str_repeat($row, $height))).$chunk('IEND', '');
}

/**
 * Runs one operation of libvips on the bytes of an image and returns the
 * file it wrote, for images GD cannot make. Only in tests that are about
 * libvips: it fails where the container has none.
 *
 * @param  string  $from  the extension the bytes are written with
 * @param  string  $to  the extension of the result, which decides its format
 * @param  list<string>  $before  the operation and what comes before the two files
 * @param  list<string>  $after  what comes after them
 */
function madeWithVips(string $bytes, string $from, string $to, array $before = ['copy'], array $after = []): string
{
    $directory = sys_get_temp_dir().'/noongun-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);

    try {
        file_put_contents("{$directory}/in.{$from}", $bytes);

        app(VipsCommand::class)->run([...$before, "{$directory}/in.{$from}", "{$directory}/out.{$to}", ...$after], $directory, 30);

        return (string) file_get_contents("{$directory}/out.{$to}");
    } finally {
        array_map(unlink(...), glob("{$directory}/*") ?: []);
        rmdir($directory);
    }
}

/**
 * The colour as a CMYK JPEG, converted the way an editor does it by
 * default (relative colorimetric, with black point compensation) to the
 * press profile libvips carries.
 *
 * @param  array{int, int, int}  $colour
 * @param  bool  $withProfile  whether the file says which CMYK it is
 */
function cmykJpegBytes(array $colour, bool $withProfile = true): string
{
    $cmyk = madeWithVips(flatPngBytes($colour), 'png', 'v', ['icc_transform'], ['cmyk', '--input-profile', 'srgb', '--intent', 'relative', '--black-point-compensation']);

    return madeWithVips($cmyk, 'v', 'jpg', ['jpegsave'], ['--Q', '100', '--keep', $withProfile ? 'icc' : 'none']);
}
