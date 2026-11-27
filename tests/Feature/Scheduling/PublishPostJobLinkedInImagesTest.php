<?php

/*
 * A post with images, through the job and the real LinkedIn adapter,
 * against the faked LinkedIn API: which calls are made, in which order, and
 * above all which are never made twice.
 */

use App\Domain\Channels\ChannelStatus;
use App\Domain\Channels\Models\Channel;
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
require_once __DIR__.'/../../Support/linkedin.php';
require_once __DIR__.'/../../Support/media.php';

beforeEach(function () {
    $this->travelTo('2026-11-26 10:15:00');
    configureLinkedIn();
    fakeMediaDisk();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Europe/Copenhagen']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create([
        'external_id' => LINKEDIN_TEST_MEMBER,
        'display_name' => 'Thandi Mokoena',
        'access_token' => LINKEDIN_TEST_TOKEN,
        'token_expires_at' => '2027-01-24 10:00:00',
    ]));

    $this->post = inWorkspace($this->workspace, function (): Post {
        $post = Post::factory()->create();
        PostVariant::factory()->create(['post_id' => $post->id, 'channel_id' => $this->channel->id, 'body' => 'Fresh beans arrive on Thursday.']);

        return $post;
    });

    $this->images = [
        attachImage(variantsOf($this->post)[0], storedImage($this->workspace, jpegBytes(60, 40)), 'Sacks of beans on a pallet.'),
        attachImage(variantsOf($this->post)[0], storedImage($this->workspace, pngBytes()), 'The roaster.'),
    ];

    // Approved through the readiness check, scheduled, then claimed the
    // way the scheduler does.
    $this->claim = function (): PublishPostJob {
        $owner = userWithRole($this->workspace, Role::Owner);

        return inWorkspace($this->workspace, function () use ($owner): PublishPostJob {
            $this->post->approve($owner)->schedule(now()->subMinute());
            expect($this->post->claimForPublishing())->toBeTrue();

            return PublishPostJob::forClaimed($this->post);
        });
    };

    $this->uploads = fn (): array => DB::table('post_variant_media')
        ->where('post_variant_id', variantsOf($this->post)[0]->id)
        ->orderBy('position')
        ->pluck('external_id')
        ->all();

    $this->logged = '';
    Event::listen(MessageLogged::class, function (MessageLogged $entry) {
        $this->logged .= $entry->message.json_encode($entry->context);
    });
});

it('sends each image, then creates one post that shows them in order', function () {
    fakeLinkedIn();
    $job = ($this->claim)();

    dispatch(clone $job);
    runPublishJob(unserialize(serialize($job)));

    expect(linkedInCalls())->toBe([
        'POST '.LINKEDIN_TEST_API.'/rest/images',
        'PUT '.LINKEDIN_TEST_UPLOADS.'/C4E10AQTestImage1/uploaded-image/0',
        'POST '.LINKEDIN_TEST_API.'/rest/images',
        'PUT '.LINKEDIN_TEST_UPLOADS.'/C4E10AQTestImage2/uploaded-image/0',
        'POST '.LINKEDIN_TEST_API.'/rest/posts',
    ]);

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($variant->external_id)->toBe(LINKEDIN_TEST_POST)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(2)])
        ->and(Http::recorded()->last()[0]['content'])->toBe(['multiImage' => ['images' => [
            ['id' => linkedInImageUrn(1), 'altText' => 'Sacks of beans on a pallet.'],
            ['id' => linkedInImageUrn(2), 'altText' => 'The roaster.'],
        ]]])
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN);
});

it('lets a post with an image and a link be approved without a link title, and sends the link in the text', function () {
    fakeLinkedIn();
    DB::table('post_variants')->where('post_id', $this->post->id)->update(['link' => 'https://example.com/beans']);

    runPublishJob(($this->claim)());

    $sent = Http::recorded()->last()[0];

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($sent['commentary'])->toBe("Fresh beans arrive on Thursday.\n\nhttps://example.com/beans")
        ->and(array_keys($sent['content']))->toBe(['multiImage']);
});

it('sends only the image that is missing after an upload failed, and creates the post once', function (Closure $failure) {
    Queue::fake();
    $puts = 0;
    fakeLinkedIn(['upload' => function (Request $request) use (&$puts, $failure) {
        // The second image does not arrive the first time.
        return ++$puts === 2 ? $failure($request) : Http::response('', 201);
    }]);
    $job = ($this->claim)();

    runPublishJob($job);

    [$variant] = variantsOf($this->post);

    // Nothing public was attempted: the post waits, and nothing is in doubt.
    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and($variant->error)->not->toContain('The outcome is unknown')
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), null]);

    Http::assertNotSent(fn (Request $request) => $request->url() === LINKEDIN_TEST_API.'/rest/posts');

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());
    // The first job, delivered again by the queue.
    runPublishJob(unserialize(serialize($job)));

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        // The second attempt was the third address LinkedIn gave out.
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(3)])
        ->and($puts)->toBe(3)
        ->and(Http::recorded(fn (Request $request) => $request->url() === LINKEDIN_TEST_API.'/rest/posts'))->toHaveCount(1)
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_UPLOAD_SIGNATURE);
})->with([
    'server error' => [fn () => Http::response('', 500)],
    'timeout' => [fn (Request $request) => throw linkedInConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 45001 milliseconds')],
]);

it('uploads nothing again when the post call was turned away, and creates the post once', function () {
    Queue::fake();
    $posts = 0;
    fakeLinkedIn(['posts' => function () use (&$posts) {
        return ++$posts === 1 ? linkedInResponse('error_rate_limit', 429) : linkedInCreated();
    }]);
    $job = ($this->claim)();

    runPublishJob($job);

    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(2)]);

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'PUT'))->toHaveCount(2)
        ->and(Http::recorded(fn (Request $request) => str_starts_with($request->url(), LINKEDIN_TEST_API.'/rest/images')))->toHaveCount(2)
        ->and($posts)->toBe(2);
});

