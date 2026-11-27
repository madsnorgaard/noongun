<?php

/*
 * Images on LinkedIn, at the adapter: sending one image (uploadMedia) and
 * creating a post that shows images (publish). The publishing job drives
 * the two; how they behave together is in
 * tests/Feature/Scheduling/PublishPostJobLinkedInImagesTest.php.
 *
 * Nothing here was recorded from LinkedIn. tests/Fixtures/linkedin/README.md
 * says which answers are documented and which are assumed.
 */

use App\Domain\Channels\Adapters\LinkedIn\UploadAddress;
use App\Domain\Channels\Contracts\MediaLinkRule;
use App\Domain\Channels\Contracts\UploadsMedia;
use App\Domain\Channels\Exceptions\PublishFailed;
use App\Domain\Channels\Models\Channel;
use App\Domain\Content\Models\MediaAttachment;
use App\Domain\Content\Models\PostVariant;
use App\Domain\Content\PostPreconditions;
use App\Domain\Workspaces\Models\Workspace;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../../Support/linkedin.php';
require_once __DIR__.'/../../../Support/media.php';

beforeEach(function () {
    configureLinkedIn();
    fakeMediaDisk();
    Http::preventStrayRequests();

    $this->workspace = Workspace::factory()->create(['timezone' => 'Africa/Johannesburg']);
    $this->channel = inWorkspace($this->workspace, fn () => Channel::factory()->platform('linkedin')->create([
        'external_id' => LINKEDIN_TEST_MEMBER,
        'display_name' => 'Thandi Mokoena',
        'access_token' => LINKEDIN_TEST_TOKEN,
        'scopes' => ['openid', 'profile', 'w_member_social'],
        'token_expires_at' => '2027-01-24 10:00:00',
    ]));

    $this->variant = fn (array $attributes = []): PostVariant => inWorkspace($this->workspace, fn () => PostVariant::factory()->for($this->channel)->create([
        'body' => 'Fresh beans arrive on Thursday.',
        ...$attributes,
    ]));

    // An image on the variant that LinkedIn already holds under the given
    // number, as the publishing job leaves it before publish().
    $this->uploaded = function (PostVariant $variant, int $number, ?string $altText = null, ?string $bytes = null): MediaAttachment {
        $attachment = attachImage($variant, storedImage($this->workspace, $bytes), $altText);

        return inWorkspace($this->workspace, fn () => $attachment->markUploaded(linkedInImageUrn($number)));
    };

    $this->upload = fn (PostVariant $variant, MediaAttachment $attachment): string => inWorkspace(
        $this->workspace,
        fn () => linkedInAdapter()->uploadMedia($variant->fresh(), $attachment->fresh()),
    );

    $this->publish = fn (PostVariant $variant) => inWorkspace($this->workspace, fn () => linkedInAdapter()->publish($variant->fresh()));

    $this->failure = function (Closure $call): PublishFailed {
        try {
            $call();
        } catch (PublishFailed $exception) {
            return $exception;
        }

        $this->fail('Expected PublishFailed.');
    };
});

describe('capabilities', function () {
    it('says what LinkedIn takes, from the settings', function () {
        $capabilities = linkedInAdapter()->capabilities();

        expect(linkedInAdapter())->toBeInstanceOf(UploadsMedia::class)
            ->and($capabilities->mediaTypes)->toBe(['image/jpeg', 'image/png'])
            ->and($capabilities->maxMediaItems)->toBe(20)
            // LinkedIn documents no size in bytes, so none is enforced.
            ->and($capabilities->maxMediaBytes)->toBeNull()
            ->and($capabilities->maxMediaPixels)->toBe(36152319)
            ->and($capabilities->altTextLength)->toBe(4086)
            ->and($capabilities->mediaLinkRule)->toBe(MediaLinkRule::InText)
            ->and($capabilities->mediaUploadLifetime)->toBe(86400)
            // A link without images is still a card the author types.
            ->and($capabilities->needsLinkCard())->toBeTrue();

        configureLinkedIn(['max_images' => 4, 'max_image_bytes' => 1000, 'max_image_pixels' => 2000, 'alt_text_length' => 120, 'image_lifetime' => 600]);

        expect(linkedInAdapter()->capabilities()->toArray())->toMatchArray([
            'max_media_items' => 4,
            'max_media_bytes' => 1000,
            'max_media_pixels' => 2000,
            'alt_text_length' => 120,
            'media_upload_lifetime' => 600,
        ]);
    });

    it('has the limits and the allowed upload hosts in the configuration', function () {
        // The file itself: configureLinkedIn() replaced what was loaded.
        expect((require config_path('noongun.php'))['channels']['linkedin'])->toMatchArray([
            'max_images' => 20,
            'max_image_bytes' => null,
            'max_image_pixels' => 36152319,
            'alt_text_length' => 4086,
            'image_lifetime' => 86400,
            'upload_hosts' => ['linkedin.com'],
        ]);
    })->skip(fn () => env('LINKEDIN_UPLOAD_HOSTS') !== null || env('LINKEDIN_MAX_IMAGES') !== null || env('LINKEDIN_MAX_IMAGE_BYTES') !== null, 'The environment sets its own values.');

    it('holds no image back for its size in bytes unless the instance sets a limit', function () {
        $variant = ($this->variant)();
        attachImage($variant, storedImage($this->workspace, attributes: ['byte_size' => 9_500_000]));

        $problems = fn (): array => inWorkspace($this->workspace, fn () => app(PostPreconditions::class)->mediaProblems(
            $variant->fresh(),
            $this->channel,
            linkedInAdapter()->capabilities(),
        ));

        expect($problems())->toBe([]);

        configureLinkedIn(['max_image_bytes' => '8000000']);

        expect(json_encode($problems()))->toContain('Noon Gun sends 8 MB at most to LinkedIn for one image');
    });
});

