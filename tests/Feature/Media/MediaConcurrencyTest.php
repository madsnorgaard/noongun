<?php

use App\Domain\Content\Exceptions\MediaBusy;
use App\Domain\Content\Media\ImageProcessor;
use App\Domain\Content\Media\MediaConfig;
use App\Domain\Content\Media\ProcessedImage;
use App\Domain\Content\Media\ProcessingSlots;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\Cache;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * Decoding an image costs hundreds of megabytes for a moment, whatever the
 * size of the file. Only a few may be decoded at once: a small number on
 * the whole instance, and one for each user.
 */

beforeEach(function () {
    config([
        'noongun.media.concurrent_uploads' => 2,
        'noongun.media.slot_wait_seconds' => 0,
        'noongun.media.slot_lease_seconds' => 120,
    ]);

    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
    $this->editor = userWithRole($this->workspace, Role::Editor);
});

/**
 * Takes the places other requests would hold while they decode an image.
 */
function occupyPlaces(int $count): void
{
    for ($place = 0; $place < $count; $place++) {
        expect(Cache::lock("noongun:media:place:{$place}", 120)->get())->toBeTrue();
    }
}

it('answers 429 with a sentence and a time to wait when every place is taken', function () {
    occupyPlaces(2);

    $response = $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())]);

    $response->assertTooManyRequests()
        ->assertHeader('Retry-After', '3')
        ->assertExactJson([
            'message' => 'The server is processing as many images as it can at once. This one was not looked at. Try it again in a moment.',
            'reason' => 'busy',
            'errors' => ['file' => ['The server is processing as many images as it can at once. This one was not looked at. Try it again in a moment.']],
        ]);

    expect(Media::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and($this->disk->allFiles())->toBe([]);
});

it('does not decode anything for a request that is turned away', function () {
    occupyPlaces(2);

    $processor = Mockery::mock(ImageProcessor::class);
    $processor->shouldNotReceive('process');
    app()->instance(ImageProcessor::class, $processor);

    // A few kilobytes that would ask for half a gigabyte.
    $this->actingAs($this->editor)
        ->postJson(route('media.store'), ['file' => uploadOf(photoLikeBytes('webp', 64, 64))])
        ->assertTooManyRequests();
});

it('takes an upload while a place is free', function () {
    occupyPlaces(1);

    $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();
});

it('honours the number of places that is configured', function () {
    config(['noongun.media.concurrent_uploads' => 1]);
    occupyPlaces(1);

    $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertTooManyRequests();

    config(['noongun.media.concurrent_uploads' => 0]);

    // Never fewer than one, or nothing could ever be uploaded.
    expect(MediaConfig::concurrentUploads())->toBe(1);
});

it('lets one user decode one image at a time, and does not hold others back for it', function () {
    expect(Cache::lock("noongun:media:user:{$this->editor->id}", 120)->get())->toBeTrue();

    $this->actingAs($this->editor)
        ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After', '3')
        ->assertJsonPath('errors.file.0', 'Another image of yours is still being processed. This one was not looked at. Try it again in a moment.');

    $this->actingAs(userWithRole($this->workspace, Role::Owner))
        ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
        ->assertCreated();
});

it('gives its places back after an upload, however it ended', function (Closure $bytes, int $status) {
    config(['noongun.media.concurrent_uploads' => 1]);

    $this->actingAs($this->editor);

    $this->postJson(route('media.store'), ['file' => uploadOf($bytes())])->assertStatus($status);
    $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes(44, 20))])->assertCreated();

    expect(Cache::lock('noongun:media:place:0', 1)->get())->toBeTrue()
        ->and(Cache::lock("noongun:media:user:{$this->editor->id}", 1)->get())->toBeTrue();
})->with([
    'stored' => [fn () => jpegBytes(), 201],
    'refused' => [fn () => scriptBytes(), 422],
    'refused as too large in pixels' => [fn () => pixelBombBytes(), 422],
]);

it('gives its places back when the work throws', function () {
    config(['noongun.media.concurrent_uploads' => 1]);

    $slots = app(ProcessingSlots::class);

    expect(fn () => $slots->run(7, fn () => throw new RuntimeException('The library gave up.')))->toThrow(RuntimeException::class)
        ->and($slots->run(7, fn (): string => 'done'))->toBe('done');
});

it('gives back the place of the user when the instance is full', function () {
    occupyPlaces(2);

    expect(fn () => app(ProcessingSlots::class)->run($this->editor->id, fn () => null))->toThrow(MediaBusy::class)
        ->and(Cache::lock("noongun:media:user:{$this->editor->id}", 1)->get())->toBeTrue();
});

it('lets a place run out by itself when the process that held it died', function () {
    $this->travelTo('2026-11-27 09:00:00');

    // What a request leaves that was killed for memory or time: its places
    // taken, and nobody to give them back.
    occupyPlaces(2);
    Cache::lock("noongun:media:user:{$this->editor->id}", 120)->get();

    $this->actingAs($this->editor);

    $this->travel(119)->seconds();
    $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertTooManyRequests();

    $this->travel(2)->seconds();
    $this->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();
});

it('waits a little for its turn before it gives up', function () {
    config(['noongun.media.slot_wait_seconds' => 1]);
    occupyPlaces(2);

    $started = microtime(true);

    $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertTooManyRequests();

    expect(microtime(true) - $started)->toBeGreaterThan(0.9)->toBeLessThan(3.0);
});

it('holds a place only while the image is decoded, not while the files are written', function () {
    config(['noongun.media.concurrent_uploads' => 1]);

    $free = null;
    $inner = app(ImageProcessor::class);

    app()->instance(ImageProcessor::class, new class($inner) implements ImageProcessor
    {
        public bool $heldDuringDecode = false;

        public function __construct(private readonly ImageProcessor $inner) {}

        public function process(string $path): ProcessedImage
        {
            $this->heldDuringDecode = ! Cache::lock('noongun:media:place:0', 1)->get();

            return $this->inner->process($path);
        }
    });

    Media::created(function () use (&$free): void {
        $lock = Cache::lock('noongun:media:place:0', 1);
        $free = $lock->get();
        $lock->release();
    });

    $this->actingAs($this->editor)->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])->assertCreated();

    expect(app(ImageProcessor::class)->heldDuringDecode)->toBeTrue()
        ->and($free)->toBeTrue();
});
