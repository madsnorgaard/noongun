<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Support\NetworkFailure;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Sends one request to the Graph API and turns every failure into a
 * ChannelException that is safe to log.
 *
 * Nothing from the HTTP client is passed on: its exceptions carry the full
 * request URL, and on GET and DELETE calls that URL holds the access token.
 * Failures are rebuilt from the Graph error fields only, and every credential
 * that was part of the request is removed from the text before it is used.
 *
 * Error codes are from developers.facebook.com/docs/graph-api/guides/error-handling
 * and developers.facebook.com/docs/graph-api/overview/rate-limiting, see
 * docs/platform-notes/meta.md.
 */
final class GraphClient
{
    /** Request parameters that hold a credential. */
    private const CREDENTIAL_PARAMETERS = [
        'access_token', 'appsecret_proof', 'client_secret', 'code', 'fb_exchange_token', 'input_token',
    ];

    /** Access token expired, or the session is invalid. */
    private const TOKEN_CODES = [102, 190];

    /** Authentication subcodes: app not installed, checkpoint, password changed, expired, unconfirmed, invalid token, no Page role. */
    private const TOKEN_SUBCODES = [458, 459, 460, 463, 464, 467, 492];

    /** Permission denied. Codes 200 to 299 are permission errors too. */
    private const PERMISSION_CODE = 10;

    /**
     * App, user, Pages API and custom rate limits. Codes 80000 to 80014 are
     * business use case limits. Each is documented purely as throttling: the
     * call was turned away, not attempted.
     */
    private const RATE_LIMIT_CODES = [4, 17, 32, 613];

    /**
     * Unknown error, service error and "application limit reached". All are
     * documented as "temporary downtime" (341 as "downtime or throttling"),
     * which does not say how far the call got before it failed.
     */
    private const TRANSIENT_CODES = [1, 2, 341];

    /**
     * "Unsupported get request. Object with ID ... does not exist, cannot be
     * loaded due to missing permissions, or does not support this
     * operation" (100 with subcode 33) and "Some of the aliases you
     * requested do not exist" (803). Neither is in the documentation that
     * was read; they are what the API is known to answer for an object that
     * was deleted. See docs/platform-notes/meta.md.
     */
    private const NOT_FOUND_CODE = 100;

    private const NOT_FOUND_SUBCODE = 33;

    private const UNKNOWN_ALIAS_CODE = 803;

    /** Most characters of an error message from Facebook that are kept. */
    private const MESSAGE_LENGTH = 300;

    /** Most characters of an error type or trace id that are kept. */
    private const LABEL_LENGTH = 64;

    /** Headers in which Facebook says how long to wait. */
    private const WAIT_HEADERS = ['X-Business-Use-Case-Usage', 'Retry-After'];

    /**
     * @param  string  $platform  platform name for exceptions
     * @param  string  $baseUrl  Graph API address including the version
     * @param  string  $appSecret  used for appsecret_proof and removed from every message
     * @param  int  $timeout  seconds allowed for one call
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $platform,
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $appSecret,
        private readonly int $timeout,
    ) {}

    /**
     * $safeToRepeat decides what "retryable" may mean for this call, and the
     * default is the careful one.
     *
     * false (a call that creates something, such as a post): a failure is
     * retryable only when Facebook certainly did not act: the connection was
     * never made, or Facebook answered with a rate limit error. A timeout, a
     * broken connection, a 5xx, an "unknown" or "service" error and an
     * answer that cannot be read all leave the outcome unknown, and are
     * reported as not retryable with `outcome_unknown` in the context,
     * because sending the call again could create the thing twice.
     *
     * true (reading, or a call whose repetition changes nothing, such as a
     * delete): every temporary failure is retryable.
     *
     * @param  'GET'|'POST'|'DELETE'  $method
     * @param  array<string, string|int>  $parameters  sent in the query for GET and DELETE, in the form body for POST
     * @param  string|null  $token  user or Page access token; appsecret_proof is added for it
     * @param  class-string<ChannelException>  $failure  the exception class to throw
     * @param  bool  $safeToRepeat  true only when sending the same call twice does no harm
     * @return array<string, mixed> the decoded JSON body
     *
     * @throws ChannelException
     */
    public function send(
        string $method,
        string $path,
        #[SensitiveParameter] array $parameters = [],
        #[SensitiveParameter] ?string $token = null,
        string $failure = ChannelException::class,
        bool $safeToRepeat = false,
    ): array {
        [$response, $endpoint, $secrets] = $this->request($method, $path, $parameters, $token, $failure, $safeToRepeat);

        $body = $response->json();

        if ($response->successful() && is_array($body) && ! isset($body['error'])) {
            return $body;
        }

        throw $this->failure($response, $failure, $endpoint, $secrets, $safeToRepeat);
    }

