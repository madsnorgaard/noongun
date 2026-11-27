<?php

namespace App\Domain\Content\Media;

use App\Domain\Content\Exceptions\MediaRefused;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Throwable;

/**
 * ImageProcessor with libvips, through its command-line program.
 *
 * What it does that GD cannot: it reads the colour profile of the upload
 * and converts the pixels to sRGB with it before the profile is dropped. A
 * photo in Display P3, Adobe RGB or ProPhoto keeps its colours, a CMYK file
 * from a print shop comes out as the picture it was, and sixteen bits a
 * channel become eight. The stored file carries no profile: its pixels are
 * sRGB, which is what a file without a profile is taken to be.
 *
 * The conversion is relative colorimetric with black point compensation. A
 * colour that sRGB can show stays exactly that colour, one that it cannot
 * show becomes the nearest it can, and the darkest tone of the source
 * becomes black. A profile that is damaged or does not fit the image is
 * left aside, and the pixels are taken as sRGB, as GD takes them.
 *
 * ImageInspector has said what the file is before anything here runs, and
 * the file is opened with the loader for that one format and no other:
 * libvips is never asked to find out what a file is, so its loaders for
 * TIFF, GIF, SVG, PDF and the rest are out of reach of an upload. On top of
 * that the run that reads the upload has VIPS_BLOCK_UNTRUSTED set.
 *
 * Each step is one short run of vips (see VipsCommand), so the decoded
 * image is never in the memory of PHP, and what a step took is given back
 * when it ends. The steps hand each other files in VipsScratch.
 */
class VipsImageProcessor implements ImageProcessor
{
    private const LOADERS = [
        ImageFacts::JPEG => 'jpegload',
        ImageFacts::PNG => 'pngload',
        ImageFacts::WEBP => 'webpload',
    ];

    /** Seconds of the lease of a processing slot that are left for storing the result. */
    private const LEASE_MARGIN_SECONDS = 5;

    public function __construct(
        private readonly ImageInspector $inspector,
        private readonly VipsCommand $vips,
    ) {}

    public function process(string $path): ProcessedImage
    {
        if (! $this->vips->available()) {
            throw MediaRefused::notSupportedHere();
        }

        $facts = $this->inspector->inspect($path);
        $side = MediaConfig::storedSide();
        $scaled = max($facts->width, $facts->height) > $side;

        $scratch = VipsScratch::open($this->vips, max(5, MediaConfig::slotLeaseSeconds() - self::LEASE_MARGIN_SECONDS));

        try {
            // Colour first: scaling a CMYK image would convert it on the
            // way, without what toSrgb() asks for.
            $image = $this->toSrgb($scratch, $this->decode($scratch, $path, $facts, $side));

            if ($scaled) {
                $image = $this->fit($scratch, $image, $side);
            }

            if ($facts->type === ImageFacts::JPEG) {
                $image = $this->upright($scratch, $image, JpegOrientation::of($path));
            }

            [$image, $transparent] = $this->alpha($scratch, $image, $facts);

            $thumbnail = $this->encodeJpeg($scratch, $this->fit($scratch, $image, MediaConfig::thumbnailSide(), keep: true), EncodingRules::THUMBNAIL_QUALITY);

            [$stored, $png] = $this->encode($scratch, $image, $facts, $scaled, $transparent);

            $size = getimagesize($stored);

            if ($size === false) {
                throw new RuntimeException('The image that was written cannot be read.');
            }

            return ProcessedImage::of($this->open($stored), $this->open($thumbnail), $png, $size[0], $size[1]);
        } catch (MediaRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            // A file libvips could not make sense of was refused in
            // decode(). What ends here went wrong on this side: a step
            // that failed or took too long, a disk that is full.
            Log::warning('An image could not be processed with libvips.', [
                'exception' => $e::class,
                'exit_code' => $e instanceof ProcessFailedException ? $e->getProcess()->getExitCode() : null,
            ]);

            throw MediaRefused::unreadable();
        } finally {
            // The two results are open by now, and an open file outlives
            // its name.
            $scratch->close();
        }
    }

    /**
     * The upload as plain pixels, read with the loader of its format. A
     * JPEG far larger than it will be stored is decoded at a half, a
     * quarter or an eighth of its size, which the format allows for free.
     *
     * @throws MediaRefused when libvips cannot read the file
     */
    private function decode(VipsScratch $scratch, string $path, ImageFacts $facts, int $side): string
    {
        $image = $scratch->file();
        $options = [];

        if ($facts->type === ImageFacts::JPEG) {
            $shrink = 8;

            while ($shrink > 1 && intdiv(max($facts->width, $facts->height), $shrink) < $side) {
                $shrink = intdiv($shrink, 2);
            }

            $options = ['--shrink', (string) $shrink];
        }

        try {
            $scratch->run([self::LOADERS[$facts->type], $path, $image, ...$options], untrusted: true);
        } catch (ProcessFailedException) {
            throw MediaRefused::unreadable();
        }

        return $image;
    }

