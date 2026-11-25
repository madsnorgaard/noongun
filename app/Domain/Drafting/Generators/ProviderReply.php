<?php

namespace App\Domain\Drafting\Generators;

use App\Domain\Drafting\DraftUsage;
use Illuminate\Http\Client\Response;

/**
 * What the last HTTP answer of a provider said, reduced to a few plain
 * fields. Prism turns some answers into an exception that no longer holds
 * them: a 429 loses the reason (too fast, or out of credit), and an answer
 * that was cut off at the token limit loses its usage, although it was paid
 * for. ProviderReplies reads them from the answer itself.
 *
 * Nothing of the request is kept, so no header and no key, and nothing of
 * what the model wrote. The fields are those of an error body
 * (`error.type`, `error.code`) and of the OpenAI Responses API (`status`,
 * `incomplete_details.reason`, `usage`, `model`).
 */
final readonly class ProviderReply
{
    private const CUT_OFF = 'max_output_tokens';

    private function __construct(
        public int $status,
        public ?string $errorType,
        public ?string $errorCode,
        public ?string $incompleteReason,
        private ?string $model,
        private ?int $inputTokens,
        private ?int $outputTokens,
        private int $cacheReadTokens,
        private int $cacheWriteTokens,
    ) {}

    public static function from(Response $response): self
    {
        $body = $response->json();
        $body = is_array($body) ? $body : [];

        return new self(
            status: $response->status(),
            errorType: self::word(data_get($body, 'error.type')),
            errorCode: self::word(data_get($body, 'error.code')),
            incompleteReason: data_get($body, 'status') === 'incomplete' ? (self::word(data_get($body, 'incomplete_details.reason')) ?? 'unknown') : null,
            model: self::word(data_get($body, 'model')),
            inputTokens: self::count(data_get($body, 'usage.input_tokens')),
            outputTokens: self::count(data_get($body, 'usage.output_tokens')),
            cacheReadTokens: self::count(data_get($body, 'usage.input_tokens_details.cached_tokens')) ?? 0,
            cacheWriteTokens: self::count(data_get($body, 'usage.input_tokens_details.cache_write_tokens')) ?? 0,
        );
    }

    /**
     * The answer stopped because it reached the token limit.
     */
    public function wasCutOff(): bool
    {
        return $this->incompleteReason === self::CUT_OFF;
    }

    /**
     * What the answer used, when it says so. Cached tokens are part of the
     * input tokens in this shape.
     */
    public function usage(string $provider, string $requestedModel): ?DraftUsage
    {
        if ($this->inputTokens === null || $this->outputTokens === null) {
            return null;
        }

        $cached = min($this->inputTokens, $this->cacheReadTokens + $this->cacheWriteTokens);
        $read = min($cached, $this->cacheReadTokens);

        return new DraftUsage(
            $provider,
            $this->model ?? $requestedModel,
            $this->inputTokens,
            $this->outputTokens,
            1,
            $read,
            $cached - $read,
        );
    }

    private static function word(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/@-]{0,127}\z/', $value) === 1 ? $value : null;
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
