<?php

/*
 * A post with images, from the job to the faked Graph API through the real
 * Facebook adapter: photos go up unpublished, one call to the Page feed
 * names them, and nothing that happens in between makes a second post.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Actions\RetryPost;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

require_once __DIR__.'/../../Support/publishing.php';
require_once __DIR__.'/../../Support/facebook.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:15:00');
    configureFacebook();
    fakeMediaDisk();

    config([
        'noongun.publishing.max_attempts' => 3,
        'noongun.publishing.backoff' => [60, 300],
        'noongun.publishing.media_uploads_per_job' => 2,
    ]);

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'display_name' => 'Table Bay Coffee',
        'access_token' => 'test-page-token-table-bay-coffee',
    ]));

    $this->post = inWorkspace($this->workspace, function (): Post {
        $post = Post::factory()->create();
        PostVariant::factory()->create(['post_id' => $post->id, 'channel_id' => $this->channel->id, 'body' => 'Fresh beans arrive on Thursday.']);

        return $post;
    });

    /**
     * Puts the images on the draft, then approves, schedules and claims
     * the post the way a person and the scheduler do.
     *
     * @param  list<Media>  $images
     */
    $this->claimWith = function (array $images): PublishPostJob {
        foreach ($images as $number => $image) {
            attachImage(variantsOf($this->post)[0], $image, 'Image '.($number + 1).'.');
        }

        $owner = userWithRole($this->workspace, Role::Owner);

        return inWorkspace($this->workspace, function () use ($owner): PublishPostJob {
            $this->post->approve($owner)->schedule(now()->subMinute());
            expect($this->post->claimForPublishing())->toBeTrue();

            return PublishPostJob::forClaimed($this->post);
        });
    };

    $this->sendAgain = function (): void {
        $owner = userWithRole($this->workspace, Role::Owner);

        inWorkspace($this->workspace, fn () => app(RetryPost::class)->handle($owner, Post::query()->findOrFail($this->post->id)));
        $this->artisan('noongun:dispatch-due-posts');
    };

    // What is remembered of each image: [external id, uploaded or not].
    $this->remembered = fn (): array => DB::table('post_variant_media')
        ->where('post_variant_id', variantsOf($this->post)[0]->id)
        ->orderBy('position')
        ->pluck('external_id')
        ->all();

    // Every call to the Page feed, as the form Facebook reads.
    $this->feedCalls = fn (): array => Http::recorded(fn (Request $request) => str_ends_with($request->url(), '/feed'))
        ->map(function (array $pair): array {
            parse_str($pair[0]->body(), $form);

            return $form;
        })->values()->all();
});

it('uploads each image once, in order, and creates one post that names them', function () {
    fakeGraph();

    $harbour = storedImage($this->workspace, jpegBytes(60, 40));
    $gull = storedImage($this->workspace, pngBytes());

    runPublishJob(($this->claimWith)([$harbour, $gull]));

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($variant->external_id)->toBe('100000000000001_900000000000001')
        ->and($variant->permalink)->toBe('https://www.facebook.test/100000000000001_900000000000001')
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and(($this->remembered)())->toBe(['800000000000001', '800000000000002']);

    expect(array_map(fn (array $upload) => [$upload['fields']['alt_text_custom'], $upload['fields']['published'], $upload['file']['type'], hash('sha256', $upload['file']['contents'])], facebookUploads()))->toBe([
        ['Image 1.', 'false', 'image/jpeg', $harbour->content_hash],
        ['Image 2.', 'false', 'image/png', $gull->content_hash],
    ]);

    $feed = ($this->feedCalls)();

    expect($feed)->toHaveCount(1)
        ->and($feed[0]['message'])->toBe('Fresh beans arrive on Thursday.')
        ->and($feed[0]['attached_media'])->toBe(['{"media_fbid":"800000000000001"}', '{"media_fbid":"800000000000002"}'])
        ->and($feed[0])->not->toHaveKey('link');

    Http::assertSentCount(3);
});

it('publishes from a media disk that is object storage', function () {
    remoteMediaDisk();
    fakeGraph();

    $image = storedImage($this->workspace);

    runPublishJob(($this->claimWith)([$image]));

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(hash('sha256', facebookUploads()[0]['file']['contents']))->toBe($image->content_hash);
});

