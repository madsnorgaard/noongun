<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Channels\Support\NetworkFailure;
use Closure;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
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
 * Requests go to the two configured addresses, and redirects are not
 * followed, so a token cannot be carried to another host. The one address
 * taken from a response is the one an image is uploaded to, and upload()
 * accepts it only as an UploadAddress that passed its check. Nothing from
 * the HTTP client is passed on either.
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

    /** Answers to an upload that say the file is the problem: sending it again cannot help. */
    private const FILE_REFUSED = [400, 413, 415, 422];

    /** The one that says the file is too large. */
    private const FILE_TOO_LARGE = 413;

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
     * @param  int|null  $timeout  seconds for this call, when it must be shorter than the usual limit
     * @param  string|null  $refused  what the member is told when LinkedIn refuses the call for a reason of its own
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
        ?int $timeout = null,
        ?string $refused = null,
    ): array {
        $endpoint = $method.' /'.$path;

        if (str_starts_with($path, 'rest/')) {
            $headers = ['Linkedin-Version' => $this->version, 'X-Restli-Protocol-Version' => '2.0.0', ...$headers];
        }

        $request = fn (): PendingRequest => $this->request($this->apiUrl, $timeout)->withToken($token)->withHeaders($headers);

        $response = $this->send($endpoint, fn (): Response => match ($method) {
            'GET' => $request()->get($path),
            'DELETE' => $request()->delete($path),
            'POST' => $request()->asJson()->post($path, $json),
        }, $failure, $safeToRepeat);

        if (! $response->successful()) {
            throw $this->failure($response, $failure, $endpoint, [$token, $this->clientSecret], $safeToRepeat, refused: $refused);
        }

        $body = $response->json();
        $id = $response->header('x-restli-id');

        return [
            'status' => $response->status(),
            'body' => is_array($body) ? $body : [],
            'id' => $id !== '' ? $id : null,
        ];
    }

    /**
     * Sends the bytes of one image to the address LinkedIn named for it.
     * Nothing is made public by this, so every temporary failure is
     * retryable.
     *
     * The token is sent along because the documentation asks for it on the
     * upload of an image. That is why the address must have passed the
     * check of UploadAddress, and why a redirect is not followed.
     *
     * The answer is not in the format of the API: the documentation shows a
     * 201 without a body and no failure at all. So only the status is read.
     * 401 means the token; a status that says the file itself is the
     * problem is permanent; everything else is worth another attempt, which
     * starts with a new address.
     *
     * @param  resource  $stream  the file; the caller closes it
     * @param  int  $bytes  size of the file, sent as Content-Length so that the upload is not chunked
     * @param  int  $timeout  seconds for this call
     * @param  class-string<ChannelException>  $failure
     * @param  int  $number  which image of the post this is, counted from 1, for the member
     *
     * @throws ChannelException
     */
    public function upload(UploadAddress $address, #[SensitiveParameter] string $token, $stream, int $bytes, int $timeout, string $failure, int $number = 1): void
    {
        $endpoint = 'PUT upload address';
        $context = ['endpoint' => $endpoint, 'upload_host' => $address->host];

        if (! $address->allowed()) {
            throw $failure::permanent($this->platform, 'LinkedIn named an address for the image that may not be used. Nothing was sent to it.', $context);
        }

        $response = $this->send(
            $endpoint,
            fn (): Response => $this->http
                ->timeout($timeout)
                ->connectTimeout(min(10, $timeout))
                ->withoutRedirecting()
                ->withToken($token)
                ->withHeaders(['Content-Length' => (string) $bytes])
                ->withBody(Utils::streamFor($stream), 'application/octet-stream')
                ->send('PUT', (string) $address->url),
            $failure,
            safeToRepeat: true,
            context: $context,
        );

        if ($response->successful()) {
            return;
        }

        $status = $response->status();
        $body = $response->json();
        $context += [
            'http_status' => $status,
            'image_number' => $number,
            'image_bytes' => $bytes,
            'platform_message' => $this->forLog(is_array($body) ? ($body['message'] ?? null) : null, [$token, $this->clientSecret]),
        ];

        throw match (true) {
            $status === 401 => $failure::reconnectRequired(
                $this->platform,
                'LinkedIn no longer accepts this connection: the access has expired or was withdrawn. Connect the profile again on the Channels page.',
                $context,
            ),
            $status === self::FILE_TOO_LARGE => $failure::permanent(
                $this->platform,
                sprintf(
                    'Image %d of this post is too large for LinkedIn (%s MB). Nothing was posted. Return the post to draft, take the image off and put a smaller copy of it on.',
                    $number,
                    number_format($bytes / 1048576, 1),
                ),
                $context,
            ),
            in_array($status, self::FILE_REFUSED, true) => $failure::permanent(
                $this->platform,
                sprintf(
                    'LinkedIn did not accept image %d of this post. Nothing was posted. Return the post to draft, take the image off and put another copy of it on.',
                    $number,
                ),
                $context,
            ),
            default => $failure::retryable(
                $this->platform,
                'LinkedIn did not take an image of this post. Nothing was posted.',
                $this->retryAfter($response),
                $context,
            ),
        };
    }

    private function request(string $baseUrl, ?int $timeout = null): PendingRequest
    {
        $timeout = max(1, min($timeout ?? $this->timeout, $this->timeout));

        return $this->http
            ->baseUrl($baseUrl)
            ->timeout($timeout)
            ->connectTimeout(min(10, $timeout))
            ->withoutRedirecting()
            ->acceptJson();
    }

    /**
     * @param  Closure(): Response  $call
     * @param  class-string<ChannelException>  $failure
     * @param  array<string, mixed>|null  $context  for the log, in place of the endpoint alone
     */
    private function send(string $endpoint, Closure $call, string $failure, bool $safeToRepeat, ?array $context = null): Response
    {
        $context ??= ['endpoint' => $endpoint];

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
                    context: $context,
                );
            }

            throw $this->outcomeUnknown(
                $failure,
                'The connection to LinkedIn failed or timed out after the request may have been sent.',
                $context,
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
     * @param  string|null  $refused  what the member is told about a refusal that has no message of its own here
     */
    private function failure(Response $response, string $failure, string $endpoint, #[SensitiveParameter] array $secrets, bool $safeToRepeat, bool $signingIn = false, ?string $refused = null): ChannelException
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
                $context,
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
                $context,
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
                : $refused ?? 'LinkedIn did not accept this. Return the post to draft, check the text and the link, and try again. The reason LinkedIn gave is in the log of this instance.',
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
