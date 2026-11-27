<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;
use Throwable;

/**
 * Decides from the header of a file whether it may be decoded at all, and
 * says what it is. Nothing here trusts the name of the file or the type the
 * browser gave, and nothing here decodes a pixel: a file that is not a
 * JPEG, PNG or WebP, that is an animation, or that is larger than the
 * limits is refused for the price of reading a few hundred bytes.
 *
 * This part does not depend on the image library. Whatever engine decodes
 * the image afterwards gets the same gate in front of it.
 */
class ImageInspector
{
    /** How many chunks of a PNG are looked at before its image data. */
    private const MAX_PNG_CHUNKS = 256;

    /**
     * @param  string  $path  the uploaded file, on the local disk where PHP put it
     *
     * @throws MediaRefused
     */
    public function inspect(string $path): ImageFacts
    {
        $head = $this->head($path, 32);

        // Before anything else looks at it: an animated WebP is not an
        // image that can be stored as one picture.
        if ($this->isWebp($head) && substr($head, 12, 4) === 'VP8X' && (ord($head[20]) & 0x02) !== 0) {
            throw MediaRefused::animation();
        }

        try {
            $info = @getimagesize($path);
        } catch (Throwable) {
            $info = false;
        }

        if ($info === false) {
            throw MediaRefused::notAnImage();
        }

        if ($info[2] === IMAGETYPE_GIF) {
            throw MediaRefused::animated();
        }

        if (! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw MediaRefused::notAnImage();
        }

        [$width, $height] = [$info[0], $info[1]];

        if ($width < 1 || $height < 1) {
            throw MediaRefused::unreadable();
        }

        if ($width > MediaConfig::maxSide() || $height > MediaConfig::maxSide() || $width * $height > MediaConfig::maxPixels()) {
            throw MediaRefused::tooManyPixels($width, $height, MediaConfig::maxPixels(), MediaConfig::maxSide());
        }

        if ($info[2] === IMAGETYPE_JPEG) {
            return new ImageFacts(ImageFacts::JPEG, $width, $height, mayHaveAlpha: false, lossless: false, jpegQuality: JpegQuality::of($path));
        }

        if ($info[2] === IMAGETYPE_PNG) {
            return new ImageFacts(ImageFacts::PNG, $width, $height, mayHaveAlpha: $this->pngMayHaveAlpha($path), lossless: true);
        }

        // The simple lossy form has neither transparency nor exact pixels.
        // The extended form can hold either kind of image data, so it is
        // treated as if it might be both.
        $form = $this->isWebp($head) ? substr($head, 12, 4) : '';

        return new ImageFacts(
            ImageFacts::WEBP,
            $width,
            $height,
            mayHaveAlpha: match ($form) {
                'VP8 ' => false,
                'VP8L' => strlen($head) > 24 && (ord($head[24]) & 0x10) !== 0,
                'VP8X' => (ord($head[20]) & 0x10) !== 0,
                default => true,
            },
            lossless: $form !== 'VP8 ',
        );
    }

    /**
     * Walks the chunks of a PNG up to its image data. An animated PNG says
     * so there (acTL) and is refused: the image library would keep its
     * first frame and drop the rest without a word. Returns whether the
     * file can carry transparency: a colour type with an alpha channel, or
     * a tRNS chunk.
     *
     * @throws MediaRefused
     */
    private function pngMayHaveAlpha(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            throw MediaRefused::unreadable();
        }

        try {
            fseek($handle, 8);
            $alpha = false;

            for ($i = 0; $i < self::MAX_PNG_CHUNKS; $i++) {
                $header = (string) fread($handle, 8);

                if (strlen($header) < 8) {
                    break;
                }

                $length = unpack('N', substr($header, 0, 4));
                $length = is_array($length) && is_int($length[1]) ? $length[1] : -1;
                $type = substr($header, 4, 4);

                if ($length < 0 || $length > 0x7FFFFFFF || $type === 'IDAT' || $type === 'IEND') {
                    break;
                }

                if ($type === 'acTL') {
                    throw MediaRefused::animation();
                }

                if ($type === 'tRNS') {
                    $alpha = true;
                }

                if ($type === 'IHDR') {
                    $data = $length > 0 ? (string) fread($handle, min($length, 13)) : '';
                    $colourType = strlen($data) >= 10 ? ord($data[9]) : 6;
                    $alpha = $alpha || $colourType === 4 || $colourType === 6;

                    fseek($handle, $length - strlen($data) + 4, SEEK_CUR);

                    continue;
                }

                if (fseek($handle, $length + 4, SEEK_CUR) !== 0) {
                    break;
                }
            }

            return $alpha;
        } finally {
            fclose($handle);
        }
    }

    private function isWebp(string $head): bool
    {
        return strlen($head) >= 21 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
    }

    private function head(string $path, int $bytes): string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return '';
        }

        $head = (string) fread($handle, max(1, $bytes));
        fclose($handle);

        return $head;
    }
}
