<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;

/**
 * Turns an uploaded file into an image this application wrote itself. The
 * one place the rest of the application meets an image library: StoreMedia
 * asks for this and knows nothing of what decodes the pixels. Which engine
 * answers is a setting, see ImageEngine.
 *
 * What every implementation owes:
 *
 * - The gate comes first. ImageInspector decides from the header whether
 *   the file may be decoded at all, and its limits hold for every engine.
 * - What comes back is new: decoded and encoded again, a JPEG or a PNG as
 *   EncodingRules says, upright, at most MediaConfig::storedSide() on its
 *   longer side, with a small JPEG copy. Nothing of the metadata of the
 *   upload survives: no EXIF, no position, no comment, no colour profile,
 *   nothing behind the image data.
 * - The pixels are meant as sRGB. An engine that can read the colour
 *   profile of the upload converts with it before the profile is dropped.
 * - A file that cannot be stored is refused with MediaRefused, in a
 *   sentence for the person who sent it.
 */
interface ImageProcessor
{
    /**
     * @param  string  $path  the uploaded file, on the local disk where PHP put it
     *
     * @throws MediaRefused
     */
    public function process(string $path): ProcessedImage;
}
