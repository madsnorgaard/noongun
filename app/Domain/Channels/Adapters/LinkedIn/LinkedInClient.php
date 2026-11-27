<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Support\NetworkFailure;
use Closure;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Sends one request to LinkedIn and turns every failure into a
 * ChannelException that is safe to log.
 *
 * Requests go to the two configured addresses and nowhere else: no address
 * from a response is ever called, and redirects are not followed, so a token
 * cannot be carried to another host. Nothing from the HTTP client is passed
 * on either.
 *
 * The message of a failure is written here, in plain words with a next step,
 * because a member reads it. What LinkedIn itself said goes into the context
 * (`platform_message`, `code`), cut to size and with every credential of the
 * request removed, where it reaches the log of the instance and nobody else.
 *
 * The status codes and error bodies are described in
 * docs/platform-notes/linkedin.md, with their sources.
 */
final class LinkedInClient
{
    /** Request parameters that hold a credential. */
    private const CREDENTIAL_PARAMETERS = ['client_secret', 'code', 'refresh_token', 'token'];

    /** Longest text of LinkedIn's that is kept for the log. */
    private const MESSAGE_LENGTH = 300;

    /**
     * @param  string  $platform  platform name for exceptions
     * @param  string  $apiUrl  address of the API, without a path
     * @param  string  $webUrl  address of linkedin.com, where the OAuth endpoints live
     * @param  string  $version  value of the Linkedin-Version header, YYYYMM
     * @param  string  $clientSecret  removed from every message
     * @param  int  $timeout  seconds allowed for one call
     */
    public function __construct(
        private readonly Factory $http,
        private readonly string $platform,
        private readonly string $apiUrl,
        private readonly string $webUrl,
        private readonly string $version,
        #[SensitiveParameter] private readonly string $clientSecret,
        private readonly int $timeout,
    ) {}

    /**
     * A call to one of the OAuth endpoints under /oauth/v2, as a form post.
     * These either read or exchange something that can only be exchanged
     * once, so sending one twice does no harm and every temporary failure
     * is retryable.
     *
     * @param  array<string, string>  $parameters
     * @return array<string, mixed> the decoded JSON body
     *
     * @throws ChannelException
     */
    public function oauth(string $endpoint, #[SensitiveParameter] array $parameters): array
    {
        $secrets = $this->secretsIn($parameters);
        $path = 'oauth/v2/'.$endpoint;

        $response = $this->send(
            'POST /'.$path,
            fn (): Response => $this->request($this->webUrl)->asForm()->post($path, $parameters),
            ChannelException::class,
            safeToRepeat: true,
        );

        $body = $response->json();

        if ($response->successful() && is_array($body) && ! isset($body['error'])) {
            return $body;
        }

        throw $this->failure($response, ChannelException::class, 'POST /'.$path, $secrets, safeToRepeat: true, signingIn: true);
    }

    /**
     * A call to the API with a member's token.
     *
     * $safeToRepeat decides what "retryable" may mean, and the default is
     * the careful one.
     *
     * false (creating a post): a failure is retryable only when LinkedIn
     * certainly did not act: the connection was never made, or LinkedIn
     * answered that the call was throttled. A timeout, a broken connection,
     * a 5xx, a write conflict and an answer that cannot be read all leave
     * the outcome unknown. They are reported as not retryable, with
     * `outcome_unknown` in the context, because LinkedIn has no idempotency
     * key: a second call would make a second post.
     *
     * true (reading, deleting): every temporary failure is retryable.
     *
     * @param  'GET'|'POST'|'DELETE'  $method
     * @param  string  $path  relative to the API address; paths under rest/ get the version headers
     * @param  array<string, mixed>  $json  body of a POST
     * @param  array<string, string>  $headers
     * @param  class-string<ChannelException>  $failure
     * @return array{status: int, body: array<string, mixed>, id: string|null} id is the x-restli-id header
     *
     * @throws ChannelException
     */
    public function api(
        string $method,
        string $path,
        #[SensitiveParameter] string $token,
        array $json = [],
        array $headers = [],
        string $failure = ChannelException::class,
        bool $safeToRepeat = false,
    ): array {
        $endpoint = $method.' /'.$path;

        if (str_starts_with($path, 'rest/')) {
            $headers = ['Linkedin-Version' => $this->version, 'X-Restli-Protocol-Version' => '2.0.0', ...$headers];
        }

        $request = fn (): PendingRequest => $this->request($this->apiUrl)->withToken($token)->withHeaders($headers);

        $response = $this->send($endpoint, fn (): Response => match ($method) {
            'GET' => $request()->get($path),
            'DELETE' => $request()->delete($path),
            'POST' => $request()->asJson()->post($path, $json),
        }, $failure, $safeToRepeat);

        if (! $response->successful()) {
            throw $this->failure($response, $failure, $endpoint, [$token, $this->clientSecret], $safeToRepeat);
        }

        $body = $response->json();
        $id = $response->header('x-restli-id');

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : [],
            'id' => $id !== '' ? $id : null,
        ];
    }

