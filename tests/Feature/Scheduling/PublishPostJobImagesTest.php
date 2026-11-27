<?php

use App\Domain\Channels\ChannelManager;
use App\Domain\Channels\Contracts\ChannelAdapter;
use App\Domain\Channels\Contracts\ChannelCapabilities;
use App\Domain\Channels\Contracts\PublishResult;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fakes\FakeChannelAdapter;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/media.php';

/*
 * The protocol for a variant with images, as PublishPostJob runs it against
 * an adapter: upload each image once, remember each id, create the post
 * last. Every test here is about what happens between those steps.
 */

beforeEach(function () {
    $this->travelTo('2026-11-24 10:00:00');

    config([
        'noongun.publishing.queue' => 'publishing',
        'noongun.publishing.max_attempts' => 3,
        'noongun.publishing.backoff' => [60, 300],
        'noongun.publishing.timeout' => 30,
        'noongun.publishing.media_timeout' => 60,
        'noongun.publishing.media_uploads_per_job' => 2,
    ]);

    fakeMediaDisk();
    $this->fake = fakeChannelAdapter()->acceptingImages(max: 4);
    $this->workspace = Workspace::factory()->create();
});

/**
 * A post the scheduler has claimed, whose variants each carry the given
 * images, and the job it would have queued.
 *
 * @param  list<list<Media>>  $imagesPerVariant
 * @return array{Post, PublishPostJob, list<PostVariant>}
 */
function claimedPostWithImages(Workspace $workspace, array $imagesPerVariant): array
{
    $post = postInState($workspace, PostState::Scheduled, count($imagesPerVariant), now()->subMinute());
    $variants = variantsOf($post);

    foreach ($imagesPerVariant as $index => $images) {
        foreach ($images as $number => $media) {
            attachImage($variants[$index], $media, 'Image '.($number + 1).'.');
        }
    }

    $job = inWorkspace($workspace, function () use ($post): PublishPostJob {
        expect($post->claimForPublishing())->toBeTrue();

        return PublishPostJob::forClaimed($post);
    });

    return [$post, $job, $variants];
}

/**
 * What is remembered of each image of a variant: [media id, external id].
 *
 * @return list<array{int, string|null}>
 */
function uploadsOf(PostVariant $variant): array
{
    return DB::table('post_variant_media')
        ->where('post_variant_id', $variant->id)
        ->orderBy('position')
        ->get()
        ->map(fn (object $row): array => [(int) $row->media_id, $row->external_id])
        ->all();
}

it('sends each image once, in order, and then creates the post with what the platform called them', function () {
    $harbour = storedImage($this->workspace, jpegBytes(60, 40));
    $gull = storedImage($this->workspace, pngBytes());

    [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$harbour, $gull]]);

    runPublishJob($job);

    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and($this->fake->calls)->toBe([
            ['method' => 'uploadMedia', 'variant_id' => $variant->id, 'channel_id' => $variant->channel_id],
            ['method' => 'uploadMedia', 'variant_id' => $variant->id, 'channel_id' => $variant->channel_id],
            ['method' => 'publish', 'variant_id' => $variant->id, 'channel_id' => $variant->channel_id],
        ])
        ->and($this->fake->uploads)->toBe([
            [
                'variant_id' => $variant->id, 'media_id' => $harbour->id, 'position' => 1, 'mime' => 'image/jpeg', 'alt_text' => 'Image 1.',
                'bytes' => $harbour->byte_size, 'sha256' => $harbour->content_hash, 'external_id' => "facebook_image_{$harbour->id}_1",
            ],
            [
                'variant_id' => $variant->id, 'media_id' => $gull->id, 'position' => 2, 'mime' => 'image/png', 'alt_text' => 'Image 2.',
                'bytes' => $gull->byte_size, 'sha256' => $gull->content_hash, 'external_id' => "facebook_image_{$gull->id}_2",
            ],
        ])
        ->and($this->fake->lastPublished($variant)['images'] ?? null)->toBe([
            ['media_id' => $harbour->id, 'external_id' => "facebook_image_{$harbour->id}_1", 'alt_text' => 'Image 1.'],
            ['media_id' => $gull->id, 'external_id' => "facebook_image_{$gull->id}_2", 'alt_text' => 'Image 2.'],
        ])
        ->and(uploadsOf($variant))->toBe([[$harbour->id, "facebook_image_{$harbour->id}_1"], [$gull->id, "facebook_image_{$gull->id}_2"]])
        ->and(variantsOf($post)[0]->isPublished())->toBeTrue()
        ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse();
});

