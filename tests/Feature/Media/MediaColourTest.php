<?php

use App\Domain\Content\Media\ImageEngine;
use App\Domain\Content\Media\ImageProcessor;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/colour.php';

/*
 * The colours of a stored image. Its pixels are sRGB and it carries no
 * profile. libvips reads the profile of the upload and converts with it;
 * GD keeps the numbers as they came.
 */

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->actingAs(userWithRole($this->workspace, Role::Editor));
});

/**
 * Uploads the bytes and returns the stored file.
 */
function storedColourBytes(string $bytes, string $name = 'photo.jpg'): string
{
    test()->postJson(route('media.store'), ['file' => uploadOf($bytes, $name)])->assertCreated();

    return storedBytes(Media::query()->withoutGlobalScopes()->latest('id')->firstOrFail());
}

/**
 * The colour in the middle of an image of one colour.
 *
 * @return array{int, int, int}
 */
function colourOf(string $bytes): array
{
    [$red, $green, $blue] = pixelOf($bytes, 16, 16);

    return [$red, $green, $blue];
}

describe('a photo in a wide colour space', function () {
    it('has numbers that mean another colour than the same numbers in sRGB', function () {
        // What the tests below rest on: the two patches are one colour,
        // and far enough apart as numbers to tell a conversion from none.
        expect(colourDistance(p3Patch(), srgbPatch()))->toBeGreaterThan(10)
            ->and(strlen(displayP3Profile()))->toBeLessThan(1024);
    });

    it('is converted to sRGB with its profile by libvips', function (Closure $bytes, string $name) {
        useImageEngine(ImageEngine::Vips);

        $stored = storedColourBytes($bytes(), $name);

        expect(colourDistance(colourOf($stored), srgbPatch()))->toBeLessThanOrEqual(3);
    })->with([
        'a JPEG in Display P3' => [fn () => jpegWithProfile(flatJpegBytes(p3Patch()), displayP3Profile()), 'photo.jpg'],
        'a PNG in Display P3' => [fn () => pngWithProfile(flatPngBytes(p3Patch()), displayP3Profile()), 'screenshot.png'],
        'a WebP in Display P3' => [fn () => madeWithVips(jpegWithProfile(flatJpegBytes(p3Patch()), displayP3Profile()), 'jpg', 'webp', ['webpsave'], ['--lossless']), 'photo.webp'],
    ]);

    it('keeps a screenshot in Display P3 an exact PNG', function () {
        useImageEngine(ImageEngine::Vips);

        $stored = storedColourBytes(pngWithProfile(flatPngBytes(p3Patch(), 64, 48), displayP3Profile()), 'screenshot.png');

        expect(getimagesizefromstring($stored)['mime'] ?? null)->toBe('image/png')
            ->and(colourDistance(pixelOf($stored, 0, 0), srgbPatch()))->toBeLessThanOrEqual(1)
            ->and(pixelOf($stored, 63, 47))->toBe(pixelOf($stored, 0, 0));
    });

    it('is stored duller by GD, which keeps the numbers and drops the profile', function () {
        useImageEngine(ImageEngine::Gd);

        $stored = storedColourBytes(jpegWithProfile(flatJpegBytes(p3Patch()), displayP3Profile()));

        expect(colourDistance(colourOf($stored), p3Patch()))->toBeLessThanOrEqual(3)
            ->and(colourDistance(colourOf($stored), srgbPatch()))->toBeGreaterThan(10);
    });

    it('leaves an image without a profile exactly as it is', function () {
        $stored = storedColourBytes(flatPngBytes(srgbPatch()), 'drawing.png');

        expect(colourOf($stored))->toBe(srgbPatch());
    })->with('image engines');
});

describe('a profile that cannot be used', function () {
    it('does not stop the upload: the pixels are taken as sRGB', function (Closure $profile) {
        $stored = storedColourBytes(jpegWithProfile(flatJpegBytes(p3Patch()), $profile()));

        expect(colourDistance(colourOf($stored), p3Patch()))->toBeLessThanOrEqual(3)
            ->and($stored)->not->toContain('ICC_PROFILE');
    })->with([
        'bytes that are no profile' => [fn () => str_repeat('not a colour profile ', 24)],
        'a profile cut off in the middle' => [fn () => substr(displayP3Profile(), 0, 200)],
        'a profile with its tags overwritten' => [fn () => substr(displayP3Profile(), 0, 128).str_repeat("\xA5", strlen(displayP3Profile()) - 128)],
        'an empty profile' => [fn () => ''],
    ])->with('image engines');
});

