<?php

use App\Domain\Content\Exceptions\MediaRefused;
use App\Domain\Content\Media\GdImageProcessor;
use App\Domain\Content\Media\ImageEngine;
use App\Domain\Content\Media\ImageFacts;
use App\Domain\Content\Media\ImageInspector;
use App\Domain\Content\Media\ImageProcessor;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Media\VipsCommand;
use App\Domain\Content\Media\VipsImageProcessor;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Symfony\Component\Process\Exception\ProcessFailedException;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/colour.php';

/*
 * Which engine processes uploads, what happens when it is not there, and
 * what libvips is kept from reading.
 */

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->actingAs(userWithRole($this->workspace, Role::Editor));
});

/**
 * Makes the container look as if it had no vips program.
 */
function withoutVips(): void
{
    app()->instance(VipsCommand::class, new class extends VipsCommand
    {
        public function available(): bool
        {
            return false;
        }
    });
}

/**
 * The bytes as a file on the local disk, as PHP puts an upload there.
 */
function fileOf(string $bytes): string
{
    $path = (string) tempnam(sys_get_temp_dir(), 'noongun-test-file-');
    file_put_contents($path, $bytes);

    return $path;
}

/**
 * A libvips engine behind a gate that lets everything through as the type
 * it is told, to see what libvips itself does with a file the real gate
 * would have refused.
 *
 * @param  ImageFacts::JPEG|ImageFacts::PNG|ImageFacts::WEBP  $as
 */
function vipsBehindAnOpenGate(string $as): VipsImageProcessor
{
    $gate = new class($as) extends ImageInspector
    {
        public function __construct(private readonly string $as) {}

        public function inspect(string $path): ImageFacts
        {
            return new ImageFacts($this->as, 32, 32, mayHaveAlpha: false, lossless: false);
        }
    };

    return new VipsImageProcessor($gate, app(VipsCommand::class));
}

describe('the engine that is configured', function () {
    it('is libvips unless the setting says GD', function (mixed $setting, ImageEngine $engine) {
        config(['noongun.media.engine' => $setting]);

        expect(MediaConfig::engine())->toBe($engine);
    })->with([
        'vips' => ['vips', ImageEngine::Vips],
        'gd' => ['gd', ImageEngine::Gd],
        'in capitals' => [' GD ', ImageEngine::Gd],
        'nothing' => [null, ImageEngine::Vips],
        'a name that is no engine' => ['imagick', ImageEngine::Vips],
    ]);

    it('answers for the one interface the application asks', function () {
        config(['noongun.media.engine' => 'gd']);
        expect(app(ImageProcessor::class))->toBeInstanceOf(GdImageProcessor::class);

        config(['noongun.media.engine' => 'vips']);
        expect(app(ImageProcessor::class))->toBeInstanceOf(VipsImageProcessor::class);
    });

    it('keeps the number of threads of libvips within bounds', function (mixed $setting, int $threads) {
        config(['noongun.media.vips_concurrency' => $setting]);

        expect(MediaConfig::vipsConcurrency())->toBe($threads);
    })->with([[null, 2], [0, 1], [1, 1], [4, 4], [400, 16]]);
});

describe('an engine that is not in the container', function () {
    it('makes the health check fail with a sentence that says what to do', function () {
        config(['noongun.media.engine' => 'vips', 'app.debug' => false]);
        withoutVips();

        $this->getJson('/up')->assertStatus(500)->assertJson(['status' => 'down']);

        expect(ImageEngine::Vips->available())->toBeFalse()
            ->and(ImageEngine::Vips->missing())->toContain('NOONGUN_MEDIA_ENGINE')->toContain('Rebuild the Docker image');
    });

    it('does not fail the health check when the other engine is the one in use', function () {
        config(['noongun.media.engine' => 'gd']);
        withoutVips();

        $this->getJson('/up')->assertOk();
    });

    it('refuses an upload with a sentence for the person who runs the instance', function () {
        config(['noongun.media.engine' => 'vips']);
        withoutVips();

        $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => 'This instance cannot process images: its image library is missing. Ask the person who runs it to rebuild the Docker image.']);

        expect(Media::query()->withoutGlobalScopes()->count())->toBe(0)
            ->and($this->disk->allFiles())->toBe([]);
    });
});

