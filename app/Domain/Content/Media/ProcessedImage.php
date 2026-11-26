<?php

namespace App\Domain\Content\Media;

/**
 * An upload after ImageProcessor has decoded it and written it out again:
 * the image to store and its small copy, each as a stream at its start.
 * Everything here describes those new files, not the file that was sent.
 */
final class ProcessedImage
{
    /**
     * @param  resource  $image
     * @param  resource  $thumbnail  always a JPEG
     * @param  'image/jpeg'|'image/png'  $mime
     * @param  'jpg'|'png'  $extension
     * @param  string  $hash  SHA-256 of the image, in hexadecimal
     */
    public function __construct(
        public $image,
        public $thumbnail,
        public string $mime,
        public string $extension,
        public int $byteSize,
        public int $width,
        public int $height,
        public string $hash,
    ) {}

    public function close(): void
    {
        foreach ([$this->image, $this->thumbnail] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