describe('uploadMedia', function () {
    it('asks LinkedIn where the image goes, sends the bytes there and returns the URN', function () {
        $bytes = jpegBytes(60, 40);
        $sent = [];
        fakeLinkedIn(['upload' => function (Request $request) use (&$sent) {
            // Read here: the stream is closed when the adapter returns.
            $sent = ['body' => $request->body(), 'url' => $request->url()];

            return Http::response('', 201);
        }]);

        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace, $bytes), 'Sacks of beans on a pallet.');

        expect(($this->upload)($variant, $attachment))->toBe(linkedInImageUrn(1));

        [[$initialise], [$upload]] = Http::recorded()->all();

        expect(linkedInCalls())->toBe([
            'POST '.LINKEDIN_TEST_API.'/rest/images',
            'PUT '.LINKEDIN_TEST_UPLOADS.'/C4E10AQTestImage1/uploaded-image/0',
        ])
            ->and($initialise->url())->toBe(LINKEDIN_TEST_API.'/rest/images?action=initializeUpload')
            ->and($initialise->data())->toBe(['initializeUploadRequest' => ['owner' => 'urn:li:person:'.LINKEDIN_TEST_MEMBER]])
            ->and(linkedInHeader($initialise, 'Authorization'))->toBe('Bearer '.LINKEDIN_TEST_TOKEN)
            ->and(linkedInHeader($initialise, 'Linkedin-Version'))->toBe('209901')
            ->and(linkedInHeader($initialise, 'X-Restli-Protocol-Version'))->toBe('2.0.0')
            // The address is the one LinkedIn named, query and all.
            ->and($sent['url'])->toBe(linkedInUploadUrl(1))
            ->and($sent['body'])->toBe($bytes)
            // The documentation asks for the token on the upload of an image.
            ->and(linkedInHeader($upload, 'Authorization'))->toBe('Bearer '.LINKEDIN_TEST_TOKEN)
            ->and(linkedInHeader($upload, 'Content-Length'))->toBe((string) strlen($bytes))
            ->and(linkedInHeader($upload, 'Content-Type'))->toBe('application/octet-stream');

        // The adapter writes nothing: the job records the upload.
        expect(DB::table('post_variant_media')->where('id', $attachment->id)->value('external_id'))->toBeNull();
    });

    it('sends a PNG as it is stored', function () {
        $bytes = pngBytes();
        $body = null;
        fakeLinkedIn(['upload' => function (Request $request) use (&$body) {
            $body = $request->body();

            return Http::response('', 201);
        }]);

        $variant = ($this->variant)();

        expect(($this->upload)($variant, attachImage($variant, storedImage($this->workspace, $bytes))))->toBe(linkedInImageUrn(1))
            ->and($body)->toBe($bytes);
    });

    it('reads the file as a stream, so that it works on object storage', function () {
        remoteMediaDisk();
        $bytes = jpegBytes(80, 50);
        $body = null;
        fakeLinkedIn(['upload' => function (Request $request) use (&$body) {
            $body = $request->body();

            return Http::response('', 201);
        }]);

        $variant = ($this->variant)();

        expect(($this->upload)($variant, attachImage($variant, storedImage($this->workspace, $bytes))))->toBe(linkedInImageUrn(1))
            ->and($body)->toBe($bytes);
    });

    it('accepts any answer of the 2xx kind to the upload', function (int $status) {
        fakeLinkedIn(['upload' => Http::response('', $status)]);
        $variant = ($this->variant)();

        expect(($this->upload)($variant, attachImage($variant, storedImage($this->workspace))))->toBe(linkedInImageUrn(1));
    })->with([200, 201, 204]);

    it('sends neither the image nor the token to an address that is not a plain HTTPS address on an allowed host', function (mixed $address, string $reason) {
        fakeLinkedIn(['images' => fn () => linkedInUploadInitialised(1, $address)]);

        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace));

        $exception = ($this->failure)(fn () => ($this->upload)($variant, $attachment));
        $everything = $exception->getMessage().json_encode($exception->context());

        // Only the call that asked for the address. A call to any other
        // host would have failed the test in fakeLinkedIn().
        expect(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/images'])
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->getMessage())->toContain('Nothing was sent to it and nothing was posted.')->toContain('LINKEDIN_UPLOAD_HOSTS')
            ->and($exception->context())->toMatchArray(['refused_because' => $reason])
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($everything)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_UPLOAD_SIGNATURE);
    })->with([
        'plain HTTP' => ['http://www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'another scheme' => ['ftp://www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'no scheme' => ['//www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'a scheme in capitals' => ['HTTPS://www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'another host' => ['https://uploads.example.test/dms-uploads/1?ut='.LINKEDIN_TEST_UPLOAD_SIGNATURE, 'host_not_allowed'],
        'the real LinkedIn, which this instance does not allow' => ['https://www.linkedin.com/dms-uploads/1', 'host_not_allowed'],
        'an allowed name as the start of another host' => ['https://www.linkedin.test.evil.example/dms-uploads/1', 'host_not_allowed'],
        'an allowed name as the end of another name' => ['https://evillinkedin.test/dms-uploads/1', 'host_not_allowed'],
        'a host that ends in a dot' => ['https://www.linkedin.test./dms-uploads/1', 'not_a_host_name'],
        'a host that starts with a dot' => ['https://.linkedin.test/dms-uploads/1', 'not_a_host_name'],
        'a host with an empty part' => ['https://a..linkedin.test/dms-uploads/1', 'not_a_host_name'],
        'a host with a part that starts with a hyphen' => ['https://-a.linkedin.test/dms-uploads/1', 'not_a_host_name'],
        'a host of one part' => ['https://test/dms-uploads/1', 'not_a_host_name'],
        'a query straight after the host' => ['https://www.linkedin.test?@evil.example/', 'not_a_plain_https_address'],
        'a query straight after the port' => ['https://www.linkedin.test:443?@evil.example/', 'not_a_plain_https_address'],
        'no path at all' => ['https://www.linkedin.test', 'not_a_plain_https_address'],
        'an allowed name as the user name' => ['https://www.linkedin.test@evil.example/dms-uploads/1', 'not_a_plain_https_address'],
        'a user name and a password' => ['https://user:secret@www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'an empty user name' => ['https://@www.linkedin.test/dms-uploads/1', 'not_a_plain_https_address'],
        'another port' => ['https://www.linkedin.test:8443/dms-uploads/1', 'port'],
        'port 80' => ['https://www.linkedin.test:80/dms-uploads/1', 'port'],
        'an empty port' => ['https://www.linkedin.test:/dms-uploads/1', 'not_a_plain_https_address'],
        'an IPv4 address' => ['https://203.0.113.7/dms-uploads/1', 'ip_address'],
        'an IPv4 address written as one number' => ['https://3405803783/dms-uploads/1', 'ip_address'],
        'an IPv6 address' => ['https://[2001:db8::7]/dms-uploads/1', 'not_a_plain_https_address'],
        'a fragment' => ['https://www.linkedin.test/dms-uploads/1#@evil.example', 'backslash_or_fragment'],
        'a fragment in place of the path' => ['https://evil.example#.linkedin.test/dms-uploads/1', 'backslash_or_fragment'],
        'a backslash before an at sign' => ['https://evil.example\\@www.linkedin.test/dms-uploads/1', 'backslash_or_fragment'],
        'a backslash in the host' => ['https://www.linkedin.test\\.evil.example/dms-uploads/1', 'backslash_or_fragment'],
        'a backslash in the path' => ['https://www.linkedin.test/dms-uploads\\1', 'backslash_or_fragment'],
        'an encoded dot in the host' => ['https://www.linkedin%2etest/dms-uploads/1', 'not_a_plain_https_address'],
        'a host that is not ASCII' => ['https://www.linkedín.test/dms-uploads/1', 'not_a_plain_https_address'],
        'a space in the address' => ['https://www.linkedin.test/dms uploads/1', 'not_a_plain_https_address'],
        'a line break after the address' => ["https://www.linkedin.test/dms-uploads/1\n", 'not_a_plain_https_address'],
        'a line break and a header' => ["https://www.linkedin.test/dms-uploads/1\r\nHost: evil.example", 'not_a_plain_https_address'],
        'a tab in the host' => ["https://www.linkedin.test\t.evil.example/dms-uploads/1", 'not_a_plain_https_address'],
        'no address' => ['', 'missing'],
        'an address that is not text' => [['https://www.linkedin.test/dms-uploads/1'], 'missing'],
        'an address that is too long' => ['https://www.linkedin.test/dms-uploads/'.str_repeat('a', 5000), 'too_long'],
    ]);

    it('accepts an address on an allowed host or below one', function (string $address) {
        expect(UploadAddress::from($address, ['linkedin.test'])->allowed())->toBeTrue();
    })->with([
        'https://www.linkedin.test/dms-uploads/C4E10/uploaded-image/0?ca=vector_ads&ut=1',
        'https://api.linkedin.test/mediaUpload/C5522/feedshare-uploadedImage/0?ca=vector_feedshare&m=AQJ&app=1953784',
        'https://linkedin.test/x',
        'https://www.linkedin.test:443/dms-uploads/1',
        'https://WWW.LinkedIn.Test/dms-uploads/1',
        'https://a.b.linkedin.test/',
        'https://www.linkedin.test/dms-uploads/1?next=https://www.linkedin.test/@x',
    ]);

    it('allows nothing when the list of hosts is empty or holds something that is not a host name of two parts', function (array $hosts) {
        // Every address, also one that another entry of the list allows.
        $address = UploadAddress::from('https://www.linkedin.test/x', $hosts);

        expect($address->allowed())->toBeFalse()
            ->and($address->refusal)->toBe('allow_list_invalid')
            ->and(UploadAddress::usable($hosts))->toBeFalse();
    })->with([
        'empty' => [[]],
        'a top level name, which would allow every host below it' => [['test']],
        'a top level name next to a good one' => [['linkedin.test', 'com']],
        'an empty name next to a good one' => [['linkedin.test', '']],
        'a name with a dot in front' => [['.linkedin.test']],
        'a name with a dot behind' => [['linkedin.test.']],
        'a pattern' => [['*.linkedin.test']],
        'an address' => [['https://www.linkedin.test']],
        'a name with a port' => [['linkedin.test:443']],
        'something that is not text' => [[['linkedin.test']]],
    ]);

    it('reads the names of the list whatever their capitals and the white space around them', function () {
        expect(UploadAddress::from('https://www.linkedin.test/x', [' LinkedIn.Test '])->allowed())->toBeTrue()
            ->and(UploadAddress::from('https://www.linkedin.test/x', ['example.test', 'linkedin.test'])->host)->toBe('www.linkedin.test');
    });

    it('sends nothing at all when the list of upload hosts cannot be used, and says which setting is wrong', function (array $hosts) {
        configureLinkedIn(['upload_hosts' => $hosts]);
        fakeLinkedIn();
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));

        Http::assertNothingSent();
        expect($exception->retryable)->toBeFalse()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->getMessage())->toContain('LINKEDIN_UPLOAD_HOSTS')->toContain('No image was sent and nothing was posted.');
    })->with([
        'empty' => [[]],
        'a top level name' => [['test']],
        'a top level name next to a good one' => [['linkedin.test', 'com']],
    ]);

    it('does not follow a redirect of the upload, and treats it as a failure that may be tried again', function () {
        fakeLinkedIn(['upload' => Http::response('', 307, ['Location' => 'https://evil.example/collect'])]);
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));

        expect($exception->retryable)->toBeTrue()
            ->and(Http::recorded())->toHaveCount(2)
            ->and(Http::recorded()->last()[0]->toPsrRequest()->getUri()->getHost())->toBe('www.linkedin.test');
    });

    it('fails in a way that may be tried again when initialising did not work for the moment', function (Closure $response) {
        fakeLinkedIn(['images' => $response]);
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));

        // Unlike a publish: nothing public was attempted, so even a
        // timeout and a server error are retryable.
        expect($exception->retryable)->toBeTrue()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($exception->getMessage())->not->toContain('The outcome is unknown')
            ->and($exception->getPrevious())->toBeNull()
            ->and($exception->getMessage().json_encode($exception->context()))->not->toContain(LINKEDIN_TEST_TOKEN);

        Http::assertNotSent(fn (Request $request) => $request->method() === 'PUT');
    })->with([
        ...linkedInAmbiguousFailures(),
        'throttled' => [fn () => linkedInResponse('error_rate_limit', 429)],
        'connection never made' => [fn (Request $request) => throw linkedInConnectionFailure($request, ConnectException::class, 'Could not resolve host: api.linkedin.test')],
        'an answer without a URN' => [fn () => Http::response(['value' => ['uploadUrl' => linkedInUploadUrl(1)]])],
        'an answer with something else than an image URN' => [fn () => Http::response(['value' => ['uploadUrl' => linkedInUploadUrl(1), 'image' => 'urn:li:image:1/../../posts']])],
        'an answer with a video URN' => [fn () => Http::response(['value' => ['uploadUrl' => linkedInUploadUrl(1), 'image' => 'urn:li:video:C4E10AQTest']])],
        'an empty answer' => [fn () => Http::response('', 200)],
    ]);

    it('asks for a reconnect when LinkedIn does not accept the token for images', function (string $fixture, int $status) {
        fakeLinkedIn(['images' => linkedInResponse($fixture, $status)]);
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));

        expect($exception->requiresReconnect)->toBeTrue()
            ->and($exception->retryable)->toBeFalse()
            ->and(Http::recorded())->toHaveCount(1);
    })->with([
        'expired' => ['error_token_expired', 401],
        'scope missing' => ['error_permission', 403],
        'the documented answer of the Images API' => ['error_image_forbidden', 403],
    ]);

    it('fails for good when LinkedIn refuses to initialise the upload', function () {
        fakeLinkedIn(['images' => linkedInResponse('error_validation', 422)]);
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));

        expect($exception->retryable)->toBeFalse()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->getMessage())->toContain('LinkedIn did not accept an image of this post. Nothing was posted.')
            ->and($exception->context())->toMatchArray(['endpoint' => 'POST /rest/images?action=initializeUpload', 'http_status' => 422]);
    });

    it('classifies what the upload address answers', function (Closure $response, bool $retryable, bool $reconnect) {
        fakeLinkedIn(['upload' => $response]);
        $variant = ($this->variant)();

        $exception = ($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))));
        $everything = $exception->getMessage().json_encode($exception->context()).$exception->getTraceAsString();

        expect($exception->retryable)->toBe($retryable)
            ->and($exception->requiresReconnect)->toBe($reconnect)
            ->and($exception->context())->toMatchArray(['endpoint' => 'PUT upload address', 'upload_host' => 'www.linkedin.test'])
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($exception->getMessage())->not->toContain('The outcome is unknown')
            ->and($exception->getPrevious())->toBeNull()
            ->and($everything)->not->toContain(LINKEDIN_TEST_TOKEN)->not->toContain(LINKEDIN_TEST_UPLOAD_SIGNATURE);
    })->with([
        'token no longer accepted' => [fn () => Http::response('', 401), false, true],
        'bad request' => [fn () => Http::response('', 400), false, false],
        'file too large' => [fn () => Http::response('', 413), false, false],
        'type not supported' => [fn () => Http::response('', 415), false, false],
        'file cannot be processed' => [fn () => Http::response(['message' => 'Bad image for Bearer '.LINKEDIN_TEST_TOKEN], 422), false, false],
        // The address may have run out: the next attempt gets a new one.
        'forbidden' => [fn () => Http::response('', 403), true, false],
        'not found' => [fn () => Http::response('', 404), true, false],
        'conflict' => [fn () => Http::response('', 409), true, false],
        'throttled' => [fn () => Http::response('', 429, ['Retry-After' => '30']), true, false],
        'server error' => [fn () => Http::response('<html>Oops</html>', 500), true, false],
        'unavailable' => [fn () => Http::response('', 503), true, false],
        'timeout' => [fn (Request $request) => throw linkedInConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 45001 milliseconds'), true, false],
        'connection never made' => [fn (Request $request) => throw linkedInConnectionFailure($request, ConnectException::class, 'Could not resolve host: www.linkedin.test'), true, false],
    ]);

    it('names the image and its size when LinkedIn finds the file too large, and says what to do', function () {
        $puts = 0;
        fakeLinkedIn(['upload' => function () use (&$puts) {
            return Http::response('', ++$puts === 2 ? 413 : 201);
        }]);
        $variant = ($this->variant)();
        $first = attachImage($variant, storedImage($this->workspace));
        $second = attachImage($variant, storedImage($this->workspace, attributes: ['byte_size' => 9_961_472]));

        ($this->upload)($variant, $first);
        $exception = ($this->failure)(fn () => ($this->upload)($variant, $second));

        expect($exception->getMessage())->toBe('Image 2 of this post is too large for LinkedIn (9.5 MB). Nothing was posted. Return the post to draft, take the image off and put a smaller copy of it on.')
            ->and($exception->retryable)->toBeFalse()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->context())->toMatchArray(['http_status' => 413, 'image_number' => 2, 'image_bytes' => 9_961_472])
            ->and($exception->context())->not->toHaveKey('outcome_unknown');
    });

    it('names the image when LinkedIn refuses the file for another reason', function (int $status) {
        fakeLinkedIn(['upload' => Http::response('', $status)]);
        $variant = ($this->variant)();

        expect(($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))))->getMessage())
            ->toBe('LinkedIn did not accept image 1 of this post. Nothing was posted. Return the post to draft, take the image off and put another copy of it on.');
    })->with([400, 415, 422]);

    it('passes on how long LinkedIn asks to wait', function () {
        fakeLinkedIn(['upload' => Http::response('', 429, ['Retry-After' => '45'])]);
        $variant = ($this->variant)();

        expect(($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))))->retryAfterSeconds)->toBe(45);
    });

    it('keeps both calls within the time one image may take, and follows no redirect', function () {
        config(['noongun.publishing.media_timeout' => 45, 'noongun.publishing.timeout' => 30]);
        configureLinkedIn();
        $options = [];
        $record = function (Closure $answer) use (&$options): Closure {
            return function (Request $request, array $sentWith) use (&$options, $answer) {
                $options[$request->method()] = $sentWith;

                return $answer();
            };
        };
        fakeLinkedIn([
            'images' => $record(fn () => linkedInUploadInitialised(1)),
            'upload' => $record(fn () => Http::response('', 201)),
        ]);
        $variant = ($this->variant)();

        ($this->upload)($variant, attachImage($variant, storedImage($this->workspace)));

        expect($options['POST']['timeout'])->toBe(15)
            ->and($options['POST']['allow_redirects'])->toBeFalse()
            ->and($options['PUT']['timeout'])->toBeGreaterThanOrEqual(40)->toBeLessThanOrEqual(45)
            ->and($options['PUT']['allow_redirects'])->toBeFalse();
    });

    it('sends nothing for an image of a type LinkedIn does not take', function () {
        fakeLinkedIn();
        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace, attributes: ['mime' => 'image/webp']));

        $exception = ($this->failure)(fn () => ($this->upload)($variant, $attachment));

        Http::assertNothingSent();
        expect($exception->retryable)->toBeFalse()
            ->and($exception->getMessage())->toContain('JPEG and PNG');
    });

    it('sends nothing when the channel holds no token or is not a LinkedIn profile', function () {
        fakeLinkedIn();
        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace));

        $elsewhere = inWorkspace($this->workspace, fn () => PostVariant::factory()->create());
        $onFacebook = attachImage($elsewhere, storedImage($this->workspace));

        expect(($this->failure)(fn () => ($this->upload)($elsewhere, $onFacebook))->getMessage())->toBe('This post is not addressed to a LinkedIn profile.');

        inWorkspace($this->workspace, fn () => $this->channel->disconnect());

        expect(($this->failure)(fn () => ($this->upload)($variant, $attachment))->requiresReconnect)->toBeTrue();

        Http::assertNothingSent();
    });

    it('sends nothing when LinkedIn is not configured with HTTPS addresses', function () {
        configureLinkedIn(['api_url' => 'http://api.linkedin.test']);
        fakeLinkedIn();
        $variant = ($this->variant)();

        expect(($this->failure)(fn () => ($this->upload)($variant, attachImage($variant, storedImage($this->workspace))))->getMessage())->toContain('LINKEDIN_API_URL');

        Http::assertNothingSent();
    });

    it('cannot send the image of another workspace, or with the token of another workspace', function () {
        fakeLinkedIn();
        $variant = ($this->variant)();
        $attachment = attachImage($variant, storedImage($this->workspace));

        $other = Workspace::factory()->create();

        // Read from inside another workspace there is no such variant and
        // no such attachment.
        expect(inWorkspace($other, fn () => PostVariant::query()->find($variant->id)))->toBeNull()
            ->and(inWorkspace($other, fn () => MediaAttachment::query()->find($attachment->id)))->toBeNull();

        // Handed over anyway, the variant has no channel there and the
        // attachment no image, so no token is found and no file is read.
        $exception = ($this->failure)(fn () => inWorkspace($other, fn () => linkedInAdapter()->uploadMedia(
            PostVariant::query()->withoutGlobalScopes()->findOrFail($variant->id),
            MediaAttachment::query()->withoutGlobalScopes()->findOrFail($attachment->id),
        )));

        expect($exception->getMessage())->toBe('This post is not addressed to a LinkedIn profile.');
        Http::assertNothingSent();
    });
});