describe('what libvips is kept from reading', function () {
    it('refuses another format under the name of a JPEG at the gate', function (Closure $bytes, string $message) {
        $this->postJson(route('media.store'), ['file' => uploadOf($bytes(), 'photo.jpg', 'image/jpeg')])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file' => $message]);

        expect($this->disk->allFiles())->toBe([]);
    })->with([
        'an SVG' => [fn () => svgBytes(), 'not a JPEG, PNG or WebP image'],
        'a TIFF' => [fn () => "II*\0\x08\0\0\0".str_repeat("\0", 64), 'not a JPEG, PNG or WebP image'],
        'a GIF' => [fn () => gifBytes(), 'GIF images are not supported'],
    ])->with('image engines');

    it('opens a file with the loader of the format the gate named, and with no other', function (Closure $bytes, string $as) {
        useImageEngine(ImageEngine::Vips);

        $path = fileOf($bytes());

        try {
            expect(fn () => vipsBehindAnOpenGate($as)->process($path))
                ->toThrow(MediaRefused::class, 'The image could not be read. It may be damaged.');
        } finally {
            unlink($path);
        }
    })->with([
        'a TIFF as a JPEG' => [fn () => madeWithVips(flatPngBytes(srgbPatch()), 'png', 'tif'), ImageFacts::JPEG],
        'a TIFF as a PNG' => [fn () => madeWithVips(flatPngBytes(srgbPatch()), 'png', 'tif'), ImageFacts::PNG],
        'an SVG as a JPEG' => [fn () => svgBytes(), ImageFacts::JPEG],
        'an SVG as a WebP' => [fn () => svgBytes(), ImageFacts::WEBP],
        'a GIF as a PNG' => [fn () => gifBytes(), ImageFacts::PNG],
        'a PNG as a JPEG' => [fn () => pngBytes(), ImageFacts::JPEG],
    ]);

    it('still reads the three formats behind that gate', function (Closure $bytes, string $as) {
        useImageEngine(ImageEngine::Vips);

        $path = fileOf($bytes());

        try {
            $image = vipsBehindAnOpenGate($as)->process($path);

            expect($image->width)->toBe(40);

            $image->close();
        } finally {
            unlink($path);
        }
    })->with([
        'a JPEG' => [fn () => jpegBytes(), ImageFacts::JPEG],
        'a PNG' => [fn () => pngBytes(), ImageFacts::PNG],
        'a WebP' => [fn () => webpBytes(), ImageFacts::WEBP],
    ]);

    it('has the loaders libvips marks as unsafe switched off where an upload is read', function () {
        useImageEngine(ImageEngine::Vips);

        // Asked to find out by itself what a file is, libvips reads an SVG.
        // With VIPS_BLOCK_UNTRUSTED it does not: the second lock, behind
        // the loader that is named.
        $directory = sys_get_temp_dir().'/noongun-test-'.bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        file_put_contents("{$directory}/drawing.svg", svgBytes());

        try {
            app(VipsCommand::class)->run(['copy', "{$directory}/drawing.svg", "{$directory}/open.png"], $directory, 30);

            expect(getimagesize("{$directory}/open.png"))->not->toBeFalse()
                ->and(fn () => app(VipsCommand::class)->run(['copy', "{$directory}/drawing.svg", "{$directory}/blocked.png"], $directory, 30, untrusted: true))
                ->toThrow(ProcessFailedException::class)
                ->and(file_exists("{$directory}/blocked.png"))->toBeFalse();
        } finally {
            array_map(unlink(...), glob("{$directory}/*") ?: []);
            rmdir($directory);
        }
    });
});
