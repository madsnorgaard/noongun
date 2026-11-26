<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;
use GdImage;
use RuntimeException;
use Throwable;

/**
 * Turns an uploaded file into an image this application wrote itself.
 *
 * What a file is, is decided from its content and never from its name or
 * from the type the browser gave. The header says the type and the size in
 * pixels; a file that is not a JPEG, PNG or WebP, or is larger than the
 * limits, is refused before anything is decoded. What is left is decoded
 * and encoded again, and only the result is kept. That drops everything
 * that is not a pixel: EXIF with the place a photo was taken, comments,
 * colour profiles, and anything hidden behind the image data.
 *
 * A stored image is a JPEG or a PNG, the two types every platform takes. A
 * WebP becomes a PNG when it has transparency and a JPEG when it has none.
 */
class ImageProcessor
{
    /**
     * @param  string  $path  the uploaded file, on the local disk where PHP put it
     *
     * @throws MediaRefused
     */
    public function process(string $path): ProcessedImage
    {
        if (! function_exists('imagecreatetruecolor') || ! function_exists('imagecreatefromwebp')) {
            throw MediaRefused::notSupportedHere();
        }

        [$type, $width, $height] = $this->inspect($path);

        if ($width > MediaConfig::maxSide() || $height > MediaConfig::maxSide() || $width * $height > MediaConfig::maxPixels()) {
            throw MediaRefused::tooManyPixels($width, $height, MediaConfig::maxPixels(), MediaConfig::maxSide());
        }

        $transparent = match ($type) {
            IMAGETYPE_PNG => true,
            IMAGETYPE_WEBP => $this->webpHasAlpha($path),
            default => false,
        };

        MediaConfig::raiseMemoryLimit();

        $image = $this->decode($path, $type);

        try {
            $image = $this->fit($image, MediaConfig::storedSide(), $transparent);

            if ($type === IMAGETYPE_JPEG) {
                $image = $this->upright($image, JpegOrientation::of($path));
            }

            $thumbnail = $this->flatten($image, MediaConfig::thumbnailSide());

            $stored = $this->encode($image, $transparent);
            $small = $this->encode($thumbnail, false, 80);
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
            thumbnail: $small,
            mime: $transparent ? 'image/png' : 'image/jpeg',
            extension: $transparent ? 'png' : 'jpg',
            byteSize: $size,
            width: imagesx($image),
            height: imagesy($image),
            hash: hash_final($hash),
        );
    }

    /**
     * Type and size from the header of the file. Reads no pixel.
     *
     * @return array{int, int, int}
     */
    private function inspect(string $path): array
    {
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

        if ($info[0] < 1 || $info[1] < 1) {
            throw MediaRefused::unreadable();
        }

        return [$info[2], $info[0], $info[1]];
    }

    private function decode(string $path, int $type): GdImage
    {
        try {
            $image = match ($type) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
                IMAGETYPE_PNG => @imagecreatefrompng($path),
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
     * Scales the image down so that its longer side is at most $side. An
     * image that already fits is returned as it is.
     */
    private function fit(GdImage $image, int $side, bool $transparent): GdImage
    {
        $width = imagesx($image);
        $height = imagesy($image);

        if (max($width, $height) <= $side) {
            return $image;
        }

        $ratio = $side / max($width, $height);
        $newWidth = max(1, (int) round($width * $ratio));
        $newHeight = max(1, (int) round($height * $ratio));

        $scaled = $this->canvas($newWidth, $newHeight, $transparent);
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

    private function canvas(int $width, int $height, bool $transparent): GdImage
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));

        if ($canvas === false) {
            throw new RuntimeException('No canvas could be made.');
        }

        if ($transparent) {
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
    private function encode(GdImage $image, bool $transparent, ?int $quality = null)
    {
        $stream = fopen('php://temp/maxmemory:4194304', 'w+b');

        if ($stream === false) {
            throw new RuntimeException('No temporary stream could be opened.');
        }

        if ($transparent) {
            imagealphablending($image, false);
            imagesavealpha($image, true);
            $written = imagepng($image, $stream, 6);
        } else {
            $written = imagejpeg($image, $stream, $quality ?? MediaConfig::jpegQuality());
        }

        if (! $written) {
            fclose($stream);

            throw new RuntimeException('The image could not be written.');
        }

        rewind($stream);

        return $stream;
    }

    /**
     * Whether a WebP file says that it has transparency. Read from its
     * header. When the header is not understood the answer is yes, which
     * only costs a larger file.
     */
    private function webpHasAlpha(string $path): bool
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return true;
        }

        $header = (string) fread($handle, 32);
        fclose($handle);

        if (strlen($header) < 25 || substr($header, 0, 4) !== 'RIFF' || substr($header, 8, 4) !== 'WEBP') {
            return true;
        }

        return match (substr($header, 12, 4)) {
            'VP8 ' => false,
            'VP8L' => (ord($header[24]) & 0x10) !== 0,
            'VP8X' => (ord($header[20]) & 0x10) !== 0,
            default => true,
        };
    }
}
