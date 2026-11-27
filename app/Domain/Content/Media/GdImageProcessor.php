<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;
use GdImage;
use RuntimeException;
use Throwable;

/**
 * ImageProcessor with GD, the image library of PHP.
 *
 * ImageInspector has refused what must not be decoded before anything here
 * runs. What is left is decoded and encoded again, and only the result is
 * kept. That drops everything that is not a pixel: EXIF with the place a
 * photo was taken, comments, colour profiles, and anything hidden behind
 * the image data. GD writes a comment of its own into a JPEG, naming itself
 * and the quality; that is taken out again.
 *
 * What a stored image is, is decided from its pixels and not from the type
 * it was sent as:
 *
 * - An image with a pixel that is not opaque is a PNG.
 * - An opaque image that came in a lossless format and is not large is
 *   written both ways, and the smaller file is kept. A screenshot or a
 *   drawing stays a sharp PNG. A photograph that was saved as PNG becomes
 *   a JPEG, a tenth of the size, which a platform will take.
 * - Everything else is a JPEG, at the configured quality, or at the quality
 *   it came with when that is lower and the image is not scaled down.
 */
class GdImageProcessor implements ImageProcessor
{
    /** A JPEG is never stored below this quality, whatever its source says. */
    public const QUALITY_FLOOR = 60;

    private const THUMBNAIL_QUALITY = 80;

    public function __construct(private readonly ImageInspector $inspector) {}