it('publishes a variant without images exactly as before', function () {
    [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[]]);

    runPublishJob($job);

    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and($this->fake->uploadCount())->toBe(0)
        ->and($this->fake->lastPublished($variant)['images'] ?? null)->toBe([]);
});

it('sends the same image once for each variant it is on', function () {
    $shared = storedImage($this->workspace);

    [$post, $job, [$first, $second]] = claimedPostWithImages($this->workspace, [[$shared], [$shared]]);

    runPublishJob($job);

    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and($this->fake->uploadCount($first))->toBe(1)
        ->and($this->fake->uploadCount($second))->toBe(1)
        ->and(uploadsOf($first)[0][1])->not->toBe(uploadsOf($second)[0][1]);
});

it('gives the job time for the uploads it may make', function () {
    $images = fn (int $count): array => array_map(fn () => storedImage($this->workspace), range(1, $count));

    [, $none] = claimedPostWithImages($this->workspace, [[]]);
    [, $one] = claimedPostWithImages($this->workspace, [$images(1)]);
    [, $many] = claimedPostWithImages($this->workspace, [$images(3), $images(2)]);

    // 30 for each variant, 60 for each image up to two a run, 15 on top.
    expect($none->timeout)->toBe(45)
        ->and($one->timeout)->toBe(105)
        ->and($many->timeout)->toBe(195);
});

it('reads the file as a stream, so that it works on object storage', function () {
    remoteMediaDisk();

    $image = storedImage($this->workspace);

    [$post, $job] = claimedPostWithImages($this->workspace, [[$image]]);

    runPublishJob($job);

    expect(freshPost($post)->state)->toBe(PostState::Published)
        ->and($this->fake->uploads[0]['sha256'])->toBe($image->content_hash);
});

