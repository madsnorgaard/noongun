<?php

/*
 * Images on a Page post, at the adapter: one photo is sent unpublished for
 * each image, and one call to the Page feed names them. The job that drives
 * the two steps is tested with the real adapter in
 * tests/Feature/Scheduling/PublishPostJobFacebookImagesTest.php.
 */

use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Contracts\UploadsMedia;
use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\Media;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Workspaces\Models\Workspace;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../../../Support/facebook.php';
require_once __DIR__.'/../../../Support/media.php';

const IMAGES_PAGE_TOKEN = 'test-page-token-table-bay-coffee';

beforeEach(function () {
    $this->travelTo('2026-11-26 09:00:00');
    configureFacebook();
    fakeMediaDisk();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->create([
        'external_id' => '100000000000001',
        'display_name' => 'Table Bay Coffee',
        'access_token' => IMAGES_PAGE_TOKEN,
    ]));

    $this->variant = inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create([
        'body' => 'Fresh beans arrive on Thursday.',
    ]));

    // The variant as the job reads it: fresh, inside the workspace.
    $this->fresh = fn (): PostVariant => inWorkspace($this->workspace, fn () => PostVariant::query()->findOrFail($this->variant->id));

    $this->upload = function (MediaAttachment $attachment): string {
        return inWorkspace($this->workspace, function () use ($attachment): string {
            $variant = PostVariant::query()->findOrFail($this->variant->id);

            return facebookAdapter()->uploadMedia($variant, $variant->images()->firstOrFail(fn (MediaAttachment $own) => $own->is($attachment)));
        });
    };

    // Puts an image on the variant as one Facebook already holds.
    $this->uploaded = function (string $photoId, ?string $altText = null, ?DateTimeInterface $at = null, ?Media $media = null): MediaAttachment {
        $attachment = attachImage($this->variant, $media ?? storedImage($this->workspace), $altText);

        DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => $photoId, 'uploaded_at' => $at ?? now()]);

        return $attachment;
    };

    $this->publish = fn () => inWorkspace($this->workspace, fn () => facebookAdapter()->publish(PostVariant::query()->findOrFail($this->variant->id)));
});

describe('capabilities', function () {
    it('takes every value for images from the settings', function () {
        $capabilities = facebookAdapter()->capabilities();

        expect(facebookAdapter())->toBeInstanceOf(UploadsMedia::class)
            ->and($capabilities->acceptsMedia())->toBeTrue()
            ->and($capabilities->mediaTypes)->toBe(['image/jpeg', 'image/png'])
            ->and($capabilities->maxMediaItems)->toBe(6)
            ->and($capabilities->maxMediaBytes)->toBe(7_000_000)
            ->and($capabilities->maxMediaPixels)->toBeNull()
            ->and($capabilities->altTextLength)->toBe(700)
            ->and($capabilities->mediaLinkRule)->toBe(MediaLinkRule::InText)
            ->and($capabilities->mediaUploadLifetime)->toBe(7200);
    });

    it('ships with what Meta documents, and careful values where it documents nothing', function () {
        $settings = (require base_path('config/noongun.php'))['channels']['facebook'];

        expect($settings['media_types'])->toBe(['image/jpeg', 'image/png'])
            ->and($settings['max_media_items'])->toBe(10)
            ->and($settings['max_media_bytes'])->toBe(10_000_000)
            ->and($settings['max_media_pixels'])->toBeNull()
            ->and($settings['alt_text_length'])->toBe(1000)
            ->and($settings['media_link_rule'])->toBe('in_text')
            ->and($settings['media_upload_lifetime'])->toBe(72000)
            ->and($settings['media_upload_trusted'])->toBe(900);
    });

    it('takes no images when the settings name no type or no count', function (array $settings) {
        configureFacebook($settings);

        $capabilities = facebookAdapter()->capabilities();

        expect($capabilities->acceptsMedia())->toBeFalse()
            ->and($capabilities->mediaTypes)->toBe([])
            ->and($capabilities->maxMediaItems)->toBe(0);
    })->with([
        'no types' => [['media_types' => []]],
        'a count of zero' => [['max_media_items' => 0]],
        'nothing at all' => [['media_types' => null, 'max_media_items' => null]],
    ]);

    it('falls back to the link in the text for a rule it does not know', function () {
        configureFacebook(['media_link_rule' => 'whatever', 'max_media_pixels' => 25_000_000]);

        expect(facebookAdapter()->capabilities()->mediaLinkRule)->toBe(MediaLinkRule::InText)
            ->and(facebookAdapter()->capabilities()->maxMediaPixels)->toBe(25_000_000);
    });
});

