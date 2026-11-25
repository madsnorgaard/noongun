<?php

namespace App\Domain\Drafting\Exceptions;

use App\Domain\Channels\Exceptions\ChannelException;
use App\Domain\Drafting\DraftUsage;
use RuntimeException;
use SensitiveParameter;

/**
 * Drafting failed. Carries what callers need to decide what to do next,
 * without them knowing the provider.
 *
 * Messages and context are safe to log, to store on a draft run and to show
 * to the user: the constructor removes the given secrets and anything that
 * looks like a credential. There is deliberately no $previous argument,
 * because HTTP client and vendor exceptions carry request headers, which is
 * where the API key lives. A generator catches the vendor exception, reads
 * what it needs and throws one of these.
 *
 * $retryable says that the same request may succeed later (a rate limit, an
 * overloaded provider, a timeout). Drafting creates nothing outside the
 * application, so asking again is always safe; it only costs tokens.
 *
 * $usage is what the failed attempts used, when the provider reported it,
 * so that a failed run still records its cost.
 */
class DraftingException extends RuntimeException
{
    public const REDACTED = ChannelException::REDACTED;

    /**
     * Context key for what the operator of the instance needs to put the
     * failure right: a setting to change, what the provider said. It is
     * redacted like all context and goes to the log. It is never part of
     * the message, which every member of the workspace reads.
     */
    public const OPERATOR_DETAIL = 'operator_detail';

    private const SENSITIVE_KEY = '/token(?!s)|secret|password|authorization|cookie|signature|api[_-]?key|credential/i';

    /** A key header as an HTTP client prints it. */
    private const KEY_HEADER = '/\b(x-api-key|api-key|x-goog-api-key)(["\']?\s*[:=]\s*["\']?)[^\s"\',}\]]+/i';

    /** The key formats of the providers Prism supports, wherever they appear. */
    private const KEY_SHAPED = '/\b(sk|rk|pk|gsk|xai|AIza)[-_][A-Za-z0-9_\-]{12,}/';

    /** @var array<string, mixed> */
    private readonly array $context;

    /**
     * @param  string  $provider  provider name as in config('noongun.drafting.providers')
     * @param  string  $message  safe summary, stored on the draft run and shown to the user
     * @param  bool  $retryable  true when the same request may succeed later
     * @param  DraftUsage|null  $usage  tokens the failed attempts used, if known
     * @param  array<string, mixed>  $context  HTTP status, provider error type and similar; never credentials or prompt text
     * @param  list<string>  $secrets  values that must not appear anywhere, such as the key that was used
     */
    final public function __construct(
        public readonly string $provider,
        string $message,
        public readonly bool $retryable = false,
        public readonly ?DraftUsage $usage = null,
        array $context = [],
        #[SensitiveParameter] array $secrets = [],
    ) {
        parent::__construct(self::redact($message, ...$secrets));

        $this->context = self::redactContext($context, $secrets);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $secrets
     */
    public static function retryable(string $provider, string $message, ?DraftUsage $usage = null, array $context = [], #[SensitiveParameter] array $secrets = []): static
    {
        return new static($provider, $message, true, $usage, $context, $secrets);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  list<string>  $secrets
     */
    public static function permanent(string $provider, string $message, ?DraftUsage $usage = null, array $context = [], #[SensitiveParameter] array $secrets = []): static
    {
        return new static($provider, $message, false, $usage, $context, $secrets);
    }

    /**
     * Picked up by Laravel's exception handler and added to the log entry.
     *
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return [
            'provider' => $this->provider,
            'retryable' => $this->retryable,
            'usage' => $this->usage?->toArray(),
            ...$this->context,
        ];
    }

    public function operatorDetail(): ?string
    {
        $detail = $this->context[self::OPERATOR_DETAIL] ?? null;

        return is_string($detail) && $detail !== '' ? $detail : null;
    }

    /**
     * Removes the given secrets and anything shaped like a credential from
     * free text. Use it on everything a provider or an HTTP client said
     * before it is stored, logged or shown.
     */
    public static function redact(string $text, #[SensitiveParameter] string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, self::REDACTED, $text);
            }
        }

        $text = (string) preg_replace(self::KEY_HEADER, '$1$2'.self::REDACTED, $text);
        $text = (string) preg_replace(self::KEY_SHAPED, self::REDACTED, $text);

        return ChannelException::redact($text);
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @param  list<string>  $secrets
     * @return array<string, mixed>
     */
    private static function redactContext(array $context, #[SensitiveParameter] array $secrets): array
    {
        $safe = [];

        foreach ($context as $key => $value) {
            $key = (string) $key;

            $safe[$key] = match (true) {
                preg_match(self::SENSITIVE_KEY, $key) === 1 => self::REDACTED,
                is_array($value) => self::redactContext($value, $secrets),
                is_string($value) => self::redact($value, ...$secrets),
                is_scalar($value), $value === null => $value,
                default => get_debug_type($value),
            };
        }

        return $safe;
    }
}