it('sends the link of a post with images as the last line of its text', function () {
    [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

    DB::table('post_variants')->where('id', $variant->id)->update(['body' => 'Fresh bread from eight.', 'link' => 'https://example.com/bread']);

    runPublishJob($job);

    expect($this->fake->lastPublished($variant)['text'] ?? null)->toBe("Fresh bread from eight.\n\nhttps://example.com/bread");
});

describe('an upload that fails', function () {
    it('keeps what was sent, marks nothing as in doubt, and sends only the rest on the next attempt', function () {
        Queue::fake();

        $first = storedImage($this->workspace);
        $second = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$first, $second]]);

        // Fails on the second upload only.
        $failing = new class extends FakeChannelAdapter
        {
            public int $seen = 0;

            public function uploadMedia(PostVariant $variant, MediaAttachment $attachment): string
            {
                if (++$this->seen === 2) {
                    $this->calls[] = ['method' => 'uploadMedia', 'variant_id' => $variant->id, 'channel_id' => $variant->channel_id];

                    throw PublishFailed::retryable('facebook', 'The platform is busy.', 120);
                }

                return parent::uploadMedia($variant, $attachment);
            }
        };
        $failing->acceptingImages(max: 4);
        app(ChannelManager::class)->extend('facebook', fn () => $failing);

        runPublishJob($job);

        $stored = variantsOf($post)[0];

        expect(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and($stored->error)->toBe('The platform is busy.')
            // Nothing public was attempted: the outcome is not in doubt.
            ->and($stored->hasUnknownOutcome())->toBeFalse()
            ->and($failing->publishCount())->toBe(0)
            ->and(uploadsOf($variant))->toBe([[$first->id, "facebook_image_{$first->id}_1"], [$second->id, null]]);

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        expect($successor->attempt)->toBe(2)
            ->and($successor->step)->toBe(2)
            ->and($successor->delay)->toBe(120)
            ->and($successor->timeout)->toBe(165);

        runPublishJob($successor);

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($failing->uploadCount())->toBe(3)
            ->and(array_column($failing->uploads, 'media_id'))->toBe([$first->id, $second->id])
            ->and($failing->publishCount())->toBe(1)
            ->and(array_column($failing->lastPublished($variant)['images'] ?? [], 'external_id'))->toBe(["facebook_image_{$first->id}_1", "facebook_image_{$second->id}_2"])
            ->and(variantsOf($post)[0]->error)->toBeNull();
    });

    it('fails the post without a publish call when the platform refuses an image, and "send again" starts from what is there', function () {
        $first = storedImage($this->workspace);
        $second = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$first, $second]]);

        $this->fake->failNextUploads(PublishFailed::permanent('facebook', 'The platform does not take this image.'));

        runPublishJob($job);

        $stored = variantsOf($post)[0];

        expect(freshPost($post)->state)->toBe(PostState::Failed)
            ->and($stored->error)->toBe('The platform does not take this image.')
            ->and($stored->hasUnknownOutcome())->toBeFalse()
            ->and($this->fake->publishCount())->toBe(0)
            ->and(uploadsOf($variant))->toBe([[$first->id, null], [$second->id, null]]);

        $owner = userWithRole($this->workspace, Role::Owner);

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($owner, Post::query()->findOrFail($post->id)));
        $this->artisan('noongun:dispatch-due-posts');

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($this->fake->publishCount())->toBe(1)
            ->and($this->fake->uploadCount())->toBe(3);
    });

    it('treats a failed upload as certain even when the adapter calls its outcome unknown: an upload makes no post', function () {
        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

        $this->fake->failNextUploads(PublishFailed::permanent('facebook', 'The connection broke.', ['outcome_unknown' => true]));

        runPublishJob($job);

        expect(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and(variantsOf($post)[0]->error)->toBe('The connection broke.')
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('stores no detail of an unexpected exception and marks nothing as in doubt', function () {
        [$post, $job] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

        $broken = new class extends FakeChannelAdapter
        {
            public function uploadMedia(PostVariant $variant, MediaAttachment $attachment): string
            {
                throw new RuntimeException('POST https://graph.test/photos?access_token=SECRET failed');
            }
        };
        $broken->acceptingImages();
        app(ChannelManager::class)->extend('facebook', fn () => $broken);

        runPublishJob($job);

        $stored = variantsOf($post)[0];

        expect($stored->error)->toBe(PublishPostJob::UPLOAD_FAILED)
            ->and($stored->hasUnknownOutcome())->toBeFalse()
            ->and($broken->publishCount())->toBe(0)
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('leaves the mark of an earlier attempt that is in doubt where it is', function () {
        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

        DB::table('post_variants')->where('id', $variant->id)->update(['outcome_unknown_since' => now()->subHour()]);
        $this->fake->failNextUploads(PublishFailed::permanent('facebook', 'The platform does not take this image.'));

        runPublishJob($job);

        $stored = variantsOf($post)[0];

        expect($stored->hasUnknownOutcome())->toBeTrue()
            ->and($stored->error)->toBe('The platform does not take this image. '.PublishPostJob::EARLIER_ATTEMPT);
    });

    it('does not stop the other variants', function () {
        $image = storedImage($this->workspace);

        [$post, $job, [$with, $without]] = claimedPostWithImages($this->workspace, [[$image], []]);

        $this->fake->failNextUploads(PublishFailed::permanent('facebook', 'The platform does not take this image.'));

        runPublishJob($job);

        [$first, $second] = variantsOf($post);

        expect($first->isPublished())->toBeFalse()
            ->and($second->isPublished())->toBeTrue()
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });
});

describe('a publish that is tried again', function () {
    it('does not send an image again that the platform already holds', function () {
        Queue::fake();

        $image = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$image]]);

        // The images go up, then creating the post is turned away twice.
        $this->fake->failRetryable(times: 2);

        runPublishJob($job);
        runPublishJob(Queue::pushed(PublishPostJob::class)->last());
        runPublishJob(Queue::pushed(PublishPostJob::class)->last());

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($this->fake->uploadCount())->toBe(1)
            ->and($this->fake->publishCount())->toBe(3)
            ->and(array_column($this->fake->lastPublished($variant)['images'] ?? [], 'external_id'))->toBe(["facebook_image_{$image->id}_1"]);
    });

    it('carries on after a run that died between the uploads and the post', function () {
        $first = storedImage($this->workspace);
        $second = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$first, $second]]);

        // What a run leaves that sent one image and then lost its worker:
        // one id remembered, no mark on the variant, the post failed by the
        // recovery.
        DB::table('post_variant_media')->where('post_variant_id', $variant->id)->where('media_id', $first->id)
            ->update(['external_id' => 'photo_from_the_dead_run', 'uploaded_at' => now()]);
        DB::table('posts')->where('id', $post->id)->update(['state' => 'failed']);

        $owner = userWithRole($this->workspace, Role::Owner);

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($owner, Post::query()->findOrFail($post->id)));
        $this->artisan('noongun:dispatch-due-posts');

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and(array_column($this->fake->uploads, 'media_id'))->toBe([$second->id])
            ->and(array_column($this->fake->lastPublished($variant)['images'] ?? [], 'external_id'))->toBe(['photo_from_the_dead_run', "facebook_image_{$second->id}_1"])
            ->and($this->fake->publishCount())->toBe(1);
    });

    it('keeps the uploads and the mark when creating the post ends in doubt, and sends nothing again by itself', function () {
        Queue::fake();

        $image = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$image]]);

        $this->fake->failNextPublishes(PublishFailed::permanent('facebook', 'No answer came back.', ['outcome_unknown' => true]));

        runPublishJob($job);

        $stored = variantsOf($post)[0];

        expect($stored->hasUnknownOutcome())->toBeTrue()
            ->and(freshPost($post)->state)->toBe(PostState::Failed)
            ->and(uploadsOf($variant))->toBe([[$image->id, "facebook_image_{$image->id}_1"]])
            ->and($this->fake->publishCount())->toBe(1);

        Queue::assertNothingPushed();
    });

    it('sends an image again when the platform no longer keeps the earlier upload', function () {
        $this->fake->acceptingImages(max: 4, uploadLifetime: 3600);

        $fresh = storedImage($this->workspace);
        $stale = storedImage($this->workspace);

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [[$fresh, $stale]]);

        DB::table('post_variant_media')->where('media_id', $fresh->id)->update(['external_id' => 'still_good', 'uploaded_at' => now()->subMinutes(59)]);
        DB::table('post_variant_media')->where('media_id', $stale->id)->update(['external_id' => 'gone_by_now', 'uploaded_at' => now()->subMinutes(61)]);

        runPublishJob($job);

        expect(array_column($this->fake->uploads, 'media_id'))->toBe([$stale->id])
            ->and(array_column($this->fake->lastPublished($variant)['images'] ?? [], 'external_id'))->toBe(['still_good', "facebook_image_{$stale->id}_1"]);
    });

    it('sends the images again after the description of one changed in draft', function () {
        $image = storedImage($this->workspace);
        $post = postInState($this->workspace, PostState::Draft);
        $variant = variantsOf($post)[0];
        $attachment = attachImage($variant, $image, 'Before.');

        DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => 'with_old_text', 'uploaded_at' => now()]);

        inWorkspace($this->workspace, fn () => MediaAttachment::query()->findOrFail($attachment->id)->update(['alt_text' => 'After.']));

        expect(uploadsOf($variant))->toBe([[$image->id, null]]);
    });
});

