<?php

/*
 * How many images a Page post takes and how long a description may be are
 * not documented by Meta. The figures in the configuration are assumptions,
 * so the first post Facebook refuses over one of them must say so: which
 * limit it may be, and where it is changed. The fixtures for these refusals
 * are invented (tests/Fixtures/facebook/README.md).
 */

use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Post;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostState;
use App\Domain\Scheduling\PublishPostJob;
use App\Domain\Workspaces\Models\Workspace;
use App\Domain\Workspaces\Role;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/publishing.php';
require_once __DIR__.'/../../../Support/facebook.php';
require_once __DIR__.'/../../../Support/media.php';

beforeEach(function () {
    $this->travelTo('2026-11-27 09:00:00');
    configureFacebook();
    fakeMediaDisk();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create();
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'access_token' => 'test-page-token-table-bay-coffee',
    ]));

    $this->post = inWorkspace($this->workspace, function (): Post {
        $post = Post::factory()->create();
        PostVariant::factory()->create(['post_id' => $post->id, 'channel_id' => $this->channel->id, 'body' => 'Fresh beans arrive on Thursday.']);

        return $post;
    });

    $this->variant = fn (): PostVariant => inWorkspace($this->workspace, fn () => PostVariant::query()->where('post_id', $this->post->id)->firstOrFail());

    // Puts this many images on the variant, as ones Facebook holds since just now.
    $this->uploaded = function (int $count): void {
        foreach (range(1, $count) as $number) {
            $attachment = attachImage(($this->variant)(), storedImage($this->workspace));

            DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => (string) (800000000000000 + $number), 'uploaded_at' => now()]);
        }
    };

    $this->publish = fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->publish(($this->variant)()));

    $this->upload = function (?string $altText): string {
        attachImage(($this->variant)(), storedImage($this->workspace));
        $attachment = attachImage(($this->variant)(), storedImage($this->workspace), $altText);

        return inWorkspace($this->workspace, function () use ($attachment): string {
            $variant = ($this->variant)();

            return facebookAdapter()->uploadMedia($variant, $variant->images()->firstOrFail(fn ($own) => $own->is($attachment)));
        });
    };
});

describe('the number of images', function () {
    it('says that the limit is an assumption and where it is changed, when Facebook names the images', function () {
        fakeGraph(['feed' => facebookResponse('error_too_many_photos', 400)]);

        ($this->uploaded)(5);

        expect(fn () => ($this->publish)())->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->toBe(
                'Facebook refused the request. Facebook said: (#100) param attached_media has too many elements'
                ." Facebook's answer is about the images of this post, and it has 5."
                .' Meta documents no limit for it, and the 6 this instance allows for Facebook are an assumption.'
                .' Take images off the post, or have the limit lowered in the configuration of the instance (FACEBOOK_MAX_MEDIA_ITEMS).',
            )
                ->and($exception->retryable)->toBeFalse()
                ->and($exception->requiresReconnect)->toBeFalse()
                ->and($exception->invalidatesUploads())->toBeFalse()
                ->and($exception->context())->toMatchArray([
                    'endpoint' => 'POST /100000000000001/feed',
                    'code' => 100,
                    'http_status' => 400,
                    'fbtrace_id' => 'AtestTraceTooManyPhotos1',
                    'suspected_limit' => 'max_media_items',
                    'limit_named_by_platform' => true,
                    'images' => 5,
                    'retryable' => false,
                ])
                ->and($exception->context())->not->toHaveKey('outcome_unknown');
        });
    });

    it('offers the limit as a possible cause when Facebook does not say what it refused', function () {
        fakeGraph(['feed' => facebookResponse('error_photo_expired', 400)]);

        ($this->uploaded)(3);

        expect(fn () => ($this->publish)())->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->toStartWith('Facebook refused the request. Facebook said: (#100) Invalid parameter One possible cause is the number of images on this post, which is 3.')
                ->and($exception->getMessage())->toContain('FACEBOOK_MAX_MEDIA_ITEMS')
                ->and($exception->context())->toMatchArray(['suspected_limit' => 'max_media_items', 'limit_named_by_platform' => false]);
        });
    });

    it('says nothing about a limit for one image, for a refusal with another code, or when the outcome is unknown', function (int $images, Closure $response) {
        fakeGraph(['feed' => $response]);

        ($this->uploaded)($images);

        expect(fn () => ($this->publish)())->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->not->toContain('FACEBOOK_MAX_MEDIA_ITEMS')
                ->and($exception->context())->not->toHaveKey('suspected_limit');
        });
    })->with([
        'one image' => [1, fn () => facebookResponse('error_too_many_photos', 400)],
        'a duplicate post' => [4, fn () => facebookResponse('error_duplicate_post', 400)],
        'a rate limit' => [4, fn () => facebookResponse('error_rate_limit', 400)],
        'an expired token' => [4, fn () => facebookResponse('error_token_expired', 400)],
        'invalid parameter with HTTP 500' => [4, fn () => facebookResponse('error_too_many_photos', 500)],
    ]);

    it('still sends old uploads again first, and names the limit when the post is refused once more', function () {
        fakeGraph(['feed' => facebookResponse('error_too_many_photos', 400)]);

        ($this->uploaded)(2);
        DB::table('post_variant_media')->update(['uploaded_at' => now()->subHour()]);

        expect(fn () => ($this->publish)())->toThrow(fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeTrue()
            ->and($exception->context())->not->toHaveKey('suspected_limit'));

        DB::table('post_variant_media')->update(['uploaded_at' => now()]);

        expect(fn () => ($this->publish)())->toThrow(fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeFalse()
            ->and($exception->context())->toMatchArray(['suspected_limit' => 'max_media_items']));
    });
});

