<?php

namespace App\Domain\Content\Exceptions;

use App\Domain\Content\Models\Media;
use RuntimeException;

/**
 * A media row whose file cannot be read from its disk.
 */
class MediaFileMissing extends RuntimeException
{
    public static function for(Media $media): self
    {
        return new self("The file of image #{$media->getKey()} cannot be read from the disk [{$media->disk}].");
    }
}