describe('more images than one run may send', function () {
    it('hands the variant to the next run at once, without counting an attempt, and creates the post when all are there', function () {
        Queue::fake();

        $images = array_map(fn () => storedImage($this->workspace), range(1, 3));

        [$post, $job, [$variant]] = claimedPostWithImages($this->workspace, [$images]);

        runPublishJob($job);

        expect($this->fake->uploadCount())->toBe(2)
            ->and($this->fake->publishCount())->toBe(0)
            ->and(freshPost($post)->state)->toBe(PostState::Publishing)
            ->and(variantsOf($post)[0]->error)->toBeNull()
            ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse();

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        expect($successor->attempt)->toBe(1)
            ->and($successor->step)->toBe(2)
            ->and($successor->delay)->toBe(0)
            ->and($successor->givenUp)->toBe([]);

        runPublishJob($successor);

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($this->fake->uploadCount())->toBe(3)
            ->and($this->fake->publishCount())->toBe(1)
            ->and(Queue::pushed(PublishPostJob::class))->toHaveCount(1)
            ->and(array_column($this->fake->lastPublished($variant)['images'] ?? [], 'media_id'))->toBe(array_map(fn (Media $media) => $media->id, $images));
    });

    it('shares the uploads of one run between the variants and still finishes', function () {
        $images = fn (): array => array_map(fn () => storedImage($this->workspace), range(1, 2));

        [$post, $job] = claimedPostWithImages($this->workspace, [$images(), $images()]);

        // The sync queue runs each successor as it is dispatched.
        runPublishJob($job);

        expect(freshPost($post)->state)->toBe(PostState::Published)
            ->and($this->fake->uploadCount())->toBe(4)
            ->and($this->fake->publishCount())->toBe(2);
    });
});