    private function request(string $baseUrl): PendingRequest
    {
        return $this->http
            ->baseUrl($baseUrl)
            ->timeout($this->timeout)
            ->connectTimeout(min(10, $this->timeout))
            ->withoutRedirecting()
            ->acceptJson();
    }

    /**
     * @param  Closure(): Response  $call
     * @param  class-string<ChannelException>  $failure
     */
    private function send(string $endpoint, Closure $call, string $failure, bool $safeToRepeat): Response
    {
        try {
            return $call();
        } catch (ConnectionException|TransferException $exception) {
            // Deliberately not chained: the original can hold the request.
            $neverConnected = NetworkFailure::neverConnected($exception);
            unset($exception);

            if ($safeToRepeat || $neverConnected) {
                throw $failure::retryable(
                    $this->platform,
                    $neverConnected
                        ? 'LinkedIn could not be reached. No connection was made, so nothing was sent.'
                        : 'LinkedIn could not be reached. The connection failed or timed out.',
                    context: ['endpoint' => $endpoint],
                );
            }

            throw $this->outcomeUnknown(
                $failure,
                'The connection to LinkedIn failed or timed out after the request may have been sent.',
                ['endpoint' => $endpoint],
            );
        }
    }

    /**
     * A failure of a call that is not safe to repeat, where LinkedIn may
     * have done what was asked. Never retryable.
     *
     * @param  class-string<ChannelException>  $failure
     * @param  array<string, mixed>  $context
     */
    public function outcomeUnknown(string $failure, string $what, array $context): ChannelException
    {
        return $failure::permanent(
            $this->platform,
            $what.' The outcome is unknown: the post may be on LinkedIn. Look at the profile. If the post is there, leave this as it is. If it is not, send it again.',
            [...$context, 'outcome_unknown' => true],
        );
    }

    /**
     * @param  class-string<ChannelException>  $failure
     * @param  list<string>  $secrets
     * @param  bool  $signingIn  true for the OAuth endpoints, where a refusal is about the sign-in and not about a stored token
     */
    private function failure(Response $response, string $failure, string $endpoint, #[SensitiveParameter] array $secrets, bool $safeToRepeat, bool $signingIn = false): ChannelException
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];
        $status = $response->status();

        // The API answers with message, status, serviceErrorCode and code;
        // the OAuth endpoints with error and error_description.
        $said = $this->forLog($body['message'] ?? $body['error_description'] ?? null, $secrets);
        $code = $this->forLog($body['code'] ?? $body['error'] ?? null, $secrets);
        $readable = $said !== null || $code !== null;

        $context = [
            'endpoint' => $endpoint,
            'http_status' => $status,
            'code' => $code,
            'service_error_code' => is_numeric($body['serviceErrorCode'] ?? null) ? (int) $body['serviceErrorCode'] : null,
            'platform_message' => $said,
        ];