it('sends the link of a post with an image as the last line of the text', function () {
    fakeGraph();

    DB::table('post_variants')->where('post_id', $this->post->id)->update(['link' => 'https://example.com/beans']);

    runPublishJob(($this->claimWith)([storedImage($this->workspace)]));

    $feed = ($this->feedCalls)();

    expect($feed[0]['message'])->toBe("Fresh beans arrive on Thursday.\n\nhttps://example.com/beans")
        ->and($feed[0])->not->toHaveKey('link')
        ->and(freshPost($this->post)->state)->toBe(PostState::Published);
});

it('makes one post when the job is dispatched twice', function () {
    fakeGraph();

    $job = ($this->claimWith)([storedImage($this->workspace)]);

    dispatch(clone $job);
    dispatch(clone $job);
    runPublishJob(unserialize(serialize($job)));

    expect(($this->feedCalls)())->toHaveCount(1)
        ->and(facebookUploads())->toHaveCount(1)
        ->and(freshPost($this->post)->state)->toBe(PostState::Published);
});

it('sends two images in one run and carries on in the next, without counting an attempt', function () {
    Queue::fake();
    fakeGraph();

    $job = ($this->claimWith)(array_map(fn () => storedImage($this->workspace), range(1, 5)));

    runPublishJob($job);

    expect(facebookUploads())->toHaveCount(2)
        ->and(($this->feedCalls)())->toBe([])
        ->and(freshPost($this->post)->state)->toBe(PostState::Publishing);

    $second = Queue::pushed(PublishPostJob::class)->sole();

    expect($second->attempt)->toBe(1)->and($second->delay)->toBe(0);

    runPublishJob($second);

    expect(facebookUploads())->toHaveCount(4)->and(($this->feedCalls)())->toBe([]);

    $third = Queue::pushed(PublishPostJob::class)->last();

    expect($third->attempt)->toBe(1);

    runPublishJob($third);

    $feed = ($this->feedCalls)();

    expect(facebookUploads())->toHaveCount(5)
        ->and($feed)->toHaveCount(1)
        ->and($feed[0]['attached_media'])->toBe(array_map(fn (int $n) => '{"media_fbid":"80000000000000'.$n.'"}', range(1, 5)))
        ->and(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(Queue::pushed(PublishPostJob::class))->toHaveCount(2);
});

describe('an upload that fails', function () {
    it('waits as long as Facebook asks after a rate limit, then sends only the image that is missing', function () {
        Queue::fake();

        fakeGraph(['photos' => fn () => count(facebookUploads()) === 2
            ? facebookResponse('error_rate_limit', 429, ['Retry-After' => '120'])
            : Http::response(['id' => (string) (800000000000000 + count(facebookUploads()))])]);

        runPublishJob(($this->claimWith)([storedImage($this->workspace), storedImage($this->workspace)]));

        [$variant] = variantsOf($this->post);

        expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
            ->and($variant->hasUnknownOutcome())->toBeFalse()
            ->and($variant->error)->toStartWith('Facebook is limiting requests at the moment.')
            ->and(($this->remembered)())->toBe(['800000000000001', null])
            ->and(($this->feedCalls)())->toBe([]);

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        expect($successor->attempt)->toBe(2)->and($successor->delay)->toBe(120);

        runPublishJob($successor);

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(3)
            ->and(($this->feedCalls)()[0]['attached_media'])->toBe(['{"media_fbid":"800000000000001"}', '{"media_fbid":"800000000000003"}']);
    });

    it('tries again after a timeout, which it would never do for the post itself', function () {
        Queue::fake();

        $calls = 0;
        fakeGraph(['photos' => function (Request $request) use (&$calls) {
            return ++$calls === 1
                ? throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 60001 milliseconds')
                : facebookResponse('photo_upload');
        }]);

        runPublishJob(($this->claimWith)([storedImage($this->workspace)]));

        expect(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse()
            ->and(freshPost($this->post)->state)->toBe(PostState::Publishing);

        runPublishJob(Queue::pushed(PublishPostJob::class)->sole());

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(($this->feedCalls)())->toHaveCount(1);
    });

    it('fails the post with a sentence that names the image Facebook refused, and makes no post', function () {
        fakeGraph(['photos' => fn () => count(facebookUploads()) === 2
            ? facebookResponse('error_invalid_image', 400)
            : facebookResponse('photo_upload')]);

        runPublishJob(($this->claimWith)([storedImage($this->workspace), storedImage($this->workspace)]));

        [$variant] = variantsOf($this->post);

        expect(freshPost($this->post)->state)->toBe(PostState::Failed)
            ->and($variant->error)->toBe('Facebook did not take image 2 of this post. Facebook said: (#324) Missing or invalid image file')
            ->and($variant->hasUnknownOutcome())->toBeFalse()
            ->and(($this->feedCalls)())->toBe([]);
    });

    it('asks for a reconnect when the token is refused, and makes no post', function () {
        fakeGraph(['photos' => facebookResponse('error_token_expired', 400)]);

        runPublishJob(($this->claimWith)([storedImage($this->workspace)]));

        expect(freshPost($this->post)->state)->toBe(PostState::Failed)
            ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::NeedsReconnect)
            ->and(($this->feedCalls)())->toBe([]);
    });
});

describe('between the uploads and the post', function () {
    it('uploads only what is missing after a run that died, and posts once', function () {
        fakeGraph();

        $first = storedImage($this->workspace);
        $second = storedImage($this->workspace);

        ($this->claimWith)([$first, $second]);

        // What a run leaves that sent one photo and then lost its worker:
        // one id remembered, no mark on the variant, the post failed by
        // the recovery.
        DB::table('post_variant_media')->where('media_id', $first->id)->update(['external_id' => '800000000000042', 'uploaded_at' => now()]);
        DB::table('posts')->where('id', $this->post->id)->update(['state' => 'failed']);

        ($this->sendAgain)();

        $feed = ($this->feedCalls)();

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(1)
            ->and(hash('sha256', facebookUploads()[0]['file']['contents']))->toBe($second->content_hash)
            ->and($feed)->toHaveCount(1)
            ->and($feed[0]['attached_media'])->toBe(['{"media_fbid":"800000000000042"}', '{"media_fbid":"800000000000001"}']);
    });

    it('never calls the feed again after an answer that leaves the outcome unknown, and keeps the uploads', function (Closure $response) {
        Queue::fake();

        $calls = 0;
        fakeGraph(['feed' => function (Request $request) use ($response, &$calls) {
            $calls++;

            return $response($request);
        }]);

        $job = ($this->claimWith)([storedImage($this->workspace)]);

        runPublishJob($job);
        runPublishJob(unserialize(serialize($job)));

        expect($calls)->toBe(1);
        Queue::assertNothingPushed();

        [$variant] = variantsOf($this->post);

        expect(freshPost($this->post)->state)->toBe(PostState::Failed)
            ->and($variant->hasUnknownOutcome())->toBeTrue()
            ->and($variant->error)->toContain('Check the Page before trying again')
            ->and(($this->remembered)())->toBe(['800000000000001'])
            ->and(facebookUploads())->toHaveCount(1);
    })->with(facebookAmbiguousFailures());

    it('retries the post after a rate limit without sending the image again', function () {
        Queue::fake();

        $calls = 0;
        fakeGraph(['feed' => function () use (&$calls) {
            return ++$calls === 1 ? facebookResponse('error_rate_limit', 400) : facebookResponse('feed_post');
        }]);

        runPublishJob(($this->claimWith)([storedImage($this->workspace)]));
        runPublishJob(Queue::pushed(PublishPostJob::class)->sole());

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(1)
            ->and($calls)->toBe(2);
    });
});

describe('a photo Facebook no longer holds', function () {
    it('uploads again when the lifetime of the upload has passed, before anything is posted', function () {
        fakeGraph();

        ($this->claimWith)([storedImage($this->workspace)]);

        // The lifetime in the test settings is two hours.
        DB::table('post_variant_media')->update(['external_id' => '800000000000042', 'uploaded_at' => now()->subMinutes(121)]);
        DB::table('posts')->where('id', $this->post->id)->update(['state' => 'failed']);

        ($this->sendAgain)();

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(1)
            ->and(($this->feedCalls)()[0]['attached_media'])->toBe(['{"media_fbid":"800000000000001"}']);
    });

    it('uploads again when Facebook refuses a photo before its lifetime has passed, and then posts once', function () {
        Queue::fake();

        // Facebook has dropped photo 800000000000042 early.
        fakeGraph(['feed' => fn (Request $request) => str_contains(urldecode($request->body()), '800000000000042')
            ? facebookResponse('error_photo_expired', 400)
            : facebookResponse('feed_post')]);

        $job = ($this->claimWith)([storedImage($this->workspace), storedImage($this->workspace)]);

        DB::table('post_variant_media')->where('position', 1)->update(['external_id' => '800000000000042', 'uploaded_at' => now()->subMinutes(90)]);
        DB::table('post_variant_media')->where('position', 2)->update(['external_id' => '800000000000043', 'uploaded_at' => now()->subMinutes(90)]);

        runPublishJob($job);

        [$variant] = variantsOf($this->post);

        // A definite refusal: nothing is in doubt, the uploads are
        // forgotten, and the post waits for its next attempt.
        expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
            ->and($variant->hasUnknownOutcome())->toBeFalse()
            ->and($variant->error)->toContain('may no longer be kept there, so they are sent again.')
            ->and(($this->remembered)())->toBe([null, null])
            ->and(facebookUploads())->toBe([]);

        $successor = Queue::pushed(PublishPostJob::class)->sole();

        expect($successor->attempt)->toBe(2)->and($successor->delay)->toBe(60);

        runPublishJob($successor);

        $feed = ($this->feedCalls)();

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(2)
            ->and($feed)->toHaveCount(2)
            ->and($feed[1]['attached_media'])->toBe(['{"media_fbid":"800000000000001"}', '{"media_fbid":"800000000000002"}'])
            ->and(variantsOf($this->post)[0]->error)->toBeNull();
    });

    it('does not loop when Facebook refuses the post whatever its photos are: one new upload, then the refusal is final', function () {
        fakeGraph(['feed' => facebookResponse('error_photo_expired', 400)]);

        $job = ($this->claimWith)([storedImage($this->workspace)]);

        DB::table('post_variant_media')->update(['external_id' => '800000000000042', 'uploaded_at' => now()->subMinutes(90)]);

        // The sync queue runs the successor as it is dispatched.
        runPublishJob($job);

        [$variant] = variantsOf($this->post);

        expect(freshPost($this->post)->state)->toBe(PostState::Failed)
            ->and($variant->hasUnknownOutcome())->toBeFalse()
            ->and($variant->error)->toBe('Facebook refused the request. Facebook said: (#100) Invalid parameter')
            ->and(facebookUploads())->toHaveCount(1)
            ->and(($this->feedCalls)())->toHaveCount(2);
    });

    it('forgets the uploads also when no attempt is left, so that "send again" starts with new ones', function () {
        config(['noongun.publishing.max_attempts' => 1]);
        fakeGraph(['feed' => fn (Request $request) => str_contains(urldecode($request->body()), '800000000000042')
            ? facebookResponse('error_photo_expired', 400)
            : facebookResponse('feed_post')]);

        $job = ($this->claimWith)([storedImage($this->workspace)]);

        DB::table('post_variant_media')->update(['external_id' => '800000000000042', 'uploaded_at' => now()->subMinutes(90)]);

        runPublishJob($job);

        expect(freshPost($this->post)->state)->toBe(PostState::Failed)
            ->and(($this->remembered)())->toBe([null]);

        ($this->sendAgain)();

        expect(freshPost($this->post)->state)->toBe(PostState::Published)
            ->and(facebookUploads())->toHaveCount(1)
            ->and(($this->feedCalls)())->toHaveCount(2);
    });

    it('keeps the uploads when the adapter does not say they are invalid', function () {
        $fake = fakeChannelAdapter()->acceptingImages();
        $fake->failNextPublishes(PublishFailed::retryable('facebook', 'The platform is busy.'));

        Queue::fake();

        $job = ($this->claimWith)([storedImage($this->workspace)]);

        runPublishJob($job);

        expect(($this->remembered)()[0])->not->toBeNull();
    });
});

it('keeps the Page token, its proof and the app secret out of the log and out of the stored error', function (array $responses) {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged) {
        $logged[] = $entry->message.' '.json_encode($entry->context);
    });

    fakeGraph($responses);

    $job = ($this->claimWith)([storedImage($this->workspace)]);

    // A failing post needs its photo on Facebook first.
    if (! array_key_exists('photos', $responses)) {
        DB::table('post_variant_media')->update(['external_id' => '800000000000042', 'uploaded_at' => now()->subMinutes(90)]);
    }

    runPublishJob($job);

    $everything = implode("\n", $logged).' '.variantsOf($this->post)[0]->error;

    expect($logged)->not->toBe([])
        ->and($everything)
        ->not->toContain('test-page-token')
        ->not->toContain(FACEBOOK_TEST_APP_SECRET)
        ->not->toContain(facebookProof('test-page-token-table-bay-coffee'));
})->with([
    'an upload Facebook refuses while repeating the form' => [['photos' => fn (Request $request) => Http::response(['error' => [
        'message' => 'Bad request: '.implode(' ', facebookMultipart($request)['fields']),
        'code' => 324,
    ]], 400)]],
    'an upload that fails on the network, with the address in the message' => [['photos' => fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out')]],
    'a post Facebook refuses while repeating the form' => [['feed' => fn (Request $request) => Http::response(['error' => [
        'message' => 'Bad request: '.$request->body(),
        'code' => 100,
    ]], 400)]],
]);