describe('uploadMedia', function () {
    it('sends the file to the photos of the Page, unpublished, with its description, and returns the photo id', function () {
        fakeGraph();
        config(['noongun.publishing.media_timeout' => 47]);

        $image = storedImage($this->workspace, jpegBytes(60, 40));
        $attachment = attachImage($this->variant, $image, 'A harbour at dusk, with "Table Mountain" behind it.');

        expect(($this->upload)($attachment))->toBe('800000000000001');

        Http::assertSentCount(1);

        [$sent] = facebookUploads();

        // Nothing in the address: the token is a field of the body.
        expect($sent['url'])->toBe(FACEBOOK_TEST_GRAPH.'/100000000000001/photos')
            ->and($sent['timeout'])->toBe(47)
            ->and($sent['fields'])->toBe([
                'published' => 'false',
                'alt_text_custom' => 'A harbour at dusk, with "Table Mountain" behind it.',
                'access_token' => IMAGES_PAGE_TOKEN,
                'appsecret_proof' => facebookProof(IMAGES_PAGE_TOKEN),
            ])
            ->and($sent['file']['field'])->toBe('source')
            ->and($sent['file']['filename'])->toBe('image-1.jpg')
            ->and($sent['file']['type'])->toBe('image/jpeg')
            ->and(hash('sha256', $sent['file']['contents']))->toBe($image->content_hash);
    });

    it('sends a PNG as a PNG, names it by its place, and leaves the description out when there is none', function () {
        fakeGraph();

        attachImage($this->variant, storedImage($this->workspace));
        $gull = storedImage($this->workspace, pngBytes());
        $attachment = attachImage($this->variant, $gull);

        ($this->upload)($attachment);

        [$sent] = facebookUploads();

        expect($sent['fields'])->not->toHaveKey('alt_text_custom')
            ->and($sent['fields']['published'])->toBe('false')
            ->and($sent['file']['filename'])->toBe('image-2.png')
            ->and($sent['file']['type'])->toBe('image/png')
            ->and($sent['file']['contents'])->toBe(storedBytes($gull));
    });

    it('writes nothing: the job remembers the id', function () {
        fakeGraph();

        $attachment = attachImage($this->variant, storedImage($this->workspace));

        ($this->upload)($attachment);

        expect(DB::table('post_variant_media')->where('id', $attachment->id)->value('external_id'))->toBeNull()
            ->and(($this->fresh)()->external_id)->toBeNull();
    });

    it('reads the file from object storage as a stream and closes it, also after a failure', function (Closure $response, bool $fails) {
        // The disk has no path and no address. Its streams are handed out
        // from a copy, so that the test can see what became of them.
        $disk = remoteMediaDisk();
        $copy = Storage::fake('copy-of-objects');
        $opened = [];

        $disk->shouldReceive('readStream')->andReturnUsing(function (string $path) use (&$opened, $copy) {
            return $opened[] = $copy->readStream($path);
        });

        $image = storedImage($this->workspace);
        $copy->put($image->path, storedBytes($image));

        fakeGraph(['photos' => $response]);

        $attachment = attachImage($this->variant, $image);

        try {
            ($this->upload)($attachment);
            expect($fails)->toBeFalse();
        } catch (PublishFailed) {
            expect($fails)->toBeTrue();
        }

        expect($opened)->toHaveCount(1)
            ->and(is_resource($opened[0]))->toBeFalse()
            ->and(hash('sha256', facebookUploads()[0]['file']['contents']))->toBe($image->content_hash);
    })->with([
        'taken' => [fn () => facebookResponse('photo_upload'), false],
        'refused' => [fn () => facebookResponse('error_invalid_image', 400), true],
        'timed out' => [fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 60001 milliseconds'), true],
    ]);

    it('takes an id only when it is what Facebook says a photo id is', function (mixed $body) {
        fakeGraph(['photos' => Http::response($body)]);

        $attachment = attachImage($this->variant, storedImage($this->workspace));

        expect(fn () => ($this->upload)($attachment))->toThrow(
            fn (PublishFailed $exception) => expect($exception->retryable)->toBeTrue()
                ->and($exception->getMessage())->toBe('Facebook took image 1 but gave no usable id for it.')
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /100000000000001/photos']),
        );
    })->with([
        'no id' => [['success' => true]],
        'an empty id' => [['id' => '']],
        'an id that is an address' => [['id' => 'https://evil.test/photo']],
        'an id with a path in it' => [['id' => '800000000000001/../../me']],
        'an id that would break out of the JSON it is put in' => [['id' => '8"},{"media_fbid":"1']],
        'an id that is a list' => [['id' => ['800000000000001']]],
    ]);

    it('accepts an id that arrives as a number', function () {
        fakeGraph(['photos' => Http::response(['id' => 800000000000009])]);

        expect(($this->upload)(attachImage($this->variant, storedImage($this->workspace))))->toBe('800000000000009');
    });

    it('maps each kind of failure, and names the image Facebook did not take', function (Closure $response, bool $retryable, bool $reconnect, ?int $retryAfter, string $message, array $context) {
        fakeGraph(['photos' => $response]);

        attachImage($this->variant, storedImage($this->workspace));
        $second = attachImage($this->variant, storedImage($this->workspace));

        try {
            ($this->upload)($second);
            $this->fail('Expected PublishFailed.');
        } catch (ChannelException $exception) {
            expect($exception)->toBeInstanceOf(PublishFailed::class)
                ->and($exception->platform)->toBe('facebook')
                ->and($exception->retryable)->toBe($retryable)
                ->and($exception->requiresReconnect)->toBe($reconnect)
                ->and($exception->retryAfterSeconds)->toBe($retryAfter)
                ->and($exception->getMessage())->toStartWith($message)
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /100000000000001/photos', ...$context])
                ->and($exception->context())->not->toHaveKey('outcome_unknown')
                ->and($exception->context())->not->toHaveKey(PublishFailed::UPLOADS_INVALID)
                ->and($exception->getPrevious())->toBeNull();
        }

        // One call: the adapter never tries again by itself.
        expect(facebookUploads())->toHaveCount(1);
    })->with([
        'an image Facebook cannot read: permanent' => [
            fn () => facebookResponse('error_invalid_image', 400), false, false, null,
            'Facebook did not take image 2 of this post. Facebook said: (#324) Missing or invalid image file', ['code' => 324],
        ],
        'a parameter Facebook refuses: permanent' => [
            fn () => facebookResponse('error_validation', 400), false, false, null,
            'Facebook did not take image 2 of this post.', ['code' => 100],
        ],
        'expired token: reconnect' => [
            fn () => facebookResponse('error_token_expired', 400), false, true, null,
            'Facebook no longer accepts this connection', ['code' => 190, 'subcode' => 463],
        ],
        'missing permission: reconnect' => [
            fn () => facebookResponse('error_permission', 403), false, true, null,
            'Facebook no longer accepts this connection', ['code' => 200],
        ],
        'Page rate limit: retry after the time Facebook gives' => [
            fn () => facebookResponse('error_rate_limit', 400, ['X-Business-Use-Case-Usage' => json_encode([
                '100000000000001' => [['type' => 'pages', 'call_count' => 100, 'total_cputime' => 25, 'total_time' => 25, 'estimated_time_to_regain_access' => 12]],
            ])]),
            true, false, 720, 'Facebook is limiting requests at the moment.', ['code' => 80001],
        ],
        'HTTP 429 with Retry-After: retry' => [
            fn () => Http::response('Too many requests', 429, ['Retry-After' => '30']), true, false, 30,
            'Facebook is limiting requests at the moment.', ['http_status' => 429],
        ],
        // An upload makes nothing public, so what publish() must call
        // "outcome unknown" is simply tried again here.
        'service error: retry' => [
            fn () => facebookResponse('error_service', 500), true, false, null,
            'Facebook had a temporary problem.', ['code' => 2, 'http_status' => 500],
        ],
        'gateway error without JSON: retry' => [
            fn () => Http::response('<html>Bad gateway</html>', 502), true, false, null,
            'Facebook had a temporary problem.', ['http_status' => 502],
        ],
        'an answer that cannot be read: retry' => [
            fn () => Http::response('OK', 200), true, false, null,
            'Facebook gave an answer that could not be read (HTTP 200).', ['http_status' => 200],
        ],
        'timeout: retry' => [
            fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 60001 milliseconds'), true, false, null,
            'Facebook could not be reached.', [],
        ],
        'connection reset while sending: retry' => [
            fn (Request $request) => throw facebookConnectionFailure($request, NetworkException::class, 'Send failure: Connection reset by peer'), true, false, null,
            'Facebook could not be reached.', [],
        ],
        'never connected: retry' => [
            fn (Request $request) => throw facebookConnectionFailure($request, ConnectException::class, 'Could not resolve host: graph.facebook.test'), true, false, null,
            'Facebook could not be reached.', [],
        ],
    ]);

    it('keeps the token, the proof and the secret out of the message, the context and the trace', function (Closure $response) {
        fakeGraph(['photos' => $response]);

        $attachment = attachImage($this->variant, storedImage($this->workspace), 'A harbour.');

        try {
            ($this->upload)($attachment);
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

            expect($everything)
                ->not->toContain('test-page-token')
                ->not->toContain(FACEBOOK_TEST_APP_SECRET)
                ->not->toContain(facebookProof(IMAGES_PAGE_TOKEN))
                ->and($exception->getPrevious())->toBeNull();
        }
    })->with([
        'Graph error that repeats the credentials' => [fn () => facebookResponse('error_echoes_credentials', 400)],
        'Graph error that repeats the fields of the form' => [fn (Request $request) => Http::response(['error' => [
            'message' => 'Bad request: '.implode(' ', facebookMultipart($request)['fields']),
            'code' => 100,
        ]], 400)],
        'Graph error that repeats the whole multipart body' => [fn (Request $request) => Http::response(['error' => [
            'message' => 'Could not parse: '.mb_convert_encoding($request->body(), 'UTF-8', 'ISO-8859-1'),
            'code' => 324,
        ]], 400)],
        'network failure whose message holds the URL and the token' => [fn (Request $request) => throw new ConnectionException(
            'cURL error 55: Send failure for '.$request->url().'?access_token='.IMAGES_PAGE_TOKEN.'&appsecret_proof='.facebookProof(IMAGES_PAGE_TOKEN),
        )],
    ]);

    it('sends nothing when the channel cannot take it', function (Closure $arrange, string $message, bool $reconnect) {
        $attachment = attachImage($this->variant, storedImage($this->workspace));
        $arrange($this);

        expect(fn () => ($this->upload)($attachment))->toThrow(
            fn (PublishFailed $exception) => expect($exception->getMessage())->toContain($message)
                ->and($exception->requiresReconnect)->toBe($reconnect)
                ->and($exception->retryable)->toBeFalse(),
        );

        Http::assertNothingSent();
    })->with([
        'no token' => [fn ($test) => inWorkspace($test->workspace, fn () => $test->channel->disconnect()), 'The Page is not connected.', true],
        'a Page id that is not an id' => [
            fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['external_id' => 'https://evil.test/100000000000001']),
            'not addressed to a Facebook Page', false,
        ],
        'another platform' => [fn ($test) => DB::table('channels')->where('id', $test->channel->id)->update(['platform' => 'linkedin']), 'not addressed to a Facebook Page', false],
        'an instance without the app secret' => [fn () => configureFacebook(['app_secret' => null]), 'FACEBOOK_APP_SECRET', false],
    ]);

    it('says which file is missing, without a call', function () {
        $image = storedImage($this->workspace);
        attachImage($this->variant, storedImage($this->workspace));
        $attachment = attachImage($this->variant, $image);

        Storage::disk('media')->delete($image->path);

        expect(fn () => ($this->upload)($attachment))->toThrow(PublishFailed::class, 'The file of image 2 is missing from the storage of this instance');

        Http::assertNothingSent();
    });
});

