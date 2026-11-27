<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Support\NetworkFailure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response;
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
 *
 * A file goes out with upload(), in the body of the request. This class
 * never logs or dumps a request: the body of an upload holds the token.
 *
 * No redirect is followed, on any call. The HTTP client would send the
 * same request again to whatever address the answer names, and on a 307 or
 * 308 with its body: the token, its proof and the file. The Graph API has
 * no reason to redirect, so a 3xx is a failure like any other answer that
 * was not expected.
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
        if ($token !== null) {
            $parameters['access_token'] = $token;
            $parameters['appsecret_proof'] = hash_hmac('sha256', $token, $this->appSecret);
        }

        $secrets = $this->secretsIn($parameters);
        $endpoint = $method.' /'.ltrim($path, '/');

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

        $body = $response->json();

        if ($response->successful() && is_array($body) && ! isset($body['error'])) {
            return $body;
        }

        throw $this->failure($response, $failure, $endpoint, $secrets, $safeToRepeat);
    }

    /**
     * Sends one file to the Graph API as multipart/form-data, read from a
     * stream. The token and its proof travel as fields of the body, never
     * in the address.
     *
     * Only for a call that makes nothing public, such as a photo that is
     * uploaded unpublished: every temporary failure is reported as
     * retryable, because sending the file again does no harm. The caller
     * closes the stream.
     *
     * @param  array<string, string>  $parameters  the other fields of the form
     * @param  resource  $file
     * @param  string  $filename  made by the caller, never taken from a user
     * @param  int  $timeout  seconds allowed for the whole call
     * @param  class-string<ChannelException>  $failure
     * @param  string  $refused  what to say when Facebook refuses the file itself
     * @return array<string, mixed> the decoded JSON body
     *
     * @throws ChannelException
     */
    public function upload(
        string $path,
        #[SensitiveParameter] array $parameters,
        #[SensitiveParameter] string $token,
        string $field,
        $file,
        string $filename,
        string $mimeType,
        int $timeout,
        string $failure,
        string $refused,
    ): array {
        $parameters['access_token'] = $token;
        $parameters['appsecret_proof'] = hash_hmac('sha256', $token, $this->appSecret);

        $secrets = $this->secretsIn($parameters);
        $endpoint = 'POST /'.ltrim($path, '/');

        try {
            $response = $this->http
                ->baseUrl($this->baseUrl)
                ->timeout($timeout)
                ->connectTimeout(min(10, $timeout))
                ->withoutRedirecting()
                ->acceptJson()
                ->attach($field, $file, $filename, ['Content-Type' => $mimeType])
                ->post($path, $parameters);
        } catch (ConnectionException|TransferException $exception) {
            // Deliberately not chained, as in send().
            unset($exception);

            throw $failure::retryable(
                $this->platform,
                'Facebook could not be reached. The connection failed or timed out.',
                context: ['endpoint' => $endpoint],
            );
        }

        $body = $response->json();

        if ($response->successful() && is_array($body) && ! isset($body['error'])) {
            return $body;
        }

        // Cut short or mangled on the way back. Nothing public was made,
        // so the file can simply be sent again.
        if ($response->successful() && ! is_array($body)) {
            throw $failure::retryable(
                $this->platform,
                "Facebook gave an answer that could not be read (HTTP {$response->status()}).",
                context: ['endpoint' => $endpoint, 'http_status' => $response->status()],
            );
        }

        throw $this->failure($response, $failure, $endpoint, $secrets, true, $refused);
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
    private function failure(
        Response $response,
        string $failure,
        string $endpoint,
        #[SensitiveParameter] array $secrets,
        bool $safeToRepeat,
        string $refused = 'Facebook refused the request.',
    ): ChannelException {
        $error = $response->json('error');
        $error = is_array($error) ? $error : [];

        $code = is_numeric($error['code'] ?? null) ? (int) $error['code'] : null;
        $subcode = is_numeric($error['error_subcode'] ?? null) ? (int) $error['error_subcode'] : null;
        $said = is_string($error['message'] ?? null) ? $this->scrub($error['message'], $secrets) : null;

        $context = [
            'endpoint' => $endpoint,
            'http_status' => $response->status(),
            'code' => $code,
            'subcode' => $subcode,
            'type' => is_string($error['type'] ?? null) ? $error['type'] : null,
            'fbtrace_id' => is_string($error['fbtrace_id'] ?? null) ? $error['fbtrace_id'] : null,
        ];

        $detail = $said === null ? '' : ' Facebook said: '.$said;

        // Not followed, see the class comment. The address it names is
        // not repeated either. For a call that creates something the
        // outcome is unknown, as for every answer that was not expected.
        if ($response->redirect()) {
            $what = "Facebook answered with a redirect (HTTP {$response->status()}), which is not followed.";

            return $safeToRepeat
                ? $failure::retryable($this->platform, $what, context: $context)
                : $this->outcomeUnknown($failure, $what, $context);
        }

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
                $context,
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
                $context,
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

        return $failure::permanent($this->platform, $refused.$detail, $context);
    }

    private function tokenOrPermissionProblem(?int $code, ?int $subcode): bool
    {
        return in_array($code, self::TOKEN_CODES, true)
            || in_array($subcode, self::TOKEN_SUBCODES, true)
            || $code === self::PERMISSION_CODE
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
