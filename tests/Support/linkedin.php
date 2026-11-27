<?php

use App\Domain\Channels\Adapters\LinkedIn\LinkedInAdapter;
use App\Domain\Channels\ChannelManager;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for tests of the LinkedIn adapter and its connect flow. Loaded by
 * the tests that need them with require_once.
 *
 * The values are deliberately unlike the defaults in config/noongun.php, so a
 * test fails if the adapter has a version or an address of its own.
 */
const LINKEDIN_TEST_API = 'https://api.linkedin.test';

const LINKEDIN_TEST_WEB = 'https://www.linkedin.test';

const LINKEDIN_TEST_SECRET = 'test-client-secret';

const LINKEDIN_TEST_TOKEN = 'test-linkedin-access-token';

const LINKEDIN_TEST_MEMBER = 'aB3dE_fG-9';

const LINKEDIN_TEST_POST = 'urn:li:share:7300000000000000001';

/** Where the faked LinkedIn takes the bytes of an image. Below the one host configureLinkedIn() allows. */
const LINKEDIN_TEST_UPLOADS = 'https://www.linkedin.test/dms-uploads';

/** In every upload address the fake gives out. It is a credential of sorts and must reach no log. */
const LINKEDIN_TEST_UPLOAD_SIGNATURE = 'test-upload-signature';

/**
 * @param  array<string, mixed>  $overrides
 */
function configureLinkedIn(array $overrides = []): void
{
    config([
        'app.url' => 'https://noongun.test',
        'noongun.channels.linkedin' => [
            'adapter' => LinkedInAdapter::class,
            'client_id' => 'test-client-id',
            'client_secret' => LINKEDIN_TEST_SECRET,
            'api_version' => '209901',
            'api_url' => LINKEDIN_TEST_API,
            'web_url' => LINKEDIN_TEST_WEB,
            'scopes' => ['openid', 'profile', 'w_member_social'],
            'publish_scope' => 'w_member_social',
            'upload_hosts' => ['linkedin.test'],
            ...$overrides,
        ],
    ]);

    app(ChannelManager::class)->forgetAdapters();
}

function linkedInAdapter(): LinkedInAdapter
{
    $adapter = app(ChannelManager::class)->adapter('linkedin');

    expect($adapter)->toBeInstanceOf(LinkedInAdapter::class);

    /** @var LinkedInAdapter $adapter */
    return $adapter;
}

/**
 * @return array<string, mixed>
 */