    /**
     * Reads several objects in one request to the batch endpoint, and gives
     * one answer for each read, in the same order. Null is a read that
     * Facebook did not finish in time; it can be asked again.
     *
     * A read that failed does not fail the others: its answer says how
     * (GraphOutcome) and carries the exception that send() would have
     * thrown. Only a failure of the batch itself is thrown.
     *
     * Every read still counts as one call against the rate limit. Only GET
     * is offered, so a batch is always safe to repeat, and the token travels
     * in the form body, not in a URL. Paths are relative to the configured
     * address: no absolute address is ever put into a batch.
     *
     * @param  list<array{path: string, query: array<string, string>}>  $reads  at most the 50 that Facebook allows in one batch
     * @return list<GraphAnswer|null>
     *
     * @throws ChannelException
     */
    public function batch(array $reads, #[SensitiveParameter] string $token): array
    {
        if ($reads === []) {
            return [];
        }

        $batch = array_map(fn (array $read): array => [
            'method' => 'GET',
            'relative_url' => ltrim($read['path'], '/').($read['query'] === [] ? '' : '?'.http_build_query($read['query'], '', '&', PHP_QUERY_RFC3986)),
        ], $reads);

        [$response, $endpoint, $secrets] = $this->request('POST', '', [
            'batch' => json_encode($batch, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'include_headers' => 'true',
        ], $token, ChannelException::class, true);

        $body = $response->json();

        if (! $response->successful() || ! is_array($body) || ! array_is_list($body)) {
            throw $this->failure($response, ChannelException::class, $endpoint.' (batch)', $secrets, true);
        }

        $answers = [];

        foreach ($reads as $index => $read) {
            $answers[] = $this->answer($body[$index] ?? null, 'GET /'.ltrim($read['path'], '/'), $secrets, $response);
        }

        return $answers;
    }

    /**
     * @param  'GET'|'POST'|'DELETE'  $method
     * @param  array<string, string|int>  $parameters
     * @param  class-string<ChannelException>  $failure
     * @return array{Response, string, list<string>} the response, the endpoint for messages, and the credentials that were sent
     *
     * @throws ChannelException
     */
    private function request(
        string $method,
        string $path,
        #[SensitiveParameter] array $parameters,
        #[SensitiveParameter] ?string $token,
        string $failure,
        bool $safeToRepeat,
    ): array {
        if ($token !== null) {
            $parameters['access_token'] = $token;
            $parameters['appsecret_proof'] = hash_hmac('sha256', $token, $this->appSecret);
        }

        $secrets = $this->secretsIn($parameters);
        $endpoint = $method.' /'.ltrim($path, '/');

        // A redirect is never followed: the next address would get the
        // token. The Graph API answers where it is asked.
        $request = $this->http
            ->baseUrl($this->baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout))
            ->withoutRedirecting()
            ->acceptJson();

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $parameters),
                'DELETE' => $request->withQueryParameters($parameters)->delete($path),
                'POST' => $request->asForm()->post($path, $parameters),
            };
        } catch (ConnectionException|TransferException $exception) {
            // Deliberately not chained: the original message holds the URL.
            $neverConnected = NetworkFailure::neverConnected($exception);
            unset($exception);

            if ($safeToRepeat || $neverConnected) {
                throw $failure::retryable(
                    $this->platform,
                    $neverConnected
                        ? 'Facebook could not be reached. No connection was made, so nothing was sent.'
                        : 'Facebook could not be reached. The connection failed or timed out.',
                    context: ['endpoint' => $endpoint],
                );
            }

            throw $this->outcomeUnknown(
                $failure,
                'The connection to Facebook failed or timed out after the request may have been sent.',
                ['endpoint' => $endpoint],
            );
        }

        return [$response, $endpoint, $secrets];
    }

    /**
     * One entry of a batch answer: `code`, `headers` (a list of name and
     * value) and `body`, a JSON document as a string. Anything else than
     * that shape is treated as a read that was not finished.
     *
     * @param  list<string>  $secrets
     */
    private function answer(mixed $entry, string $endpoint, #[SensitiveParameter] array $secrets, Response $batch): ?GraphAnswer
    {
        if (! is_array($entry)) {
            return null;
        }

        $status = is_numeric($entry['code'] ?? null) ? (int) $entry['code'] : 0;
        $raw = $entry['body'] ?? null;
        $body = is_string($raw) ? json_decode($raw, true) : $raw;

        if ($status >= 200 && $status < 300 && is_array($body) && ! isset($body['error'])) {
            return new GraphAnswer(GraphOutcome::Answered, $body);
        }

        $headers = [];

        foreach (is_array($entry['headers'] ?? null) ? $entry['headers'] : [] as $header) {
            if (is_array($header) && is_string($header['name'] ?? null) && is_string($header['value'] ?? null)) {
                $headers[$header['name']] = $header['value'];
            }
        }

        // The advice on how long to wait may be on the batch instead of on
        // the read that was turned away.
        $sent = array_change_key_case($headers);

        foreach (self::WAIT_HEADERS as $name) {
            if (! array_key_exists(strtolower($name), $sent) && $batch->header($name) !== '') {
                $headers[$name] = $batch->header($name);
            }
        }

        $response = new Response(new Psr7Response(
            $status >= 100 && $status <= 599 ? $status : 502,
            $headers,
            is_string($raw) ? $raw : (string) json_encode($body),
        ));

        return new GraphAnswer(
            $this->outcome($response),
            [],
            $this->failure($response, ChannelException::class, $endpoint, $secrets, true),
        );
    }

    /**
     * A failure of a call that is not safe to repeat, where Facebook may
     * have done what was asked. Never retryable.
     *
     * @param  class-string<ChannelException>  $failure
     * @param  array<string, mixed>  $context
     */
    private function outcomeUnknown(string $failure, string $what, array $context): ChannelException
    {
        return $failure::permanent(
            $this->platform,
            $what.' The outcome is unknown: Facebook may have carried out the request. Check the Page before trying again.',
            [...$context, 'outcome_unknown' => true],
        );
    }

    /**
     * @param  class-string<ChannelException>  $failure
     * @param  list<string>  $secrets
     */
    private function failure(Response $response, string $failure, string $endpoint, #[SensitiveParameter] array $secrets, bool $safeToRepeat): ChannelException
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;
        // Text from Facebook is cut to a length that fits a log line and a
        // row: an answer can be as long as its sender likes.
        $said = $this->said($error['message'] ?? null, self::MESSAGE_LENGTH, $secrets);

        $context = [
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'code' => $code,
            'subcode' => $subcode,
            'type' => $this->said($error['type'] ?? null, self::LABEL_LENGTH, $secrets),
            'fbtrace_id' => $this->said($error['fbtrace_id'] ?? null, self::LABEL_LENGTH, $secrets),
        ];

        $detail = $said === null ? '' : ' Facebook said: '.$said;

        if ($this->tokenOrPermissionProblem($code, $subcode)) {
            return $failure::reconnectRequired(
                $this->platform,
                'Facebook no longer accepts this connection, or a permission is missing. Connect the Page again.'.$detail,
                $context,
            );
        }

        // A rate limit error is a definite refusal: Facebook turned the call
        // away before doing anything, so it is retryable for every call.
        if ($this->rateLimited($code)) {
            return $failure::retryable(
                $this->platform,
                'Facebook is limiting requests at the moment.'.$detail,
                $this->retryAfter($response),
                [...$context, 'rate_limited' => true],
            );
        }

        $temporary = in_array($code, self::TRANSIENT_CODES, true)
            || $response->serverError()
            || $response->status() === 429;

        if ($temporary && $safeToRepeat) {
            return $failure::retryable(
                $this->platform,
                $response->status() === 429 ? 'Facebook is limiting requests at the moment.'.$detail : 'Facebook had a temporary problem.'.$detail,
                $this->retryAfter($response),
                $response->status() === 429 ? [...$context, 'rate_limited' => true] : $context,
            );
        }

        // For a call that creates something, none of these says whether
        // Facebook acted: "unknown" and "service" errors (1, 2, 341) and 5xx
        // can come from any stage of handling, and a 429 or 2xx without a
        // readable body cannot be trusted either.
        if (! $safeToRepeat && ($temporary || $error === [])) {
            return $this->outcomeUnknown(
                $failure,
                $error === []
                    ? "Facebook gave an answer that could not be read (HTTP {$response->status()})."
                    : 'Facebook had a temporary problem while handling the request.'.$detail,
                $context,
            );
        }

        if ($error === []) {
            return $failure::permanent(
                $this->platform,
                "Facebook gave an answer that could not be read (HTTP {$response->status()}).",
                $context,
            );
        }

        return $failure::permanent($this->platform, 'Facebook refused the request.'.$detail, $context);
    }

    /**
     * A text field of a Graph error, without credentials and no longer than
     * $length characters. Null when Facebook sent none.
     *
     * @param  list<string>  $secrets
     */
    private function said(mixed $value, int $length, #[SensitiveParameter] array $secrets): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return Str::limit($this->scrub($value, $secrets), $length);
    }

    /**
     * How one read of a batch failed, for a caller that treats a missing
     * permission, a missing object and a refused token differently.
     */
    private function outcome(Response $response): GraphOutcome
    {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;

        return match (true) {
            $this->tokenProblem($code, $subcode) => GraphOutcome::TokenRefused,
            $this->permissionProblem($code) => GraphOutcome::PermissionMissing,
            $this->rateLimited($code), $response->status() === 429 => GraphOutcome::RateLimited,
            $code === self::NOT_FOUND_CODE && $subcode === self::NOT_FOUND_SUBCODE,
            $code === self::UNKNOWN_ALIAS_CODE,
            $response->status() === 404 => GraphOutcome::NotFound,
            in_array($code, self::TRANSIENT_CODES, true), $response->serverError() => GraphOutcome::Temporary,
            default => GraphOutcome::Refused,
        };
    }

    private function tokenOrPermissionProblem(?int $code, ?int $subcode): bool
    {
        return $this->tokenProblem($code, $subcode) || $this->permissionProblem($code);
    }

    private function tokenProblem(?int $code, ?int $subcode): bool
    {
        return in_array($code, self::TOKEN_CODES, true)
            || in_array($subcode, self::TOKEN_SUBCODES, true);
    }

    private function permissionProblem(?int $code): bool
    {
        return $code === self::PERMISSION_CODE
            || ($code !== null && $code >= 200 && $code <= 299);
    }

    private function rateLimited(?int $code): bool
    {
        return in_array($code, self::RATE_LIMIT_CODES, true)
            || ($code !== null && $code >= 80000 && $code <= 80014);
    }

    /**
     * Facebook's own advice on how long to wait: the largest
     * estimated_time_to_regain_access (minutes) in the business use case
     * header, or else a Retry-After header in seconds.
     */
    private function retryAfter(Response $response): ?int
    {
        $usage = json_decode($response->header('X-Business-Use-Case-Usage'), true);
        $minutes = 0;

        foreach (is_array($usage) ? $usage : [] as $entries) {
            foreach (is_array($entries) ? $entries : [] as $entry) {
                $wait = is_array($entry) ? ($entry['estimated_time_to_regain_access'] ?? 0) : 0;
                $minutes = max($minutes, is_numeric($wait) ? (int) $wait : 0);
            }
        }

        if ($minutes > 0) {
            return $minutes * 60;
        }

        $seconds = $response->header('Retry-After');

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : null;
    }

    /**
     * @param  array<string, string|int>  $parameters
     * @return list<string>
     */
    private function secretsIn(#[SensitiveParameter] array $parameters): array
    {
        $secrets = [$this->appSecret];

        foreach (self::CREDENTIAL_PARAMETERS as $name) {
            if (isset($parameters[$name])) {
                $secrets[] = (string) $parameters[$name];
            }
        }

        return array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));
    }

    /**
     * @param  list<string>  $secrets
     */
    private function scrub(string $text, #[SensitiveParameter] array $secrets): string
    {
        $encoded = array_map(rawurlencode(...), $secrets);

        return str_replace([...$secrets, ...$encoded], ChannelException::REDACTED, $text);
    }
}
