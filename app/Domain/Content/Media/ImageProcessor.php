<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;
use Illuminate\Container\Attributes\Bind;

/**
 * Turns an uploaded file into an image this application wrote itself. The
 * one place the rest of the application meets an image library: StoreMedia
 * asks for this and knows nothing of what decodes the pixels. A different
 * engine is another class that implements this and one changed line here.
 *
 * What every implementation owes:
 *
 * - The gate comes first. ImageInspector decides from the header whether
 *   the file may be decoded at all, and its limits hold for every engine.
 * - What comes back is new: decoded and encoded again, a JPEG or a PNG,
 *   upright, at most MediaConfig::storedSide() on its longer side, with a
 *   small JPEG copy. Nothing of the metadata of the upload survives: no
 *   EXIF, no position, no comment, nothing behind the image data.
 * - A file that cannot be stored is refused with MediaRefused, in a
 *   sentence for the person who sent it.
 */
#[Bind(GdImageProcessor::class)]
interface ImageProcessor
{
    /**
     * @param  string  $path  the uploaded file, on the local disk where PHP put it
     *
     * @throws MediaRefused
     */
    public function process(string $path): ProcessedImage;
}
