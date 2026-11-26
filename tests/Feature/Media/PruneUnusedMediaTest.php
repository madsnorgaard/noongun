<?php

use App\Domain\Content\Actions\DeletePost;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Post;
use App\Domain\Content\PostState;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 12:00:00');

    $this->disk = fakeMediaDisk();
    $this->workspace = Workspace::factory()->create();
});

function mediaExists(Media $media): bool
{
    return DB::table('media')->where('id', $media->id)->exists();
}

it('removes an image nobody attached, a day after it was first seen unused', function () {
    $media = storedImage($this->workspace);

    $this->artisan('noongun:prune-unused-media')->assertSuccessful();

    expect(mediaExists($media))->toBeTrue()
        ->and(DB::table('media')->where('id', $media->id)->value('unreferenced_since'))->not->toBeNull();

    $this->travel(23)->hours();
    $this->artisan('noongun:prune-unused-media')->assertSuccessful();

    expect(mediaExists($media))->toBeTrue()
        ->and($this->disk->exists($media->path))->toBeTrue();

    $this->travel(61)->minutes();
    $this->artisan('noongun:prune-unused-media')->expectsOutputToContain('Removed 1 unused image(s).')->assertSuccessful();

    expect(mediaExists($media))->toBeFalse()
        ->and($this->disk->allFiles())->toBe([]);
});

it('never removes an image that is on a post, whatever the state of the post and however old', function (PostState $state) {
    $media = storedImage($this->workspace);
    $post = postInState($this->workspace, $state);

    attachImage(variantsOf($post)[0], $media);
    DB::table('media')->update(['unreferenced_since' => now()->subYear(), 'created_at' => now()->subYears(2)]);

    $this->travel(2)->days();
    $this->artisan('noongun:prune-unused-media')->assertSuccessful();
    $this->travel(2)->days();
    $this->artisan('noongun:prune-unused-media')->assertSuccessful();

    expect(mediaExists($media))->toBeTrue()
        ->and($this->disk->exists($media->path))->toBeTrue()
        ->and($this->disk->exists((string) $media->thumb_path))->toBeTrue()
        // The note of an earlier time without a post is forgotten.
        ->and(DB::table('media')->where('id', $media->id)->value('unreferenced_since'))->toBeNull();
})->with([PostState::Draft, PostState::Approved, PostState::Scheduled, PostState::Published, PostState::Failed, PostState::Rejected]);

it('gives an image a full day again after it was taken off a post', function () {
    $media = storedImage($this->workspace);
    $post = postInState($this->workspace, PostState::Draft);
    $attachment = attachImage(variantsOf($post)[0], $media);

    $this->travel(30)->days();
    $this->artisan('noongun:prune-unused-media');

    DB::table('post_variant_media')->where('id', $attachment->id)->delete();

    $this->artisan('noongun:prune-unused-media');
    $this->travel(23)->hours();
    $this->artisan('noongun:prune-unused-media');

    expect(mediaExists($media))->toBeTrue();

    $this->travel(2)->hours();
    $this->artisan('noongun:prune-unused-media');

    expect(mediaExists($media))->toBeFalse();
});

it('keeps an image that one of two texts still uses', function () {
    $media = storedImage($this->workspace);
    $post = postInState($this->workspace, PostState::Draft, 2);
    [$first, $second] = variantsOf($post);

    $gone = attachImage($first, $media);
    attachImage($second, $media);

    DB::table('post_variant_media')->where('id', $gone->id)->delete();

    $this->artisan('noongun:prune-unused-media');
    $this->travel(3)->days();
    $this->artisan('noongun:prune-unused-media');

    expect(mediaExists($media))->toBeTrue();
});

it('removes the images of a deleted draft, and only those', function () {
    $owner = userWithRole($this->workspace, Role::Owner);
    $onDraft = storedImage($this->workspace);
    $onBoth = storedImage($this->workspace);
    $draft = postInState($this->workspace, PostState::Draft);
    $published = postInState($this->workspace, PostState::Draft);

    attachImage(variantsOf($draft)[0], $onDraft);
    attachImage(variantsOf($draft)[0], $onBoth);
    attachImage(variantsOf($published)[0], $onBoth);
    DB::table('posts')->where('id', $published->id)->update(['state' => 'published', 'approved_at' => now(), 'approved_by' => $owner->id, 'scheduled_at' => now()]);

    inWorkspace($this->workspace, fn () => app(DeletePost::class)->handle($owner, Post::query()->findOrFail($draft->id)));

    // Deleting the post removes no file by itself.
    expect($this->disk->allFiles())->toHaveCount(4);

    $this->artisan('noongun:prune-unused-media');
    $this->travel(25)->hours();
    $this->artisan('noongun:prune-unused-media');

    expect(mediaExists($onDraft))->toBeFalse()
        ->and(mediaExists($onBoth))->toBeTrue()
        ->and($this->disk->exists($onBoth->path))->toBeTrue()
        ->and($this->disk->allFiles())->toHaveCount(2);
});

it('cleans every workspace, each by itself', function () {
    $other = Workspace::factory()->create();
    $unused = storedImage($other);
    $used = storedImage($other);

    attachImage(variantsOf(postInState($other, PostState::Draft))[0], $used);

    $mine = storedImage($this->workspace);

    $this->artisan('noongun:prune-unused-media');
    $this->travel(25)->hours();
    $this->artisan('noongun:prune-unused-media')->expectsOutputToContain('Removed 2 unused image(s).');

    expect(mediaExists($unused))->toBeFalse()
        ->and(mediaExists($mine))->toBeFalse()
        ->and(mediaExists($used))->toBeTrue();
});

it('removes the row even when the file is already gone, and works on any disk', function () {
    remoteMediaDisk();

    $media = storedImage($this->workspace);
    $missing = storedImage($this->workspace);

    Storage::disk('objects')->delete([$missing->path, (string) $missing->thumb_path]);

    $this->artisan('noongun:prune-unused-media');
    $this->travel(25)->hours();
    $this->artisan('noongun:prune-unused-media')->assertSuccessful();

    expect(mediaExists($media))->toBeFalse()
        ->and(mediaExists($missing))->toBeFalse()
        ->and(Storage::disk('objects')->allFiles())->toBe([]);
});

it('honours the grace period that is configured', function () {
    config(['noongun.media.unused_grace_hours' => 2]);

    $media = storedImage($this->workspace);

    $this->artisan('noongun:prune-unused-media');
    $this->travel(121)->minutes();
    $this->artisan('noongun:prune-unused-media');

    expect(mediaExists($media))->toBeFalse();
});

it('runs every hour', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn ($event) => str_contains((string) $event->command, 'noongun:prune-unused-media'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('0 * * * *');
});
