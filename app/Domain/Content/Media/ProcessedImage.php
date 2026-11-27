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

    /**
     * From the two files an engine wrote, each an open stream. The size and
     * the hash are read from the image itself.
     *
     * @param  resource  $image
     * @param  resource  $thumbnail
     */
    public static function of($image, $thumbnail, bool $png, int $width, int $height): self
    {
        rewind($image);
        rewind($thumbnail);

        $hash = hash_init('sha256');
        hash_update_stream($hash, $image);
        rewind($image);

        return new self(
            image: $image,
            thumbnail: $thumbnail,
            mime: $png ? 'image/png' : 'image/jpeg',
            extension: $png ? 'png' : 'jpg',
            byteSize: fstat($image)['size'] ?? 0,
            width: $width,
            height: $height,
            hash: hash_final($hash),
        );
    }

    public function close(): void
    {
        foreach ([$this->image, $this->thumbnail] as $stream) {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