describe('publish with images', function () {
    it('creates a post with one image and its alternative text', function () {
        fakeLinkedIn();
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1, 'Sacks of beans on a pallet.');

        $result = ($this->publish)($variant);

        expect($result->externalId)->toBe(LINKEDIN_TEST_POST)
            // publish() uploads nothing: one call, the one that creates the post.
            ->and(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/posts'])
            ->and(Http::recorded()->first()[0]->data())->toBe([
                'author' => 'urn:li:person:'.LINKEDIN_TEST_MEMBER,
                'commentary' => 'Fresh beans arrive on Thursday.',
                'visibility' => 'PUBLIC',
                'distribution' => [
                    'feedDistribution' => 'MAIN_FEED',
                    'targetEntities' => [],
                    'thirdPartyDistributionChannels' => [],
                ],
                'lifecycleState' => 'PUBLISHED',
                'isReshareDisabledByAuthor' => false,
                'content' => ['media' => ['id' => linkedInImageUrn(1), 'altText' => 'Sacks of beans on a pallet.']],
            ]);
    });

    it('leaves the alternative text out when the image has none', function (?string $altText) {
        fakeLinkedIn();
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1, $altText);

        ($this->publish)($variant);

        expect(Http::recorded()->first()[0]['content'])->toBe(['media' => ['id' => linkedInImageUrn(1)]]);
    })->with([null, '', '   ']);

    it('creates a post with several images in their order, each with its own text', function () {
        fakeLinkedIn();
        $variant = ($this->variant)();
        // Uploaded as 3, 1 and 2: the order is that of the attachments,
        // not that of the names LinkedIn gave.
        ($this->uploaded)($variant, 3, 'The roaster.');
        ($this->uploaded)($variant, 1);
        ($this->uploaded)($variant, 2, 'Two cups on the counter.', pngBytes());

        ($this->publish)($variant);

        expect(linkedInCalls())->toBe(['POST '.LINKEDIN_TEST_API.'/rest/posts'])
            ->and(Http::recorded()->first()[0]['content'])->toBe(['multiImage' => ['images' => [
                ['id' => linkedInImageUrn(3), 'altText' => 'The roaster.'],
                ['id' => linkedInImageUrn(1)],
                ['id' => linkedInImageUrn(2), 'altText' => 'Two cups on the counter.'],
            ]]]);
    });

    it('sends the link of a post with images as the last line of the text and no card', function () {
        fakeLinkedIn();
        $variant = ($this->variant)([
            'body' => 'Fresh beans (washed) arrive on Thursday.',
            'link' => 'https://example.com/beans',
            'utm' => ['utm_source' => 'linkedin', 'utm_medium' => 'social'],
            // The card that would be sent without the image.
            'link_title' => 'Beans this week',
            'link_description' => 'What is in the roaster.',
        ]);
        ($this->uploaded)($variant, 1, 'Sacks of beans.');

        ($this->publish)($variant);

        $sent = Http::recorded()->first()[0];

        // The text is the one the readiness check measured, escaped as
        // every text is. The underscores of the address are syntax too.
        expect($sent['commentary'])->toBe("Fresh beans \\(washed\\) arrive on Thursday.\n\nhttps://example.com/beans?utm\\_source=linkedin&utm\\_medium=social")
            ->and($sent['content'])->toBe(['media' => ['id' => linkedInImageUrn(1), 'altText' => 'Sacks of beans.']])
            ->and($sent['content'])->not->toHaveKey('article');
    });

    it('does not ask for a link title when the link goes into the text', function () {
        fakeLinkedIn();
        $variant = ($this->variant)(['link' => 'https://example.com/beans']);
        ($this->uploaded)($variant, 1);
        ($this->uploaded)($variant, 2);

        ($this->publish)($variant);

        $sent = Http::recorded()->first()[0];

        expect($sent['commentary'])->toBe("Fresh beans arrive on Thursday.\n\nhttps://example.com/beans")
            ->and(array_keys($sent['content']))->toBe(['multiImage']);
    });

    it('still sends a link without images as a card, and a text without either as text', function () {
        fakeLinkedIn();

        ($this->publish)(($this->variant)(['link' => 'https://example.com/beans', 'link_title' => 'Beans this week']));
        ($this->publish)(($this->variant)());

        [[$card], [$text]] = Http::recorded()->all();

        expect($card['commentary'])->toBe('Fresh beans arrive on Thursday.')
            ->and($card['content'])->toBe(['article' => ['source' => 'https://example.com/beans', 'title' => 'Beans this week']])
            ->and($text->data())->not->toHaveKey('content');
    });

    it('sends nothing when an image has no name from LinkedIn', function (?string $externalId) {
        fakeLinkedIn();
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);
        $second = attachImage($variant, storedImage($this->workspace));

        if ($externalId !== null) {
            inWorkspace($this->workspace, fn () => $second->markUploaded($externalId));
        }

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        Http::assertNothingSent();
        expect($exception->retryable)->toBeFalse()
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($exception->getMessage())->toBe('An image of this post has not been sent to LinkedIn. Nothing was posted. Send the post again.');
    })->with([
        'never uploaded' => [null],
        'the id of another platform' => ['122100000000000001'],
        'a URN of another kind' => ['urn:li:video:C4E10AQTest'],
        'a URN with something after it' => ["urn:li:image:C4E10AQTest\n\"}"],
    ]);

    it('sends nothing when the post has more images than LinkedIn takes', function () {
        configureLinkedIn(['max_images' => 2]);
        fakeLinkedIn();
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);
        ($this->uploaded)($variant, 2);
        ($this->uploaded)($variant, 3);

        expect(($this->failure)(fn () => ($this->publish)($variant))->getMessage())->toContain('more images than LinkedIn takes');
        Http::assertNothingSent();
    });

    it('may be tried again when LinkedIn refuses the post because an image is not ready yet', function (Closure $response) {
        fakeLinkedIn(['posts' => $response]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        expect($exception->retryable)->toBeTrue()
            ->and($exception->requiresReconnect)->toBeFalse()
            ->and($exception->getMessage())->toBe('LinkedIn is still preparing an image of this post. Nothing was posted.')
            // Never to be mistaken for a post that may exist.
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($exception->context())->toMatchArray(['image_not_ready' => true, 'retryable' => true, 'endpoint' => 'POST /rest/posts']);
    })->with([
        'the image is still processing' => [fn () => linkedInResponse('error_image_not_ready', 422)],
        'the upload has not arrived' => [fn () => Http::response(['status' => 400, 'code' => 'MEDIA_ASSET_WAITING_UPLOAD', 'message' => 'Media asset is waiting to be uploaded'], 400)],
        'the image is not available' => [fn () => Http::response(['status' => 404, 'message' => 'urn:li:image:C4E10AQTestImage1 is not available'], 404)],
    ]);

    it('does not read a refusal as "not ready" unless it certainly is one', function (Closure $response, bool $outcomeUnknown) {
        fakeLinkedIn(['posts' => $response]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        expect($exception->retryable)->toBeFalse()
            ->and($exception->context())->not->toHaveKey('image_not_ready')
            ->and($exception->context()['outcome_unknown'] ?? false)->toBe($outcomeUnknown);
    })->with([
        'processing failed' => [fn () => linkedInResponse('error_image_processing_failed', 422), false],
        'the image is not known' => [fn () => linkedInResponse('error_image_invalid', 400), false],
        'a refusal that is about the text' => [fn () => linkedInResponse('error_field_too_long', 400), false],
        'a refusal that speaks of processing but not of an image' => [fn () => Http::response(['status' => 422, 'message' => 'Error processing request'], 422), false],
        // Whatever these say, LinkedIn may have made the post.
        'a server error that speaks of processing an image' => [fn () => Http::response(['status' => 500, 'message' => 'Media asset is still processing'], 500), true],
        'a write conflict that speaks of processing an image' => [fn () => Http::response(['status' => 409, 'message' => 'Media asset is still processing'], 409), true],
        'words in a page that is not from LinkedIn' => [fn () => Http::response('<html>The image is still processing</html>', 422), true],
    ]);

    it('asks for the images to be sent again when LinkedIn no longer has one that was sent earlier', function (Closure $response) {
        fakeLinkedIn(['posts' => $response]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);
        ($this->uploaded)($variant, 2);

        $this->travel(2)->hours();

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        expect($exception->invalidatesUploads())->toBeTrue()
            ->and($exception->retryable)->toBeTrue()
            ->and($exception->context())->not->toHaveKey('outcome_unknown')
            ->and($exception->getMessage())->toBe('LinkedIn no longer has an image that was sent for this post. Nothing was posted. The images are sent again.');
    })->with([
        'the image is not known' => [fn () => linkedInResponse('error_image_invalid', 400)],
        'the image is not found' => [fn () => Http::response(['status' => 404, 'code' => 'NOT_FOUND', 'message' => 'Could not find entity urn:li:image:C4E10AQTestImage1'], 404)],
        'processing failed' => [fn () => linkedInResponse('error_image_processing_failed', 422)],
    ]);

    it('does not send images again for a failure that is not a certain refusal about them', function (Closure $response) {
        fakeLinkedIn(['posts' => $response]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        $this->travel(2)->hours();

        expect(($this->failure)(fn () => ($this->publish)($variant))->invalidatesUploads())->toBeFalse();
    })->with([
        'a refusal that is about the text' => [fn () => linkedInResponse('error_field_too_long', 400)],
        'a server error that says the image is invalid' => [fn () => Http::response(['status' => 500, 'message' => 'This Image ID is invalid'], 500)],
        'the token' => [fn () => linkedInResponse('error_image_forbidden', 403)],
        'throttled' => [fn () => linkedInResponse('error_rate_limit', 429)],
    ]);

    it('does not blame images that were sent a moment ago', function () {
        fakeLinkedIn(['posts' => linkedInResponse('error_image_invalid', 400)]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        $this->travel(5)->minutes();

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        expect($exception->invalidatesUploads())->toBeFalse()
            ->and($exception->retryable)->toBeFalse();
    });

    it('says what to do with the images when LinkedIn refuses the post', function () {
        fakeLinkedIn(['posts' => linkedInResponse('error_image_invalid', 400)]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        expect(($this->failure)(fn () => ($this->publish)($variant))->getMessage())
            ->toContain('take the images off and put them on again');
    });

    it('never marks a failure as retryable when a post with images may have been made', function (Closure $response) {
        fakeLinkedIn(['posts' => $response]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);
        ($this->uploaded)($variant, 2);

        $exception = ($this->failure)(fn () => ($this->publish)($variant));

        expect($exception->retryable)->toBeFalse()
            ->and($exception->context())->toMatchArray(['outcome_unknown' => true])
            ->and($exception->getMessage())->toContain('Look at the profile.');
    })->with(linkedInAmbiguousFailures());

    it('asks for a reconnect when LinkedIn says the image may not be used', function () {
        fakeLinkedIn(['posts' => linkedInResponse('error_image_forbidden', 403)]);
        $variant = ($this->variant)();
        ($this->uploaded)($variant, 1);

        expect(($this->failure)(fn () => ($this->publish)($variant))->requiresReconnect)->toBeTrue();
    });
});