it('waits when LinkedIn limits the calls for an image, and loses nothing that was sent', function () {
    Queue::fake();
    $initialised = 0;
    fakeLinkedIn(['images' => function () use (&$initialised) {
        // The daily limit counts every call, and an image is two of them.
        return ++$initialised === 2 ? linkedInResponse('error_rate_limit', 429) : linkedInUploadInitialised($initialised);
    }]);

    runPublishJob(($this->claim)());

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and($variant->error)->toBe('LinkedIn is limiting requests at the moment. Try again later.')
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), null]);

    Http::assertNotSent(fn (Request $request) => $request->url() === LINKEDIN_TEST_API.'/rest/posts');

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(3)])
        ->and(Http::recorded(fn (Request $request) => $request->url() === LINKEDIN_TEST_API.'/rest/posts'))->toHaveCount(1);
});

it('waits and tries again when LinkedIn says an image is not ready, without calling the outcome unknown', function () {
    Queue::fake();
    $posts = 0;
    fakeLinkedIn(['posts' => function () use (&$posts) {
        return ++$posts === 1 ? linkedInResponse('error_image_not_ready', 422) : linkedInCreated();
    }]);

    runPublishJob(($this->claim)());

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and($variant->error)->toBe('LinkedIn is still preparing an image of this post. Nothing was posted.');

    $successor = Queue::pushed(PublishPostJob::class)->sole();

    expect($successor->delay)->toBeGreaterThan(0);

    runPublishJob($successor);

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and($posts)->toBe(2)
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'PUT'))->toHaveCount(2);
});

it('sends the images again when LinkedIn no longer has the ones that were sent earlier', function () {
    Queue::fake();
    $posts = 0;
    fakeLinkedIn(['posts' => function () use (&$posts) {
        return ++$posts === 1 ? linkedInResponse('error_image_invalid', 400) : linkedInCreated();
    }]);

    // Sent two hours ago, by an attempt that did not get to the post.
    DB::table('post_variant_media')
        ->where('post_variant_id', variantsOf($this->post)[0]->id)
        ->update(['external_id' => 'urn:li:image:C4E10AQGone', 'uploaded_at' => now()->subHours(2)]);

    runPublishJob(($this->claim)());

    expect(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/posts'])
        ->and(freshPost($this->post)->state)->toBe(PostState::Publishing)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse()
        ->and(($this->uploads)())->toBe([null, null]);

    runPublishJob(Queue::pushed(PublishPostJob::class)->sole());

    expect(freshPost($this->post)->state)->toBe(PostState::Published)
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(2)])
        ->and($posts)->toBe(2)
        ->and(Http::recorded()->last()[0]['content']['multiImage']['images'][0]['id'])->toBe(linkedInImageUrn(1));
});

it('never calls the Posts API again after an answer that leaves the outcome of a post with images unknown', function (Closure $response) {
    Queue::fake();
    $posts = 0;
    fakeLinkedIn(['posts' => function (Request $request) use ($response, &$posts) {
        $posts++;

        return $response($request);
    }]);
    $job = ($this->claim)();

    runPublishJob($job);
    runPublishJob(unserialize(serialize($job)));

    expect($posts)->toBe(1);
    Queue::assertNothingPushed();

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and($variant->hasUnknownOutcome())->toBeTrue()
        ->and($variant->error)->toContain('Look at the profile. If the post is there, leave this as it is.')
        // The images were sent once, and are still known for a person who sends the post again.
        ->and(Http::recorded(fn (Request $request) => $request->method() === 'PUT'))->toHaveCount(2)
        ->and(($this->uploads)())->toBe([linkedInImageUrn(1), linkedInImageUrn(2)])
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_SECRET);
})->with(linkedInAmbiguousFailures());

it('fails the post with nothing sent to the address when LinkedIn names one that is not its own', function () {
    Queue::fake();
    fakeLinkedIn(['images' => fn () => linkedInUploadInitialised(1, 'https://www.linkedin.test@uploads.example.test/dms-uploads/1?ut='.LINKEDIN_TEST_UPLOAD_SIGNATURE)]);

    runPublishJob(($this->claim)());

    [$variant] = variantsOf($this->post);

    expect(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/images'])
        ->and(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and($variant->error)->toContain('Nothing was sent to it and nothing was posted.')
        ->and(($this->uploads)())->toBe([null, null])
        ->and($this->logged)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_UPLOAD_SIGNATURE)->not->toContain('uploads.example.test');

    Queue::assertNothingPushed();
});

it('marks the channel for a reconnect when the token is refused for an image, and creates no post', function () {
    fakeLinkedIn(['images' => linkedInResponse('error_token_expired', 401)]);

    runPublishJob(($this->claim)());

    expect(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/images'])
        ->and(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and(variantsOf($this->post)[0]->hasUnknownOutcome())->toBeFalse()
        ->and(inWorkspace($this->workspace, fn () => $this->channel->fresh())->status)->toBe(ChannelStatus::NeedsReconnect);
});
