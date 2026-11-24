<?php

namespace App\Domain\Channels\Adapters\Facebook;

use App\Domain\Channels\Exceptions\ChannelException;
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

    /** App, user, Pages API, custom and application rate limits. Codes 80000 to 80014 are business use case limits. */
    private const RATE_LIMIT_CODES = [4, 17, 32, 341, 613];

    /** Unknown error and service error: "possibly temporary downtime, retry". */
    private const TRANSIENT_CODES = [1, 2];

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
     * @param  'GET'|'POST'|'DELETE'  $method
     * @param  array<string, string|int>  $parameters  sent in the query for GET and DELETE, in the form body for POST
     * @param  string|null  $token  user or Page access token; appsecret_proof is added for it
     * @param  class-string<ChannelException>  $failure  the exception class to throw
     * @return array<string, mixed> the decoded JSON body
     *
     * @throws ChannelException
     */
    public function send(string $method, string $path, #[SensitiveParameter] array $parameters = [], #[SensitiveParameter] ?string $token = null, string $failure = ChannelException::class): array
    {
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
            ->acceptJson();

        try {
            $response = match ($method) {
                'GET' => $request->get($path, $parameters),
                'DELETE' => $request->withQueryParameters($parameters)->delete($path),
                'POST' => $request->asForm()->post($path, $parameters),
            };
        } catch (ConnectionException|TransferException) {
            // Deliberately not chained: the original message holds the URL.
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

        throw $this->failure($response, $failure, $endpoint, $secrets);
    }

    /**
     * @param  class-string<ChannelException>  $failure
     * @param  list<string>  $secrets
     */
    private function failure(Response $response, string $failure, string $endpoint, #[SensitiveParameter] array $secrets): ChannelException
    {
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

        if ($this->tokenOrPermissionProblem($code, $subcode)) {
            return $failure::reconnectRequired(
                $this->platform,
                'Facebook no longer accepts this connection, or a permission is missing. Connect the Page again.'.$detail,
                $context,
            );
        }

        if ($this->rateLimited($code) || $response->status() === 429) {
            return $failure::retryable(
                $this->platform,
                'Facebook is limiting requests at the moment.'.$detail,
                $this->retryAfter($response),
                $context,
            );
        }

        if (in_array($code, self::TRANSIENT_CODES, true) || $response->serverError()) {
            return $failure::retryable(
                $this->platform,
                'Facebook had a temporary problem.'.$detail,
                $this->retryAfter($response),
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
