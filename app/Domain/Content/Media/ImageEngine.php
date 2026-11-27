<?php

namespace App\Domain\Content\Media;

/**
 * The image engines an instance can be set to, in noongun.media.engine.
 *
 * libvips is the default: it converts colours with the profile of the
 * upload. GD is the image library of PHP itself and what an instance falls
 * back to; it stores the numbers of the pixels as they came.
 */
enum ImageEngine: string
{
    case Vips = 'vips';
    case Gd = 'gd';

    /**
     * @return class-string<ImageProcessor>
     */
    public function processor(): string
    {
        return match ($this) {
            self::Vips => VipsImageProcessor::class,
            self::Gd => GdImageProcessor::class,
        };
    }

    /**
     * Whether this container has what the engine needs.
     */
    public function available(): bool
    {
        return match ($this) {
            self::Vips => app(VipsCommand::class)->available(),
            self::Gd => GdImageProcessor::available(),
        };
    }

    /**
     * What to tell the person who runs the instance when it is not.
     */
    public function missing(): string
    {
        return match ($this) {
            self::Vips => 'The image engine is set to vips (NOONGUN_MEDIA_ENGINE), but the vips program of libvips is not in this container, so no image can be uploaded. Rebuild the Docker image, or set NOONGUN_MEDIA_ENGINE=gd.',
            self::Gd => 'The image engine is set to gd (NOONGUN_MEDIA_ENGINE), but the GD extension of PHP with JPEG, PNG and WebP is not in this container, so no image can be uploaded. Rebuild the Docker image.',
        };
    }
}