describe('a CMYK file', function () {
    it('comes out as the sRGB picture it was', function (bool $withProfile) {
        useImageEngine(ImageEngine::Vips);

        $sent = cmykJpegBytes(srgbPatch(), $withProfile);

        expect(getimagesizefromstring($sent)['channels'] ?? null)->toBe(4)
            ->and(str_contains($sent, 'ICC_PROFILE'))->toBe($withProfile);

        $stored = storedColourBytes($sent);

        expect(getimagesizefromstring($stored)['channels'] ?? null)->toBe(3)
            ->and(colourDistance(colourOf($stored), srgbPatch()))->toBeLessThanOrEqual(6)
            ->and($stored)->not->toContain('ICC_PROFILE');
    })->with([
        'that names its press profile' => [true],
        'without a profile, taken as a common press profile' => [false],
    ]);

    it('keeps black black', function () {
        useImageEngine(ImageEngine::Vips);

        // Without black point compensation the darkest ink comes out as a
        // dark grey, and every shadow of the picture with it.
        expect(max(colourOf(storedColourBytes(cmykJpegBytes([0, 0, 0])))))->toBeLessThan(16);
    });

    it('is stored in other colours by GD, which knows no CMYK profile', function () {
        if (! ImageEngine::Vips->available()) {
            $this->markTestSkipped('The CMYK file is made with libvips, which is not in this container.');
        }

        $sent = cmykJpegBytes(srgbPatch());

        useImageEngine(ImageEngine::Gd);

        expect(colourDistance(colourOf(storedColourBytes($sent)), srgbPatch()))->toBeGreaterThan(10);
    });
});

describe('sixteen bits a channel', function () {
    it('become eight', function () {
        $sent = sixteenBitPngBytes(srgbPatch());

        expect(getimagesizefromstring($sent)['bits'] ?? null)->toBe(16);

        $stored = storedColourBytes($sent, 'scan.png');

        expect(getimagesizefromstring($stored)['bits'] ?? null)->toBe(8)
            ->and(colourOf($stored))->toBe(srgbPatch());
    })->with('image engines');

    it('become eight after the colours are converted', function () {
        useImageEngine(ImageEngine::Vips);

        $stored = storedColourBytes(pngWithProfile(sixteenBitPngBytes(p3Patch()), displayP3Profile()), 'scan.png');

        expect(getimagesizefromstring($stored)['bits'] ?? null)->toBe(8)
            ->and(colourDistance(colourOf($stored), srgbPatch()))->toBeLessThanOrEqual(1);
    });
});

describe('what a stored file carries besides its pixels', function () {
    it('is nothing: no profile, no EXIF, no XMP, no comment and nothing behind the image', function () {
        $xmp = "http://ns.adobe.com/xap/1.0/\0<x:xmpmeta xmlns:x=\"adobe:ns:meta/\">".exifMarker().'</x:xmpmeta>';
        $comment = 'A comment with '.payloadMarker();

        $sent = jpegWithProfile(jpegBytes(withLocation: true), displayP3Profile());
        $sent = substr($sent, 0, 2)
            ."\xFF\xE1".pack('n', strlen($xmp) + 2).$xmp
            ."\xFF\xFE".pack('n', strlen($comment) + 2).$comment
            .substr($sent, 2)
            .'<script>'.payloadMarker().'</script>';

        expect($sent)->toContain('ICC_PROFILE')->toContain('Exif')->toContain('xmpmeta');

        test()->postJson(route('media.store'), ['file' => uploadOf($sent)])->assertCreated();

        $media = Media::query()->withoutGlobalScopes()->sole();

        foreach ([storedBytes($media), storedBytes($media, thumbnail: true)] as $stored) {
            expect($stored)->not->toContain('ICC_PROFILE')
                ->not->toContain('Display P3')
                ->not->toContain('Exif')
                ->not->toContain('xmpmeta')
                ->not->toContain('ns.adobe.com')
                ->not->toContain(exifMarker())
                ->not->toContain(payloadMarker())
                ->not->toContain("\xFF\xE1")
                ->not->toContain("\xFF\xE2")
                ->not->toContain("\xFF\xFE")
                ->and(substr($stored, -2))->toBe("\xFF\xD9");
        }
    })->with('image engines');

    it('has only the chunks of a PNG that hold the picture', function () {
        $sent = pngWithChunk(pngWithProfile(flatPngBytes(p3Patch()), displayP3Profile()), 'tEXt', "Comment\0".payloadMarker());
        $sent = pngWithChunk($sent, 'eXIf', substr(exifBlock(6, withLocation: true), 6));

        $stored = storedColourBytes($sent, 'drawing.png');

        expect(getimagesizefromstring($stored)['mime'] ?? null)->toBe('image/png');

        foreach (['iCCP', 'tEXt', 'zTXt', 'iTXt', 'eXIf', 'tIME', exifMarker(), payloadMarker(), 'Display P3'] as $unwanted) {
            expect($stored)->not->toContain($unwanted);
        }
    })->with('image engines');

    it('gives the same file for the same picture every time, so that it is stored once', function () {
        $sent = jpegWithProfile(flatJpegBytes(p3Patch()), displayP3Profile());

        test()->postJson(route('media.store'), ['file' => uploadOf($sent)])->assertCreated();
        test()->postJson(route('media.store'), ['file' => uploadOf($sent)])->assertOk();

        expect(Media::query()->withoutGlobalScopes()->count())->toBe(1);
    })->with('image engines');
});

it('leaves no temporary file behind', function () {
    useImageEngine(ImageEngine::Vips);

    $before = glob(sys_get_temp_dir().'/noongun-image-*') ?: [];

    storedColourBytes(jpegWithProfile(flatJpegBytes(p3Patch()), displayP3Profile()));

    test()->postJson(route('media.store'), ['file' => uploadOf(substr(jpegBytes(), 0, 200))])->assertUnprocessable();

    expect(glob(sys_get_temp_dir().'/noongun-image-*') ?: [])->toBe($before)
        ->and(app(ImageProcessor::class))->toBeInstanceOf(ImageEngine::Vips->processor());
});
