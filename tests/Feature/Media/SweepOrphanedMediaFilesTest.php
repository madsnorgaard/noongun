<?php

use App\Domain\Content\Console\SweepOrphanedMediaFiles;
use App\Domain\Content\Models\Media;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * An upload writes its files and then its row. What a process that was
 * killed in between leaves behind has no row, so only a look at the disk
 * itself finds it.
 */

beforeEach(function () {
    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
});

/**
 * A path as StoreMedia makes one.
 */
function storedPath(int $workspaceId, string $ending = '.jpg'): string
{
    return sprintf('w%d/2026/11/%s%s', $workspaceId, bin2hex(random_bytes(20)), $ending);
}

/**
 * Stores an image through the application and returns its row.
 */
function uploadedImage(Workspace $workspace): Media
{
    test()->actingAs(userWithRole($workspace, Role::Editor))
        ->postJson(route('media.store'), ['file' => uploadOf(jpegBytes())])
        ->assertCreated();

    return Media::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
}

it('removes files that no row points to, once they are old', function () {
    $image = storedPath($this->workspace->id);
    $thumb = str_replace('.jpg', '_thumb.jpg', $image);
    $png = storedPath($this->workspace->id, '.png');

    $this->disk->put($image, jpegBytes());
    $this->disk->put($thumb, jpegBytes(8, 4));
    $this->disk->put($png, pngBytes());

    // Young: the row of an upload may be a moment away.
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 0 file(s)')->assertSuccessful();
    $this->travel(47)->hours();
    $this->artisan('noongun:sweep-orphaned-media-files')->assertSuccessful();

    expect($this->disk->allFiles())->toHaveCount(3);

    $this->travel(2)->hours();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 3 file(s)')->assertSuccessful();

    expect($this->disk->allFiles())->toBe([]);
});

it('never removes a file that a row points to, as an image or as its small copy', function () {
    $media = uploadedImage($this->workspace);
    $unused = uploadedImage(Workspace::factory()->create());

    $this->travel(400)->days();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 0 file(s)')->assertSuccessful();

    expect($this->disk->exists($media->path))->toBeTrue()
        ->and($this->disk->exists((string) $media->thumb_path))->toBeTrue()
        ->and($this->disk->exists($unused->path))->toBeTrue()
        ->and($this->disk->allFiles())->toHaveCount(4);
});

it('takes the files of an upload that was killed before its row was written', function () {
    $kept = uploadedImage($this->workspace);

    // What the same upload leaves when the row never arrives.
    $lost = storedPath($this->workspace->id);
    $this->disk->put($lost, jpegBytes());
    $this->disk->put(str_replace('.jpg', '_thumb.jpg', $lost), jpegBytes(8, 4));

    $this->travel(3)->days();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 2 file(s)');

    expect($this->disk->allFiles())->toEqualCanonicalizing([$kept->path, $kept->thumb_path]);
});

it('takes the files a deleted workspace left behind', function () {
    $media = uploadedImage($this->workspace);

    DB::table('workspaces')->where('id', $this->workspace->id)->delete();

    expect($this->disk->exists($media->path))->toBeTrue();

    $this->travel(3)->days();
    $this->artisan('noongun:sweep-orphaned-media-files');

    expect($this->disk->allFiles())->toBe([]);
});

it('touches nothing that does not look like a file it stored', function () {
    $others = [
        'notes.txt',
        'w1/readme.md',
        'backups/w1/2026/11/'.str_repeat('a', 40).'.jpg',
        'w1/2026/11/'.str_repeat('a', 39).'.jpg',
        'w1/2026/11/'.str_repeat('a', 40).'.gif',
        'w1/2026/11/'.str_repeat('A', 40).'.jpg',
        'w1/2026/11/'.str_repeat('a', 40).'.jpg.bak',
        'w1/test/'.str_repeat('a', 40).'.jpg',
    ];

    foreach ($others as $path) {
        $this->disk->put($path, 'not ours');
    }

    $this->travel(400)->days();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 0 file(s)');

    expect($this->disk->allFiles())->toHaveCount(count($others))
        ->and(preg_match(SweepOrphanedMediaFiles::STORED_PATH, storedPath(12)))->toBe(1)
        ->and(preg_match(SweepOrphanedMediaFiles::STORED_PATH, storedPath(12, '_thumb.jpg')))->toBe(1)
        ->and(preg_match(SweepOrphanedMediaFiles::STORED_PATH, storedPath(12, '.png')))->toBe(1);
});

it('names a file by the path a real upload gets', function () {
    $media = uploadedImage($this->workspace);

    expect(preg_match(SweepOrphanedMediaFiles::STORED_PATH, $media->path))->toBe(1)
        ->and(preg_match(SweepOrphanedMediaFiles::STORED_PATH, (string) $media->thumb_path))->toBe(1);
});

it('only says what it would remove when asked to', function () {
    $this->disk->put(storedPath($this->workspace->id), jpegBytes());

    $this->travel(3)->days();
    $this->artisan('noongun:sweep-orphaned-media-files', ['--dry-run' => true])->expectsOutputToContain('Would remove 1 file(s)')->assertSuccessful();

    expect($this->disk->allFiles())->toHaveCount(1);
});

it('honours the age that is configured, and never less than an hour', function () {
    config(['noongun.media.orphan_file_hours' => 0]);

    $this->disk->put(storedPath($this->workspace->id), jpegBytes());

    $this->travel(59)->minutes();
    $this->artisan('noongun:sweep-orphaned-media-files');

    expect($this->disk->allFiles())->toHaveCount(1);

    $this->travel(2)->minutes();
    $this->artisan('noongun:sweep-orphaned-media-files');

    expect($this->disk->allFiles())->toBe([]);
});

it('works through the disk, so a bucket is swept the same way, and sweeps every disk that holds images', function () {
    $old = uploadedImage($this->workspace);
    $orphanOnOld = storedPath($this->workspace->id);
    $this->disk->put($orphanOnOld, jpegBytes());

    $objects = remoteMediaDisk();
    $new = uploadedImage($this->workspace);
    $orphanOnNew = storedPath($this->workspace->id, '.png');
    Storage::disk('objects')->put($orphanOnNew, pngBytes());

    $this->travel(3)->days();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 2 file(s)')->assertSuccessful();

    expect($new->disk)->toBe('objects')
        ->and($objects->allFiles())->toEqualCanonicalizing([$new->path, $new->thumb_path])
        ->and($this->disk->allFiles())->toEqualCanonicalizing([$old->path, $old->thumb_path]);
});

it('does not take a file for an orphan because a row on another disk has the same path', function () {
    $media = uploadedImage($this->workspace);

    remoteMediaDisk();
    Storage::disk('objects')->put($media->path, jpegBytes());

    $this->travel(3)->days();
    $this->artisan('noongun:sweep-orphaned-media-files')->expectsOutputToContain('Removed 1 file(s)');

    expect(Storage::disk('objects')->allFiles())->toBe([])
        ->and($this->disk->exists($media->path))->toBeTrue();
});

it('runs once a week', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'noongun:sweep-orphaned-media-files'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('30 3 * * 1');
});
