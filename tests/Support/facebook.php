<?php

use App\Domain\Channels\Adapters\Facebook\FacebookAdapter;
use App\Domain\Channels\ChannelManager;
use GuzzleHttp\Exception\NetworkException;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Helpers for tests of the Facebook adapter and the connect flow. Loaded by
 * the tests that need them with require_once.
 *
 * The values are deliberately unlike the defaults in config/noongun.php, so a
 * test fails if the adapter has a version or an address of its own.
 */
const FACEBOOK_TEST_GRAPH = 'https://graph.facebook.test/v99.0';

const FACEBOOK_TEST_APP_SECRET = 'test-app-secret';

/**
 * @param  array<string, mixed>  $overrides
 */
function configureFacebook(array $overrides = []): void
{
    config([
        'app.url' => 'https://noongun.test',
        'noongun.channels.facebook' => [
            'adapter' => FacebookAdapter::class,
            'app_id' => 'test-app-id',
            'app_secret' => FACEBOOK_TEST_APP_SECRET,
            'api_version' => 'v99.0',
            'graph_url' => 'https://graph.facebook.test',
            'dialog_url' => 'https://www.facebook.test',
            'scopes' => ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts'],
            'media_types' => ['image/jpeg', 'image/png'],
            'max_media_items' => 6,
            'max_media_bytes' => 7_000_000,
            'max_media_pixels' => null,
            'alt_text_length' => 700,
            'media_link_rule' => 'in_text',
            'media_upload_lifetime' => 7200,
            'media_upload_trusted' => 600,
            ...$overrides,
        ],
    ]);

    app(ChannelManager::class)->forgetAdapters();
}

function facebookAdapter(): FacebookAdapter
{
    $adapter = app(ChannelManager::class)->adapter('facebook');

    expect($adapter)->toBeInstanceOf(FacebookAdapter::class);

    /** @var FacebookAdapter $adapter */
    return $adapter;
}

/**
 * @return array<string, mixed>
 */
