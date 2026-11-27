<?php

use App\Domain\Content\Media\EncodingRules;
use App\Domain\Content\Media\GdImageProcessor;
use App\Domain\Content\Media\ImageProcessor;
use App\Domain\Content\Media\JpegQuality;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * What a stored image is: a JPEG or a PNG, decided from its pixels and not
 * from the type it was sent as, and never larger than it has to be.
 */

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->actingAs(userWithRole($this->workspace, Role::Editor));
});

/**
 * Uploads the bytes and returns the stored image.
 */
function stored(string $bytes, string $name = 'image.png'): Media
{
    test()->postJson(route('media.store'), ['file' => uploadOf($bytes, $name)])->assertCreated();

    return Media::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

it('resolves the image engine through one interface', function () {
    expect(app(ImageProcessor::class))->toBeInstanceOf(GdImageProcessor::class);
});

describe('a photograph that was saved in a lossless format', function () {
    it('is stored as a JPEG, far smaller than it came', function (Closure $bytes) {
        $sent = $bytes();
        $media = stored($sent);

        expect($media->mime)->toBe('image/jpeg')
            ->and($media->path)->toEndWith('.jpg')
            ->and($media->byte_size)->toBeLessThan((int) (strlen($sent) / 2))
            ->and(getimagesizefromstring(storedBytes($media))['mime'] ?? null)->toBe('image/jpeg')
            ->and([$media->width, $media->height])->toBe([320, 240]);
    })->with([
        'a PNG' => [fn () => photoLikeBytes('png')],
        'a PNG with an alpha channel in which every pixel is opaque' => [fn () => photoLikeBytes('png', alphaChannel: true)],
        'a lossless WebP' => [fn () => photoLikeBytes('webp')],
    ]);

    it('is stored as a JPEG without trying a PNG when it is large', function () {
        config(['noongun.media.lossless_choice_megapixels' => 0.01]);

        // Flat colour, where a PNG would win if it were tried.
        expect(stored(pngBytes(200, 100, transparent: false))->mime)->toBe('image/jpeg');
    });
});

describe('a screenshot or a drawing', function () {
    it('stays a PNG, exact and smaller than a JPEG of it', function (Closure $bytes) {
        $media = stored($bytes());
        $pixels = imagecreatefromstring(storedBytes($media));

        ob_start();
        imagejpeg($pixels, null, 90);
        $asJpeg = strlen((string) ob_get_clean());

        expect($media->mime)->toBe('image/png')
            ->and($media->path)->toEndWith('.png')
            ->and($media->byte_size)->toBeLessThan($asJpeg)
            // Exact: the flat areas and a stroke have the colours that were drawn.
            ->and(pixelOf(storedBytes($media), 5, 5))->toBe([0x24, 0x29, 0x2F, 0])
            ->and(pixelOf(storedBytes($media), 400, 50))->toBe([0xF7, 0xF7, 0xF5, 0])
            ->and(pixelOf(storedBytes($media), 181, 66))->toBe([0x1F, 0x23, 0x28, 0]);
    })->with([
        'sent as PNG' => [fn () => screenshotLikeBytes('png')],
        'sent as lossless WebP' => [fn () => screenshotLikeBytes('webp')],
    ]);
});

describe('an image with transparency', function () {
    it('stays a PNG when a single pixel is not opaque', function () {
        $image = photoLikeImage(120, 90);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagesetpixel($image, 77, 41, imagecolorallocatealpha($image, 10, 20, 30, 60));

        ob_start();
        imagepng($image);
        $media = stored((string) ob_get_clean());

        expect($media->mime)->toBe('image/png')
            ->and(pixelOf(storedBytes($media), 77, 41))->toBe([10, 20, 30, 60])
            ->and(pixelOf(storedBytes($media), 10, 10)[3])->toBe(0);
    });

    it('stays a PNG whatever its size, also when it was scaled down', function () {
        config(['noongun.media.lossless_choice_megapixels' => 0.001, 'noongun.media.stored_side' => 100]);

        $media = stored(pngBytes(400, 200));

        expect($media->mime)->toBe('image/png')
            ->and([$media->width, $media->height])->toBe([100, 50])
            ->and(pixelOf(storedBytes($media), 1, 1)[3])->toBe(127);
    });

    it('is found in a PNG that says so with a palette', function () {
        $image = imagecreate(40, 20);
        imagecolortransparent($image, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 10, 5, 30, 15, imagecolorallocate($image, 200, 0, 0));

        ob_start();
        imagepng($image);
        $media = stored((string) ob_get_clean());

        expect($media->mime)->toBe('image/png')
            ->and(pixelOf(storedBytes($media), 1, 1)[3])->toBe(127)
            ->and(pixelOf(storedBytes($media), 20, 10))->toBe([200, 0, 0, 0]);
    });
});

describe('the quality of a stored JPEG', function () {
    it('estimates the quality a JPEG was saved with from its tables', function (int $quality) {
        expect(abs((int) JpegQuality::fromBytes(jpegAtQuality($quality)) - $quality))->toBeLessThanOrEqual(1);
    })->with([30, 50, 60, 75, 85, 90, 95]);

    it('has no estimate for what is not a JPEG or has no table', function () {
        expect(JpegQuality::fromBytes(pngBytes()))->toBeNull()
            ->and(JpegQuality::fromBytes(''))->toBeNull()
            ->and(JpegQuality::fromBytes("\xFF\xD8\xFF\xDB\x00\x05\x00\x01\x02"))->toBeNull()
            ->and(JpegQuality::fromBytes(substr(jpegAtQuality(80), 0, 40)))->toBeNull();
    });

    it('does not store a JPEG at a higher quality than it came with', function (int $quality, int $expected) {
        $sent = jpegAtQuality($quality);
        $media = stored($sent, 'photo.jpg');

        expect(JpegQuality::fromBytes(storedBytes($media)))->toBe($expected)
            // Written again, so not the same bytes, but not a larger file.
            ->and(storedBytes($media))->not->toBe($sent)
            ->and($media->byte_size)->toBeLessThanOrEqual((int) (strlen($sent) * 1.05));
    })->with([
        'web quality stays' => [70, 70],
        'a little under the configured quality stays' => [85, 85],
        'the configured quality is the most' => [97, 90],
    ]);

    it('never stores a JPEG below the floor, whatever its tables say', function () {
        expect(JpegQuality::fromBytes(storedBytes(stored(jpegAtQuality(30), 'photo.jpg'))))->toBe(EncodingRules::QUALITY_FLOOR);
    });

    it('would have made the file larger at the configured quality', function () {
        $sent = jpegAtQuality(70);

        ob_start();
        imagejpeg(imagecreatefromstring($sent), null, 90);

        expect(strlen((string) ob_get_clean()))->toBeGreaterThan((int) (strlen($sent) * 1.4));
    });

    it('uses the configured quality for an image it scales down, which is a new image', function () {
        config(['noongun.media.stored_side' => 160]);

        $media = stored(jpegAtQuality(65), 'photo.jpg');

        expect($media->width)->toBe(160)
            ->and(JpegQuality::fromBytes(storedBytes($media)))->toBe(90);
    });
});

describe('what a stored file says about itself', function () {
    it('carries no comment, also not the one the image library writes', function (Closure $bytes) {
        $media = stored($bytes(), 'photo.jpg');

        foreach ([storedBytes($media), storedBytes($media, thumbnail: true)] as $file) {
            expect($file)->not->toContain('CREATOR')
                ->not->toContain('gd-jpeg')
                ->not->toContain("\xFF\xFE")
                ->and(getimagesizefromstring($file))->not->toBeFalse()
                ->and(imagecreatefromstring($file))->toBeInstanceOf(GdImage::class);
        }
    })->with([
        'a JPEG' => [fn () => jpegBytes(withLocation: true)],
        'a photograph sent as PNG' => [fn () => photoLikeBytes('png')],
    ]);
});

describe('an animation', function () {
    it('is refused instead of being stored as its first frame', function (Closure $bytes, string $name) {
        $this->postJson(route('media.store'), ['file' => uploadOf($bytes(), $name)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'Animated images are not supported, because an animation would be published as a still picture. Save one frame as PNG or JPEG.']);

        expect(Media::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->disk->allFiles())->toBe([]);
    })->with([
        'an animated PNG' => [fn () => animatedPngBytes(), 'spinner.png'],
        'an animated WebP' => [fn () => animatedWebpBytes(), 'spinner.webp'],
    ]);

    it('would be decoded as a still picture if it were not caught', function () {
        // The reason for the check: the image library reads an animated
        // PNG as its first frame and says nothing.
        expect(imagecreatefromstring(animatedPngBytes()))->toBeInstanceOf(GdImage::class);
    });

    it('still takes a PNG with other chunks before its image data', function () {
        $png = pngBytes(transparent: false);
        $chunk = 'tEXt'."Comment\0acTL is only a word here";

        $media = stored(substr($png, 0, 33).pack('N', strlen($chunk) - 4).$chunk.pack('N', crc32($chunk)).substr($png, 33));

        expect($media->width)->toBe(40)
            ->and(storedBytes($media))->not->toContain('only a word here');
    });
});