    /**
     * Scales the image down so that its longer side is at most $side. An
     * image that already fits comes out as it is.
     *
     * @param  bool  $keep  whether the image that was read is still needed
     */
    private function fit(VipsScratch $scratch, string $image, int $side, bool $keep = false): string
    {
        $options = [(string) $side, '--height', (string) $side, '--size', 'down', '--no-rotate'];

        if (! $keep) {
            return $scratch->apply('thumbnail', $image, ...$options);
        }

        $small = $scratch->file();
        $scratch->run(['thumbnail', $image, $small, ...$options]);

        return $small;
    }

    /**
     * The pixels as sRGB with eight bits a channel. The profile in the
     * file says what its numbers mean; without one libvips takes RGB as
     * sRGB, which changes nothing, and CMYK as a common press profile.
     * When the conversion fails, the image is taken as what libvips makes
     * of it without a profile.
     */
    private function toSrgb(VipsScratch $scratch, string $image): string
    {
        try {
            return $scratch->apply('icc_transform', $image, 'srgb', '--embedded', '--intent', 'relative', '--black-point-compensation', '--depth', '8');
        } catch (ProcessFailedException) {
            return $scratch->apply('colourspace', $image, 'srgb');
        }
    }

    /**
     * Turns and mirrors the image as its EXIF orientation says, so that it
     * is upright without that note.
     */
    private function upright(VipsScratch $scratch, string $image, int $orientation): string
    {
        $turns = JpegOrientation::quarterTurns($orientation);

        if ($turns !== 0) {
            $image = $scratch->apply('rot', $image, 'd'.($turns * 90));
        }

        if (JpegOrientation::mirrored($orientation)) {
            $image = $scratch->apply('flip', $image, 'horizontal');
        }

        return $image;
    }

    /**
     * Whether any pixel is not fully opaque, and the image without its
     * alpha channel when none is: an export from an editor often has one
     * in which every pixel is opaque.
     *
     * @return array{string, bool}
     */
    private function alpha(VipsScratch $scratch, string $image, ImageFacts $facts): array
    {
        if (! $facts->mayHaveAlpha) {
            return [$image, false];
        }

        $lowest = $this->lowestAlpha($scratch, $image);

        if ($lowest === null) {
            return [$image, false];
        }

        if ($lowest < 255) {
            return [$image, true];
        }

        return [$scratch->apply('extract_band', $image, '0', '--n', '3'), false];
    }

    /**
     * The lowest value in the alpha channel, or null when the image has
     * none. libvips writes the statistics of an image as a table: one row
     * for the whole image and one for each channel, the minimum first.
     */
    private function lowestAlpha(VipsScratch $scratch, string $image): ?float
    {
        $table = $scratch->file('csv');

        $scratch->run(['stats', $image, $table]);

        $rows = array_values(array_filter(array_map(trim(...), explode("\n", (string) file_get_contents($table)))));

        if (count($rows) !== 5) {
            return null;
        }

        $lowest = (preg_split('/[\s,;]+/', $rows[4]) ?: [])[0] ?? '';

        if (! is_numeric($lowest)) {
            throw new RuntimeException('The statistics of the image cannot be read.');
        }

        return (float) $lowest;
    }

    /**
     * The image as the file to store, and whether that file is a PNG.
     *
     * @return array{string, bool}
     */
    private function encode(VipsScratch $scratch, string $image, ImageFacts $facts, bool $scaled, bool $transparent): array
    {
        if ($transparent) {
            return [$this->encodePng($scratch, $image), true];
        }

        $jpeg = $this->encodeJpeg($scratch, $image, EncodingRules::jpegQuality($facts, $scaled));
        $size = getimagesize($jpeg);

        if ($size === false || ! EncodingRules::triesPng($facts, $size[0] * $size[1])) {
            return [$jpeg, false];
        }

        $png = $this->encodePng($scratch, $image);

        return filesize($png) <= filesize($jpeg) ? [$png, true] : [$jpeg, false];
    }

    /**
     * A JPEG with nothing in it but the picture. The colour is stored at
     * half the resolution, as GD stores it and as a camera does. Where the
     * image is transparent it is put on white.
     */
    private function encodeJpeg(VipsScratch $scratch, string $image, int $quality): string
    {
        $file = $scratch->file('jpg');

        $scratch->run([
            'jpegsave', $image, $file,
            '--Q', (string) $quality,
            '--keep', 'none',
            '--subsample-mode', 'on',
            '--optimize-coding',
            '--background', '255 255 255',
        ]);

        return $file;
    }

    private function encodePng(VipsScratch $scratch, string $image): string
    {
        $file = $scratch->file('png');

        $scratch->run([
            'pngsave', $image, $file,
            '--compression', (string) EncodingRules::PNG_COMPRESSION,
            '--filter', 'all',
            '--keep', 'none',
        ]);

        return $file;
    }

    /**
     * @return resource
     *
     * @throws ExceptionInterface|RuntimeException
     */
    private function open(string $file)
    {
        $stream = fopen($file, 'rb');

        if ($stream === false) {
            throw new RuntimeException('A file that was written cannot be opened.');
        }

        return $stream;
    }
}
