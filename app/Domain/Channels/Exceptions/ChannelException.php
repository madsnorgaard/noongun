<?php

namespace App\Domain\Channels\Exceptions;

use RuntimeException;

/**
 * A platform call failed. Carries what callers need to decide what to do
 * next, without them knowing the platform.
 *
 * Messages and context are safe to log and to store: the constructor redacts
 * anything that looks like a credential. There is deliberately no $previous
 * argument, because HTTP client exceptions carry request URLs and headers,
 * which is where tokens live.
 */
class ChannelException extends RuntimeException
{
    public const REDACTED = '[redacted]';

    private const SENSITIVE_KEY = '/token|secret|password|authorization|cookie|appsecret_proof|signature|api[_-]?key/i';

    /**
     * name=value with any credential-like name, or name: value where the
     * name is a compound such as access_token, so that prose like
     * "Invalid token: session expired" stays readable.
     */
    private const SENSITIVE_PARAMETER = '/\b((?:[a-z_]*token|[a-z_]*secret|appsecret_proof|password|api_key)\s*=\s*["\']?|(?:[a-z]+_token|[a-z]+_secret|appsecret_proof|password|api_key)["\']?\s*:\s*["\']?)[^&\s"\',}]+/i';

    /** An OAuth authorization code, only when written as a query parameter. */
    private const CODE_PARAMETER = '/([?&]code=)[^&\s"\']+/i';

    private const BEARER = '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]+/i';

    /** @var array<string, mixed> */
    private readonly array $context;

    /**
     * @param  string  $platform  platform name as in config('noongun.channels')
     * @param  string  $message  safe summary, shown to the user and stored on the variant
     * @param  bool  $retryable  true when the same call may succeed later (rate limit, timeout, server error)
     * @param  int|null  $retryAfterSeconds  the platform's own advice on how long to wait, if it gave any
     * @param  bool  $requiresReconnect  true when the token is expired or revoked and a human must connect again
     * @param  array<string, mixed>  $context  platform error code, HTTP status and similar; never credentials
     */
    final public function __construct(
        public readonly string $platform,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?int $retryAfterSeconds = null,
        public readonly bool $requiresReconnect = false,
        array $context = [],
    ) {
        parent::__construct(self::redact($message));

        $this->context = self::redactContext($context);
    }

    /**
     * A failure worth trying again: rate limit, timeout, platform outage.
     *
     * @param  array<string, mixed>  $context
     */
    public static function retryable(string $platform, string $message, ?int $retryAfterSeconds = null, array $context = []): static
    {
        return new static($platform, $message, true, $retryAfterSeconds, false, $context);
    }

    /**
     * A failure that will repeat until a human changes something.
     *
     * @param  array<string, mixed>  $context
     */
    public static function permanent(string $platform, string $message, array $context = []): static
    {
        return new static($platform, $message, false, null, false, $context);
    }

    /**
     * The token is expired, revoked or lacks a permission. Never retryable.
     *
     * @param  array<string, mixed>  $context
     */
    public static function reconnectRequired(string $platform, string $message, array $context = []): static
    {
        return new static($platform, $message, false, null, true, $context);
    }

    /**
     * Picked up by Laravel's exception handler and added to the log entry.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'platform' => $this->platform,
            'retryable' => $this->retryable,
            'retry_after_seconds' => $this->retryAfterSeconds,
            'requires_reconnect' => $this->requiresReconnect,
            ...$this->context,
        ];
    }

    /**
     * Removes credentials from free text such as a platform error message or
     * a URL. Use it on anything from a platform before it is stored or logged.
     */
    public static function redact(string $text): string
    {
        $text = (string) preg_replace(self::SENSITIVE_PARAMETER, '$1'.self::REDACTED, $text);
        $text = (string) preg_replace(self::CODE_PARAMETER, '$1'.self::REDACTED, $text);

        return (string) preg_replace(self::BEARER, '$1 '.self::REDACTED, $text);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @return array<string, mixed>
     */
    private static function redactContext(array $context): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;

            $safe[$key] = match (true) {
                preg_match(self::SENSITIVE_KEY, $key) === 1 => self::REDACTED,
                is_array($value) => self::redactContext($value),
                is_string($value) => self::redact($value),
                is_scalar($value), $value === null => $value,
                default => get_debug_type($value),
            };
        }

        return $safe;
    }
}