    public function process(string $path): ProcessedImage
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromwebp')) {
            throw MediaRefused::notSupportedHere();
        }

        $facts = $this->inspector->inspect($path);

        MediaConfig::raiseMemoryLimit();

        $image = $this->decode($path, $facts);

        try {
            $scaled = max($facts->width, $facts->height) > MediaConfig::storedSide();
            $image = $this->fit($image, MediaConfig::storedSide(), $facts->mayHaveAlpha);

            if ($facts->type === ImageFacts::JPEG) {
                $image = $this->upright($image, JpegOrientation::of($path));
            }

            $thumbnail = $this->encodeJpeg($this->flatten($image, MediaConfig::thumbnailSide()), self::THUMBNAIL_QUALITY);

            [$stored, $png] = $this->encode($image, $facts, $scaled);
        } catch (MediaRefused $e) {
            throw $e;
        } catch (Throwable) {
            throw MediaRefused::unreadable();
        }

        $size = fstat($stored)['size'] ?? 0;
        $hash = hash_init('sha256');
        hash_update_stream($hash, $stored);
        rewind($stored);

        return new ProcessedImage(
            image: $stored,
            thumbnail: $thumbnail,
            mime: $png ? 'image/png' : 'image/jpeg',
            extension: $png ? 'png' : 'jpg',
            byteSize: $size,
            width: imagesx($image),
            height: imagesy($image),
            hash: hash_final($hash),
        );
    }

    /**
     * The image as the file to store, and whether that file is a PNG.
     *
     * @return array{resource, bool}
     */
    private function encode(GdImage $image, ImageFacts $facts, bool $scaled): array
    {
        if ($facts->mayHaveAlpha && $this->hasTransparency($image)) {
            return [$this->encodePng($image), true];
        }

        $quality = MediaConfig::jpegQuality();

        // Writing a file again at a higher quality than it came with adds
        // bytes and no detail. A scaled image is a new image and gets the
        // configured quality.
        if ($facts->jpegQuality !== null && ! $scaled) {
            $quality = min($quality, max(self::QUALITY_FLOOR, $facts->jpegQuality));
        }

        $jpeg = $this->encodeJpeg($image, $quality);

        if (! $facts->lossless || imagesx($image) * imagesy($image) > MediaConfig::losslessChoicePixels()) {
            return [$jpeg, false];
        }

        $png = $this->encodePng($image);

        if ($this->size($png) <= $this->size($jpeg)) {
            fclose($jpeg);

            return [$png, true];
        }

        fclose($png);

        return [$jpeg, false];
    }

    private function decode(string $path, ImageFacts $facts): GdImage
    {
        try {
            $image = match ($facts->type) {
                ImageFacts::JPEG => @imagecreatefromjpeg($path),
                ImageFacts::PNG => @imagecreatefrompng($path),
                default => @imagecreatefromwebp($path),
            };
        } catch (Throwable) {
            $image = false;
        }

        if ($image === false) {
            throw MediaRefused::unreadable();
        }

        if (! imageistruecolor($image)) {
            imagepalettetotruecolor($image);
        }

        return $image;
    }

    /**
     * Whether any pixel is not fully opaque. The corners are looked at
     * first, where a cut-out is transparent, and then every pixel: an image
     * with one transparent pixel must not be flattened.
     */
    private function hasTransparency(GdImage $image): bool
    {
        $width = imagesx($image);
        $height = imagesy($image);

        foreach ([[0, 0], [$width - 1, 0], [0, $height - 1], [$width - 1, $height - 1], [intdiv($width, 2), intdiv($height, 2)]] as [$x, $y]) {
            if (((int) imagecolorat($image, $x, $y) & 0x7F000000) !== 0) {
                return true;
            }
        }

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                if ((imagecolorat($image, $x, $y) & 0x7F000000) !== 0) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Scales the image down so that its longer side is at most $side. An
     * image that already fits is returned as it is.
     */
    private function fit(GdImage $image, int $side, bool $alpha): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $side) {
            return $image;
        }

        $ratio = $side / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $scaled = $this->canvas($newWidth, $newHeight, $alpha);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $scaled;
    }

    /**
     * A copy with its longer side at most $side, on white where the image
     * is transparent. For the thumbnail, which is always a JPEG.
     */
    private function flatten(GdImage $image, int $side): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $ratio = min(1, $side / max($width, $height));
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $flat = $this->canvas($newWidth, $newHeight, false);
        imagealphablending($flat, true);
        imagecopyresampled($flat, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        return $flat;
    }

    /**
     * Turns and mirrors the image as its EXIF orientation says, so that it
     * is upright without that note.
     */
    private function upright(GdImage $image, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3, 4 => 180,
            5, 6 => 270,
            7, 8 => 90,
            default => 0,
        };

        if ($angle !== 0) {
            $turned = imagerotate($image, $angle, 0);

            if ($turned === false) {
                throw new RuntimeException('The image could not be turned.');
            }

            $image = $turned;
        }

        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($image, IMG_FLIP_HORIZONTAL);
        }

        return $image;
    }

    private function canvas(int $width, int $height, bool $alpha): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($canvas === false) {
            throw new RuntimeException('No canvas could be made.');
        }

        if ($alpha) {
            imagealphablending($canvas, false);
            imagesavealpha($canvas, true);
            imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        } else {
            imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        }

        return $canvas;
    }

    /**
     * @return resource a temporary stream at its start
     */
    private function encodePng(GdImage $image)
    {
        $stream = $this->temporary();

        imagealphablending($image, false);
        imagesavealpha($image, true);

        if (! imagepng($image, $stream, 6)) {
            fclose($stream);

            throw new RuntimeException('The image could not be written.');
        }

        rewind($stream);

        return $stream;
    }

    /**
     * @return resource a temporary stream at its start
     */
    private function encodeJpeg(GdImage $image, int $quality)
    {
        $stream = $this->temporary();

        if (! imagejpeg($image, $stream, $quality)) {
            fclose($stream);

            throw new RuntimeException('The image could not be written.');
        }

        return $this->withoutComment($stream);
    }

    /**
     * GD starts every JPEG with a comment that names the library and the
     * quality. A stored file says nothing about how it was made, so the
     * comment is left out of the copy that is kept.
     *
     * @param  resource  $stream
     * @return resource a temporary stream at its start
     */
    private function withoutComment($stream)
    {
        rewind($stream);

        $head = (string) fread($stream, 4096);
        $comments = array_values(array_filter(JpegSegments::leading($head), fn (array $segment): bool => $segment[0] === 0xFE));

        if ($comments === []) {
            rewind($stream);

            return $stream;
        }

        foreach (array_reverse($comments) as [, , $offset, $length]) {
            $head = substr($head, 0, $offset).substr($head, $offset + $length);
        }

        $clean = $this->temporary();
        fwrite($clean, $head);
        stream_copy_to_stream($stream, $clean);
        fclose($stream);
        rewind($clean);

        return $clean;
    }

    /**
     * @param  resource  $stream
     */
    private function size($stream): int
    {
        return fstat($stream)['size'] ?? 0;
    }

    /**
     * @return resource
     */
    private function temporary()
    {
        $stream = fopen('php://temp/maxmemory:4194304', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('No temporary stream could be opened.');
        }

        return $stream;
    }
}