describe('publish with images', function () {
    it('makes one call to the Page feed that names the photo, and returns the post id and permalink', function () {
        fakeGraph();

        ($this->uploaded)('800000000000001', 'A harbour at dusk.');

        $result = ($this->publish)();

        expect($result->externalId)->toBe('100000000000001_900000000000001')
            ->and($result->permalink)->toBe('https://www.facebook.test/100000000000001_900000000000001')
            ->and($result->publishedAt->toIso8601String())->toBe('2026-11-26T09:00:00+00:00');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'POST'
            && $request->url() === FACEBOOK_TEST_GRAPH.'/100000000000001/feed'
            && $request->isForm()
            && $request->data() === [
                'message' => 'Fresh beans arrive on Thursday.',
                'attached_media[0]' => '{"media_fbid":"800000000000001"}',
                'access_token' => IMAGES_PAGE_TOKEN,
                'appsecret_proof' => facebookProof(IMAGES_PAGE_TOKEN),
            ]);
    });

    it('names several photos in the order of the images', function () {
        fakeGraph();

        // Uploaded out of order, as a continued job may have done it.
        ($this->uploaded)('800000000000007');
        ($this->uploaded)('800000000000003');
        ($this->uploaded)('800000000000005');

        ($this->publish)();

        Http::assertSentCount(1);
        Http::assertSent(function (Request $request) {
            // As Facebook reads the form: a list, in this order.
            parse_str($request->body(), $form);

            return $form['attached_media'] === [
                '{"media_fbid":"800000000000007"}',
                '{"media_fbid":"800000000000003"}',
                '{"media_fbid":"800000000000005"}',
            ];
        });
    });

    it('sends the link as the last line of the text, with its UTM values, and no link parameter', function () {
        fakeGraph();

        DB::table('post_variants')->where('id', $this->variant->id)->update([
            'link' => 'https://example.com/beans',
            'utm' => json_encode(['utm_source' => 'facebook', 'utm_medium' => 'social']),
        ]);
        ($this->uploaded)('800000000000001');

        ($this->publish)();

        Http::assertSent(fn (Request $request) => $request['message'] === "Fresh beans arrive on Thursday.\n\nhttps://example.com/beans?utm_source=facebook&utm_medium=social"
            && ! array_key_exists('link', $request->data()));
    });

    it('sends exactly the text the readiness check measured', function () {
        fakeGraph();

        DB::table('post_variants')->where('id', $this->variant->id)->update(['link' => 'https://example.com/beans']);
        ($this->uploaded)('800000000000001');

        $expected = inWorkspace($this->workspace, fn () => ($this->fresh)()->textFor(facebookAdapter()->capabilities()));

        ($this->publish)();

        Http::assertSent(fn (Request $request) => $request['message'] === $expected);
    });

    it('does not write to the variant or to its images', function () {
        fakeGraph();

        $attachment = ($this->uploaded)('800000000000001');

        ($this->publish)();

        expect(($this->fresh)()->external_id)->toBeNull()
            ->and(DB::table('post_variant_media')->where('id', $attachment->id)->value('external_id'))->toBe('800000000000001');
    });

    it('maps each kind of Graph failure as for a post without images', function (Closure $response, bool $retryable, bool $reconnect, ?int $retryAfter, array $context) {
        fakeGraph(['feed' => $response]);

        ($this->uploaded)('800000000000001');

        try {
            ($this->publish)();
            $this->fail('Expected PublishFailed.');
        } catch (ChannelException $exception) {
            expect($exception)->toBeInstanceOf(PublishFailed::class)
                ->and($exception->retryable)->toBe($retryable)
                ->and($exception->requiresReconnect)->toBe($reconnect)
                ->and($exception->retryAfterSeconds)->toBe($retryAfter)
                ->and($exception->context())->toMatchArray(['endpoint' => 'POST /100000000000001/feed', ...$context])
                ->and($exception->context())->not->toHaveKey(PublishFailed::UPLOADS_INVALID);
        }

        Http::assertSentCount(1);
    })->with([
        'expired token: reconnect' => [fn () => facebookResponse('error_token_expired', 400), false, true, null, ['code' => 190, 'subcode' => 463]],
        'missing permission: reconnect' => [fn () => facebookResponse('error_permission', 403), false, true, null, ['code' => 200]],
        'rate limit: retry after the time Facebook gives' => [fn () => facebookResponse('error_rate_limit', 429, ['Retry-After' => '30']), true, false, 30, ['code' => 80001]],
        'validation error just after the upload: permanent' => [fn () => facebookResponse('error_validation', 400), false, false, null, ['code' => 100]],
        'duplicate post: permanent' => [fn () => facebookResponse('error_duplicate_post', 400), false, false, null, ['code' => 506]],
    ]);

    it('never marks a failure as retryable when the post may have been made', function (Closure $response) {
        fakeGraph(['feed' => $response]);

        // Old enough to be blamed, if the failure were a refusal.
        ($this->uploaded)('800000000000001', at: now()->subHour());

        expect(fn () => ($this->publish)())->toThrow(
            fn (PublishFailed $exception) => expect($exception->retryable)->toBeFalse()
                ->and($exception->invalidatesUploads())->toBeFalse()
                ->and($exception->getMessage())->toContain('Check the Page before trying again.')
                ->and($exception->context())->toMatchArray(['outcome_unknown' => true]),
        );
    })->with([
        ...facebookAmbiguousFailures(),
        'invalid parameter with HTTP 503' => [fn () => facebookResponse('error_photo_expired', 503)],
        'success without an id' => [fn () => Http::response(['success' => true], 200)],
    ]);

    describe('a photo Facebook no longer holds', function () {
        it('says that the uploads are no longer valid when Facebook refuses photos that were sent a while ago', function (string $fixture, int $code) {
            fakeGraph(['feed' => facebookResponse($fixture, 400)]);

            ($this->uploaded)('800000000000001', at: now()->subMinutes(11));
            ($this->uploaded)('800000000000002', at: now()->subMinute());

            expect(fn () => ($this->publish)())->toThrow(
                fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeTrue()
                    // A definite refusal: no post was made, so it may be sent again.
                    ->and($exception->retryable)->toBeTrue()
                    ->and($exception->requiresReconnect)->toBeFalse()
                    ->and($exception->getMessage())->toContain('Facebook refused the request.')
                    ->and($exception->getMessage())->toContain('may no longer be kept there, so they are sent again.')
                    ->and($exception->context())->toMatchArray(['endpoint' => 'POST /100000000000001/feed', 'code' => $code, 'http_status' => 400, 'retryable' => true])
                    ->and($exception->context())->not->toHaveKey('outcome_unknown'),
            );

            Http::assertSentCount(1);
        })->with([
            'invalid parameter' => ['error_photo_expired', 100],
            'missing or invalid image' => ['error_invalid_image', 324],
        ]);

        it('does not blame photos that were sent a moment ago: the refusal is final', function () {
            fakeGraph(['feed' => facebookResponse('error_photo_expired', 400)]);

            ($this->uploaded)('800000000000001', at: now()->subMinutes(9));
            ($this->uploaded)('800000000000002');

            expect(fn () => ($this->publish)())->toThrow(
                fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeFalse()
                    ->and($exception->retryable)->toBeFalse(),
            );
        });

        it('does not blame the photos for a refusal that is about something else', function () {
            fakeGraph(['feed' => facebookResponse('error_duplicate_post', 400)]);

            ($this->uploaded)('800000000000001', at: now()->subHours(3));

            expect(fn () => ($this->publish)())->toThrow(
                fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeFalse()
                    ->and($exception->retryable)->toBeFalse(),
            );
        });

        it('makes no call for an image without a usable photo id, and asks for the uploads again', function (?string $photoId) {
            fakeGraph();

            ($this->uploaded)('800000000000001');
            $attachment = attachImage($this->variant, storedImage($this->workspace));

            DB::table('post_variant_media')->where('id', $attachment->id)->update(['external_id' => $photoId, 'uploaded_at' => $photoId === null ? null : now()]);

            expect(fn () => ($this->publish)())->toThrow(
                fn (PublishFailed $exception) => expect($exception->invalidatesUploads())->toBeTrue()
                    ->and($exception->getMessage())->toBe('Image 2 of this post is not on Facebook yet. Nothing was published.'),
            );

            Http::assertNothingSent();
        })->with([
            'never uploaded' => [null],
            'an id of another platform' => ['urn:li:image:C4E10AQFoyyAjHPMQuQ'],
            'an id that would break out of the JSON' => ['8"},{"media_fbid":"1'],
        ]);
    });

    it('keeps the token out of every failure', function () {
        fakeGraph(['feed' => fn (Request $request) => Http::response(['error' => [
            'message' => 'Bad request: '.$request->body().' '.json_encode($request->data()),
            'code' => 100,
        ]], 400)]);

        ($this->uploaded)('800000000000001', at: now()->subHour());

        try {
            ($this->publish)();
            $this->fail('Expected PublishFailed.');
        } catch (PublishFailed $exception) {
            expect($exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString())
                ->not->toContain('test-page-token')
                ->not->toContain(FACEBOOK_TEST_APP_SECRET)
                ->not->toContain(facebookProof(IMAGES_PAGE_TOKEN));
        }
    });
});