function facebookFixture(string $name): array
{
    return json_decode(
        (string) file_get_contents(__DIR__."/../Fixtures/facebook/{$name}.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/**
 * @param  array<string, string>  $headers
 */
function facebookResponse(string $fixture, int $status = 200, array $headers = []): PromiseInterface
{
    return Http::response(facebookFixture($fixture), $status, $headers);
}

function facebookProof(string $token): string
{
    return hash_hmac('sha256', $token, FACEBOOK_TEST_APP_SECRET);
}

/**
 * Fakes the Graph API. Every call must go to the configured address and
 * version, and anything else fails the test. Each endpoint answers with its
 * success fixture unless replaced:
 *
 *     fakeGraph(['feed' => facebookResponse('error_validation', 400)]);
 *
 * Keys: code, exchange, accounts, debug, feed, photos, delete. A value is a
 * fixture name, a response, or a closure that gets the request and the
 * options of the HTTP client.
 *
 * Every photo upload is read while the call is open, because the adapter
 * closes the file afterwards, and kept for facebookUploads(). Without a
 * replacement each upload is answered with a photo id of its own:
 * 800000000000001, 800000000000002 and so on.
 *
 * @param  array<string, mixed>  $responses
 */
function fakeGraph(array $responses = []): void
{
    $uploads = &facebookUploadLog();
    $uploads = [];
    $sent = &facebookOptionsLog();
    $sent = [];

    $responses += [
        'code' => 'oauth_code_exchange',
        'exchange' => 'oauth_long_lived_exchange',
        'accounts' => 'me_accounts',
        'debug' => 'debug_token_page',
        'feed' => 'feed_post',
        'photos' => fn () => Http::response(['id' => (string) (800000000000000 + count(facebookUploads()))]),
        'delete' => 'delete_post',
    ];

    Http::preventStrayRequests();

    Http::fake(function (Request $request, array $options = []) use ($responses, &$uploads, &$sent) {
        expect($request->url())->toStartWith(FACEBOOK_TEST_GRAPH.'/');

        $sent[] = ['method' => $request->method(), 'path' => (string) parse_url($request->url(), PHP_URL_PATH), 'options' => $options];

        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        $key = match (true) {
            str_ends_with($path, '/oauth/access_token') => isset($request['fb_exchange_token']) ? 'exchange' : 'code',
            str_ends_with($path, '/me/accounts') => 'accounts',
            str_ends_with($path, '/debug_token') => 'debug',
            str_ends_with($path, '/feed') => 'feed',
            str_ends_with($path, '/photos') => 'photos',
            $request->method() === 'DELETE' => 'delete',
            default => throw new RuntimeException("Unexpected Graph API call in a test: {$request->method()} {$path}"),
        };

        if ($key === 'photos') {
            $uploads[] = ['url' => $request->url(), 'timeout' => $options['timeout'] ?? null, ...facebookMultipart($request)];
        }

        $response = $responses[$key];

        if ($response instanceof Closure) {
            $response = $response($request, $options);
        }

        return is_string($response) ? facebookResponse($response) : $response;
    });
}

/**
 * A network failure as the HTTP client reports it: Laravel's exception around
 * the Guzzle exception whose class says how far the call got. The message
 * holds the URL with a token, as real ones can.
 *
 * @param  class-string<NetworkException>  $class  ConnectException: never connected. The others: unknown.
 */
function facebookConnectionFailure(Request $request, string $class, string $text): ConnectionException
{
    $message = "cURL error: {$text} for {$request->url()}?access_token=test-page-token-table-bay-coffee";

    return new ConnectionException($message, 0, new $class($message, new GuzzleRequest($request->method(), $request->url())));
}

/**
 * The failures that do not say whether Facebook acted. On a call that is
 * safe to repeat they are retryable; on publish they must not be.
 *
 * @return array<string, array{Closure}>
 */
function facebookAmbiguousFailures(): array
{
    return [
        'read timeout' => [fn (Request $request) => throw facebookConnectionFailure($request, NetworkTimeoutException::class, 'Operation timed out after 30001 milliseconds')],
        'service error with HTTP 500' => [fn () => facebookResponse('error_service', 500)],
        'gateway error without JSON' => [fn () => Http::response('<html>Bad gateway</html>', 502)],
        'HTTP 429 without a Graph error' => [fn () => Http::response('Too many requests', 429)],
    ];
}

/**
 * The query of a recorded request, whatever its method.
 *
 * @return array<string, mixed>
 */
function facebookQuery(Request $request): array
{
    parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

    return $query;
}

/**
 * @return list<array{url: string, timeout: mixed, fields: array<string, string>, file: array{field: string, filename: string, type: string, contents: string}|null}>
 */
function &facebookUploadLog(): array
{
    static $log = [];

    return $log;
}

/**
 * Every photo upload the faked Graph API received since fakeGraph(), in
 * order: the address, the time limit of the call, the fields of the form
 * and the file as it arrived.
 *
 * @return list<array{url: string, timeout: mixed, fields: array<string, string>, file: array{field: string, filename: string, type: string, contents: string}|null}>
 */
function facebookUploads(): array
{
    return facebookUploadLog();
}

/**
 * The parts of a multipart/form-data request: its plain fields by name,
 * and the one file it carries.
 *
 * @return array{fields: array<string, string>, file: array{field: string, filename: string, type: string, contents: string}|null}
 */
function facebookMultipart(Request $request): array
{
    expect($request->isMultipart())->toBeTrue();
    expect(preg_match('/boundary="?([^";]+)"?/', $request->header('Content-Type')[0] ?? '', $boundary))->toBe(1);

    $fields = [];
    $file = null;

    foreach (array_slice(explode('--'.$boundary[1], $request->body()), 1, -1) as $part) {
        [$head, $contents] = explode("\r\n\r\n", ltrim($part, "\r\n"), 2);
        $contents = substr($contents, 0, -2);

        expect(preg_match('/name="([^"]*)"/', $head, $name))->toBe(1);

        if (preg_match('/filename="([^"]*)"/', $head, $filename) === 1) {
            preg_match('/Content-Type:\s*(\S+)/i', $head, $type);
            $file = ['field' => $name[1], 'filename' => $filename[1], 'type' => $type[1] ?? '', 'contents' => $contents];

            continue;
        }

        $fields[$name[1]] = $contents;
    }

    return ['fields' => $fields, 'file' => $file];
}

/**
 * @return list<array{method: string, path: string, options: array<string, mixed>}>
 */
function &facebookOptionsLog(): array
{
    static $log = [];

    return $log;
}

/**
 * Every call the faked Graph API received since fakeGraph(), with the
 * options the HTTP client was given for it (timeout, allow_redirects).
 *
 * @return list<array{method: string, path: string, options: array<string, mixed>}>
 */
function facebookCalls(): array
{
    return facebookOptionsLog();
}

/** A read inside a batch that Facebook did not finish: `null` in the answer. */
const FACEBOOK_UNFINISHED = '(unfinished)';

/**
 * One entry of a batch answer, in the documented envelope: `code`, `headers`
 * as a list of name and value, and `body` as a JSON string.
 *
 * $answer is FACEBOOK_UNFINISHED, the name of a fixture (answered with 200,
 * or with 400 when the name holds "error"), a body as an array, or
 * [fixture or body, code, headers].
 *
 * @param  string|array<array-key, mixed>  $answer
 * @return array{code: int, headers: list<array{name: string, value: string}>, body: string}|null
 */
function facebookBatchEntry(string|array $answer, ?string $postId = null): ?array
{
    if ($answer === FACEBOOK_UNFINISHED) {
        return null;
    }

    $code = null;
    $headers = [];

    if (is_array($answer) && array_is_list($answer) && $answer !== []) {
        [$answer, $code, $headers] = [$answer[0], $answer[1] ?? null, $answer[2] ?? []];
    }

    $code ??= is_string($answer) && str_contains($answer, 'error') ? 400 : 200;
    $body = is_string($answer) ? facebookFixture($answer) : $answer;

    // A fixture of a post answers for the post that was asked about.
    if ($postId !== null && isset($body['id'], $body['reactions'])) {
        $body['id'] = $postId;
    }

    $list = [];

    foreach ($headers as $name => $value) {
        $list[] = ['name' => (string) $name, 'value' => (string) $value];
    }

    return ['code' => $code, 'headers' => $list, 'body' => (string) json_encode($body)];
}

/**
 * The reads of a recorded batch request, each with the id of the post, what
 * was read of it (post or insights) and its query.
 *
 * @return list<array{post: string, kind: string, query: array<string, mixed>}>
 */
function facebookBatchReads(Request $request): array
{
    $reads = [];

    foreach (json_decode((string) $request['batch'], true, flags: JSON_THROW_ON_ERROR) as $read) {
        expect($read['method'])->toBe('GET')
            // Relative to the configured address, never an address of its own.
            ->and($read['relative_url'])->not->toContain('://')->not->toStartWith('/');

        [$path, $query] = [...explode('?', $read['relative_url'], 2), ''];
        parse_str($query, $parameters);
        $insights = str_ends_with($path, '/insights');

        $reads[] = [
            'post' => $insights ? substr($path, 0, -strlen('/insights')) : $path,
            'kind' => $insights ? 'insights' : 'post',
            'query' => $parameters,
        ];
    }

    return $reads;
}

/**
 * Fakes the batch endpoint of the Graph API for reading metrics. Every call
 * must be a POST to the configured address and version, with the token in
 * the form body. Each read is answered with the fixture of a post with
 * numbers and of its insights, unless replaced for a post id (or for "*",
 * every post):
 *
 *     fakeGraphMetrics(['100_200' => ['post' => 'insights/error_post_gone']]);
 *     fakeGraphMetrics(['*' => ['insights' => FACEBOOK_UNFINISHED]]);
 *
 * A value is anything facebookBatchEntry() takes. $headers are the headers
 * of the batch answer itself.
 *
 * @param  array<string, array{post?: string|array<array-key, mixed>, insights?: string|array<array-key, mixed>}>  $answers
 * @param  array<string, string>  $headers
 */
function fakeGraphMetrics(array $answers = [], array $headers = []): void
{
    Http::preventStrayRequests();

    Http::fake(function (Request $request) use ($answers, $headers) {
        expect($request->url())->toBe(FACEBOOK_TEST_GRAPH.'/')
            ->and($request->method())->toBe('POST')
            ->and($request->isForm())->toBeTrue();

        $entries = [];

        foreach (facebookBatchReads($request) as $read) {
            $answer = $answers[$read['post']][$read['kind']]
                ?? $answers['*'][$read['kind']]
                ?? ($read['kind'] === 'post' ? 'insights/post_engagement' : 'insights/post_insights');

            $entries[] = facebookBatchEntry($answer, $read['post']);
        }

        return Http::response($entries, 200, $headers);
    });
}

/**
 * Every batch request that was sent, in order.
 *
 * @return list<Request>
 */
function facebookBatchRequests(): array
{
    return Http::recorded()->map(fn (array $pair): Request => $pair[0])->values()->all();
}
