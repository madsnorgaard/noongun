<?php

namespace App\Domain\Drafting\Generators;

use Prism\Prism\Enums\Provider;

/**
 * Where the providers behind PrismDraftGenerator differ, and the only place
 * that knows it: the options a request carries beside the prompt and the
 * schema, and the names a provider gives its errors. Everything else in the
 * generator is the same for every provider.
 *
 * See docs/platform-notes/llm.md for where each name comes from.
 */
final class PrismDialect
{
    /** The same request can succeed later. */
    private const TRANSIENT = [
        'overloaded_error', 'rate_limit_error', 'api_error', 'timeout_error',
        'server_error', 'service_unavailable_error', 'server_is_overloaded', 'slow_down', 'rate_limit_exceeded',
    ];

    /** The account behind the key has no credit or reached a spending limit. Waiting does not help. */
    private const OUT_OF_CREDIT = [
        'billing_error',
        'insufficient_quota', 'credit_balance_exhausted', 'billing_not_active',
        'organization_spend_limit_exceeded', 'project_spend_limit_exceeded', 'organization_usage_limit_exceeded',
    ];

    /**
     * The provider options of one request.
     *
     * OpenAI: the schema is sent as a strict schema, so the answer is held
     * to it; the answer is not stored on the provider's side as
     * conversation state, because one is never continued; effort is a
     * field of "reasoning".
     *
     * Every other provider: effort as a plain option, which is how Prism
     * passes it to Anthropic.
     *
     * @return array<string, mixed>
     */
    public static function requestOptions(string $provider, ?string $effort): array
    {
        if ($provider === Provider::OpenAI->value) {
            return [
                'schema' => ['strict' => true],
                'store' => false,
                ...($effort !== null ? ['reasoning' => ['effort' => $effort]] : []),
            ];
        }

        return $effort !== null ? ['effort' => $effort] : [];
    }

    public static function isTransient(?string ...$errorNames): bool
    {
        return array_intersect($errorNames, self::TRANSIENT) !== [];
    }

    public static function isOutOfCredit(?string ...$errorNames): bool
    {
        return array_intersect($errorNames, self::OUT_OF_CREDIT) !== [];
    }
}
