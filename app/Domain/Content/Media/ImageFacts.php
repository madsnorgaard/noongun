<?php

namespace App\Domain\Content\Media;

/**
 * What the header of an uploaded file says about it, read without decoding
 * a pixel. An image engine decides from this how to decode and what to
 * write.
 */
final readonly class ImageFacts
{
    public const JPEG = 'jpeg';

    public const PNG = 'png';

    public const WEBP = 'webp';

    /**
     * @param  self::JPEG|self::PNG|self::WEBP  $type
     * @param  bool  $mayHaveAlpha  whether the format of this file can carry transparency; whether it does is only known once it is decoded
     * @param  bool  $lossless  whether the file may hold its pixels exactly, as a screenshot or a drawing is saved
     * @param  int|null  $jpegQuality  the estimated quality of a JPEG, see JpegQuality
     */
    public function __construct(
        public string $type,
        public int $width,
        public int $height,
        public bool $mayHaveAlpha,
        public bool $lossless,
        public ?int $jpegQuality = null,
    ) {}

    public function pixels(): int
    {
        return $this->width * $this->height;
    }
}