function linkedInFixture(string $name): array
{
    return json_decode(
        (string) file_get_contents(__DIR__."/../Fixtures/linkedin/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  array<string, string>  $headers
 */
function linkedInResponse(string $fixture, int $status = 200, array $headers = []): PromiseInterface
{
    return Http::response(linkedInFixture($fixture), $status, $headers);
}

/**
 * What LinkedIn answers when a post is created: 201, no body, and the URN of
 * the post in the x-restli-id header.
 */
function linkedInCreated(string $urn = LINKEDIN_TEST_POST): PromiseInterface
{
    return Http::response('', 201, ['x-restli-id' => $urn]);
}

/**
 * What LinkedIn calls the nth image the fake takes.
 */
function linkedInImageUrn(int $number = 1): string
{
    return 'urn:li:image:C4E10AQTestImage'.$number;
}

/**
 * The address the fake names for the bytes of the nth image.
 */
function linkedInUploadUrl(int $number = 1): string
{
    return LINKEDIN_TEST_UPLOADS.'/C4E10AQTestImage'.$number.'/uploaded-image/0?ca=vector_ads&cn=uploads&sync=0&v=beta&ut='.LINKEDIN_TEST_UPLOAD_SIGNATURE;
}

/**
 * What LinkedIn answers when an upload is initialised: the URN of the image
 * and the address its bytes go to. Pass an address to say something else
 * than LinkedIn would.
 */
function linkedInUploadInitialised(int $number = 1, mixed $uploadUrl = null): PromiseInterface
{
    $answer = linkedInFixture('image_initialize_upload');
    $answer['value']['image'] = linkedInImageUrn($number);
    $answer['value']['uploadUrl'] = $uploadUrl ?? linkedInUploadUrl($number);

    return Http::response($answer);
}

/**
 * Fakes LinkedIn. Every call must go to one of the two configured addresses,
 * or with PUT to the address for uploads, and anything else fails the test.
 * Each endpoint answers with its success unless replaced:
 *
 *     fakeLinkedIn(['posts' => linkedInResponse('error_validation', 422)]);
 *
 * Keys: token, userinfo, introspect, posts, delete, images (initialising an
 * upload) and upload (the bytes). A value is a fixture name, a response, or
 * a closure that gets the request and the options it is sent with (timeout,
 * allow_redirects). Left alone, images numbers the uploads
 * from 1: linkedInImageUrn(1), linkedInImageUrn(2) and so on.
 *
 * @param  array<string, mixed>  $responses
 */
function fakeLinkedIn(array $responses = []): void
{
    $initialised = 0;

    $responses += [
        'token' => 'oauth_token',
        'userinfo' => 'userinfo',
        'introspect' => 'introspect_active',
        'posts' => fn () => linkedInCreated(),
        'delete' => fn () => Http::response('', 204),
        'images' => function () use (&$initialised) {
            return linkedInUploadInitialised(++$initialised);
        },
        'upload' => fn () => Http::response('', 201),
    ];

    Http::preventStrayRequests();

    Http::fake(function (Request $request, array $options = []) use ($responses) {
        $url = strtok($request->url(), '?');
        $method = $request->method();

        $key = match (true) {
            $method === 'POST' && $url === LINKEDIN_TEST_WEB.'/oauth/v2/accessToken' => 'token',
            $method === 'POST' && $url === LINKEDIN_TEST_WEB.'/oauth/v2/introspectToken' => 'introspect',
            $method === 'GET' && $url === LINKEDIN_TEST_API.'/v2/userinfo' => 'userinfo',
            $method === 'POST' && $url === LINKEDIN_TEST_API.'/rest/posts' => 'posts',
            $method === 'POST' && $request->url() === LINKEDIN_TEST_API.'/rest/images?action=initializeUpload' => 'images',
            $method === 'PUT' && str_starts_with((string) $url, LINKEDIN_TEST_UPLOADS.'/') => 'upload',
            $method === 'DELETE' && str_starts_with((string) $url, LINKEDIN_TEST_API.'/rest/posts/') => 'delete',
            default => throw new RuntimeException("Unexpected LinkedIn call in a test: {$method} {$url}"),
        };

        $response = $responses[$key];

        if ($response instanceof Closure) {
            $response = $response($request, $options);
        }

        return is_string($response) ? linkedInResponse($response) : $response;
    });
}

/**
 * A network failure as the HTTP client reports it: Laravel's exception around
 * the Guzzle exception whose class says how far the call got. The message
 * holds a token, to prove it goes nowhere.
 *
 * @param  class-string<NetworkException>  $class  ConnectException: never connected. The others: unknown.
 */
function linkedInConnectionFailure(Request $request, string $class, string $text): ConnectionException
{
    $message = "cURL error: {$text} for {$request->url()} with Bearer ".LINKEDIN_TEST_TOKEN;

    return new ConnectionException($message, 0, new $class($message, new GuzzleRequest($request->method(), $request->url())));
}

/**
 * The failures that do not say whether LinkedIn acted. On a call that is
 * safe to repeat they are retryable; on publish they must not be.
 *
 * @return array<string, array{Closure}>
 */
function linkedInAmbiguousFailures(): array
{
    return [
        'read timeout' => [fn (Request $request) => throw linkedInConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 30001 milliseconds')],
        'server error' => [fn () => linkedInResponse('error_server', 500)],
        'gateway error without JSON' => [fn () => Http::response('<html>Bad gateway</html>', 502)],
        'HTTP 429 that is not in the format of LinkedIn' => [fn () => Http::response('Too many requests', 429)],
        'write conflict' => [fn () => linkedInResponse('error_conflict', 409)],
    ];
}

/**
 * The calls the fake has recorded, as "METHOD address" without the query.
 *
 * @return list<string>
 */
function linkedInCalls(): array
{
    return Http::recorded()
        ->map(fn (array $pair): string => $pair[0]->method().' '.strtok($pair[0]->url(), '?'))
        ->values()
        ->all();
}

/**
 * The headers of a recorded request, one value each.
 */
function linkedInHeader(Request $request, string $name): ?string
{
    return $request->header($name)[0] ?? null;
}