describe('the description of an image', function () {
    it('says that the limit is an assumption and where it is changed, when Facebook names the description', function () {
        fakeGraph(['photos' => facebookResponse('error_alt_text_too_long', 400)]);

        expect(fn () => ($this->upload)(str_repeat('Æ', 650)))->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->toBe(
                'Facebook did not take image 2 of this post. Facebook said: (#100) Param alt_text_custom is too long'
                ." Facebook's answer is about the description of image 2, which is 650 characters long."
                .' Meta documents no limit for it, and the 700 characters this instance allows for Facebook are an assumption.'
                .' Shorten the description, or have the limit lowered in the configuration of the instance (FACEBOOK_ALT_TEXT_LENGTH).',
            )
                ->and($exception->retryable)->toBeFalse()
                ->and($exception->context())->toMatchArray([
                    'endpoint' => 'POST /100000000000001/photos',
                    'code' => 100,
                    'fbtrace_id' => 'AtestTraceAltTextLong01',
                    'suspected_limit' => 'alt_text_length',
                    'limit_named_by_platform' => true,
                    'description_length' => 650,
                ]);
        });
    });

    it('offers the limit as a possible cause when Facebook does not say what it refused', function () {
        fakeGraph(['photos' => facebookResponse('error_validation', 400)]);

        expect(fn () => ($this->upload)('A harbour at dusk.'))->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->toContain('One possible cause is the description of image 2, which is 18 characters long.')
                ->and($exception->getMessage())->toContain('FACEBOOK_ALT_TEXT_LENGTH')
                ->and($exception->context())->toMatchArray(['limit_named_by_platform' => false]);
        });
    });

    it('says nothing about a limit for an image without a description, or for a file Facebook cannot read', function (?string $altText, string $fixture) {
        fakeGraph(['photos' => facebookResponse($fixture, 400)]);

        expect(fn () => ($this->upload)($altText))->toThrow(function (PublishFailed $exception) {
            expect($exception->getMessage())->not->toContain('FACEBOOK_ALT_TEXT_LENGTH')
                ->and($exception->context())->not->toHaveKey('suspected_limit');
        });
    })->with([
        'no description' => [null, 'error_alt_text_too_long'],
        'an invalid image file' => ['A harbour at dusk.', 'error_invalid_image'],
        'an expired token' => ['A harbour at dusk.', 'error_token_expired'],
    ]);
});

it('shows the member which limit it may be and writes the code Facebook gave to the log', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $entry) use (&$logged) {
        $logged[] = ['level' => $entry->level, 'message' => $entry->message, 'context' => $entry->context];
    });

    fakeGraph(['feed' => facebookResponse('error_too_many_photos', 400)]);

    foreach (range(1, 3) as $number) {
        attachImage(($this->variant)(), storedImage($this->workspace), "Image {$number}.");
    }

    $owner = userWithRole($this->workspace, Role::Owner);

    $job = inWorkspace($this->workspace, function () use ($owner): PublishPostJob {
        $this->post->approve($owner)->schedule(now()->subMinute());
        expect($this->post->claimForPublishing())->toBeTrue();

        return PublishPostJob::forClaimed($this->post);
    });

    runPublishJob($job);

    [$variant] = variantsOf($this->post);

    expect(freshPost($this->post)->state)->toBe(PostState::Failed)
        ->and($variant->hasUnknownOutcome())->toBeFalse()
        ->and($variant->error)->toContain("Facebook's answer is about the images of this post, and it has 3.")
        ->and($variant->error)->toContain('the 6 this instance allows for Facebook are an assumption')
        ->and($variant->error)->toContain('configuration of the instance (FACEBOOK_MAX_MEDIA_ITEMS)');

    $entry = collect($logged)->firstWhere('message', 'Publishing a variant failed.');

    expect($entry)->not->toBeNull()
        ->and($entry['level'])->toBe('warning')
        ->and($entry['context'])->toMatchArray([
            'code' => 100,
            'http_status' => 400,
            'fbtrace_id' => 'AtestTraceTooManyPhotos1',
            'endpoint' => 'POST /100000000000001/feed',
            'suspected_limit' => 'max_media_items',
            'images' => 3,
        ])
        ->and(json_encode($entry['context']))->not->toContain('test-page-token');
});