describe('workspace isolation', function () {
    beforeEach(function () {
        $this->other = Workspace::factory()->create();
        $this->attachment = ($this->uploaded)('800000000000001');
        $this->strangers = fn (): PostVariant => PostVariant::query()->withoutGlobalScopes()->findOrFail($this->variant->id);
    });

    it('sends no image of another workspace', function () {
        fakeGraph();

        $attachment = MediaAttachment::query()->withoutGlobalScopes()->findOrFail($this->attachment->id);

        expect(fn () => inWorkspace($this->other, fn () => facebookAdapter()->uploadMedia(($this->strangers)(), $attachment)))
            ->toThrow(PublishFailed::class, 'not addressed to a Facebook Page');

        // Even with the Page in hand, the file of another workspace is not there to read.
        $variant = ($this->strangers)()->setRelation('channel', $this->channel);

        expect(fn () => inWorkspace($this->other, fn () => facebookAdapter()->uploadMedia($variant, $attachment)))
            ->toThrow(PublishFailed::class, 'is missing from the storage of this instance');

        Http::assertNothingSent();
    });

    it('publishes no variant of another workspace, and sees none of its images', function () {
        fakeGraph();

        expect(fn () => inWorkspace($this->other, fn () => facebookAdapter()->publish(($this->strangers)())))
            ->toThrow(PublishFailed::class, 'not addressed to a Facebook Page')
            ->and(inWorkspace($this->other, fn () => ($this->strangers)()->images()))->toBeEmpty();

        Http::assertNothingSent();
    });
});