        if (! $signingIn && ($status === 401 || $status === 403)) {
            return $failure::reconnectRequired(
                $this->platform,
                $status === 401
                    ? 'LinkedIn no longer accepts this connection: the access has expired or was withdrawn. Connect the profile again on the Channels page.'
                    : 'LinkedIn did not allow this. Connect the profile again on the Channels page and allow everything that is asked for.',
                $context,
            );
        }

        // A throttled call is turned away, not attempted, so it is retryable
        // for every call. Only an answer in LinkedIn's own error format is
        // trusted to mean that: a bare 429 can come from anything in between.
        if ($status === 429 && $readable) {
            return $failure::retryable(
                $this->platform,
                'LinkedIn is limiting requests at the moment. Try again later.',
                $this->retryAfter($response),
                [...$context, 'rate_limited' => true],
            );
        }

        // 409 is documented as a write conflict that may be retried. It does
        // not say how far the write got, so on a publish it is not trusted.
        $temporary = $response->serverError() || $status === 429 || $status === 409;

        if ($temporary && $safeToRepeat) {
            return $failure::retryable(
                $this->platform,
                $status === 429 ? 'LinkedIn is limiting requests at the moment. Try again later.' : 'LinkedIn had a temporary problem. Try again later.',
                $this->retryAfter($response),
                $status === 429 ? [...$context, 'rate_limited' => true] : $context,
            );
        }

        if (! $safeToRepeat && ($temporary || ! $readable)) {
            return $this->outcomeUnknown(
                $failure,
                $readable
                    ? 'LinkedIn had a temporary problem while handling the request.'
                    : 'LinkedIn gave an answer that could not be read.',
                $context,
            );
        }

        if (! $readable) {
            return $failure::permanent($this->platform, 'LinkedIn gave an answer that could not be read. Try again later.', $context);
        }

        if ($status === 426) {
            return $failure::permanent(
                $this->platform,
                'This instance asks LinkedIn for a version of its service that LinkedIn has retired. The person who runs this instance must set LINKEDIN_API_VERSION to a current version.',
                $context,
            );
        }

        if ($signingIn) {
            return $failure::permanent(
                $this->platform,
                'LinkedIn refused the sign-in. Start again from the Channels page. If it keeps happening, check the Client ID, the Client Secret and the redirect address of the LinkedIn app.',
                $context,
            );
        }

        return $failure::permanent(
            $this->platform,
            $code === 'FIELD_LENGTH_TOO_LONG'
                ? 'LinkedIn found the text or the link title too long. Return the post to draft, shorten it and try again.'
                : 'LinkedIn did not accept this. Return the post to draft, check the text and the link, and try again. The reason LinkedIn gave is in the log of this instance.',
            $context,
        );
    }

    /**
     * A text from LinkedIn's answer, made safe to keep for the log: the
     * credentials of this request removed, then cut to size.
     *
     * @param  list<string>  $secrets
     */
    private function forLog(mixed $value, #[SensitiveParameter] array $secrets): ?string
    {
        if (! is_scalar($value) || (string) $value === '') {
            return null;
        }

        return Str::limit($this->scrub((string) $value, $secrets), self::MESSAGE_LENGTH);
    }

    /**
     * LinkedIn documents no Retry-After header. It is read in case one is
     * sent; without it the caller's own backoff applies.
     */
    private function retryAfter(Response $response): ?int
    {
        $seconds = $response->header('Retry-After');

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : null;
    }

    /**
     * @param  array<string, string>  $parameters
     * @return list<string>
     */
    private function secretsIn(#[SensitiveParameter] array $parameters): array
    {
        $secrets = [$this->clientSecret];

        foreach (self::CREDENTIAL_PARAMETERS as $name) {
            if (isset($parameters[$name])) {
                $secrets[] = $parameters[$name];
            }
        }

        return array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));
    }

    /**
     * @param  list<string>  $secrets
     */
    private function scrub(string $text, #[SensitiveParameter] array $secrets): string
    {
        $secrets = array_values(array_filter($secrets, fn (string $secret): bool => $secret !== ''));
        $encoded = array_map(rawurlencode(...), $secrets);

        return str_replace([...$secrets, ...$encoded], ChannelException::REDACTED, $text);
    }
}