describe('images that cannot go out', function () {
    it('calls no platform for a variant with images on a platform that takes none', function () {
        [$post, $job] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

        $this->fake->withCapabilities(new ChannelCapabilities(maxBodyLength: 5000));

        runPublishJob($job);

        expect($this->fake->calls)->toBe([])
            ->and(variantsOf($post)[0]->error)->toContain('Images cannot be published to Facebook from this instance.')
            ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('calls no platform when the adapter says it takes images and cannot upload them', function () {
        [$post, $job] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

        $adapter = Mockery::mock(ChannelAdapter::class);
        $adapter->shouldReceive('platform')->andReturn('facebook');
        $adapter->shouldReceive('capabilities')->andReturn(new ChannelCapabilities(maxBodyLength: 5000, mediaTypes: ['image/jpeg'], maxMediaItems: 4));
        $adapter->shouldNotReceive('publish');
        app(ChannelManager::class)->extend('facebook', fn () => $adapter);

        runPublishJob($job);

        expect(variantsOf($post)[0]->error)->toBe('Publishing to Facebook is not available on this instance.')
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('sends nothing when a file is missing from the disk', function () {
        $present = storedImage($this->workspace);
        $missing = storedImage($this->workspace);

        [$post, $job] = claimedPostWithImages($this->workspace, [[$present, $missing]]);

        Storage::disk('media')->delete($missing->path);

        runPublishJob($job);

        expect(variantsOf($post)[0]->error)->toBe(sprintf(PublishPostJob::FILE_MISSING, 2))
            ->and(variantsOf($post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and($this->fake->publishCount())->toBe(0)
            ->and(freshPost($post)->state)->toBe(PostState::Failed);
    });

    it('checks the limits of the platform again before anything is sent', function () {
        [$post, $job] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace), storedImage($this->workspace)]]);

        $this->fake->acceptingImages(max: 1);

        runPublishJob($job);

        expect($this->fake->calls)->toBe([])
            ->and(variantsOf($post)[0]->error)->toContain('has 2 images. Noon Gun sends 1 at most to Facebook on one post.');
    });
});

it('records the result of the publish as for a post without images', function () {
    [$post, $job] = claimedPostWithImages($this->workspace, [[storedImage($this->workspace)]]);

    $this->fake->publishReturns(new PublishResult('post_77', 'https://platform.test/posts/post_77', now()));

    runPublishJob($job);

    expect(variantsOf($post)[0]->external_id)->toBe('post_77')
        ->and(variantsOf($post)[0]->permalink)->toBe('https://platform.test/posts/post_77');
});
