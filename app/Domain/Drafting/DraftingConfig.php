<?php

namespace App\Domain\Drafting;

use Illuminate\Support\Str;
use SensitiveParameter;

/**
 * Typed access to config('noongun.drafting'), with values that make no
 * sense (no token limit, a timeout of zero) replaced by safe ones. The only
 * class that reads these keys.
 */
final class DraftingConfig
{
    /** The most approved posts one target channel can get as examples, whatever is configured. */
    public const MAX_EXAMPLES_PER_CHANNEL = 10;

    /** The most characters the examples of one request can hold, whatever is configured. */
    public const MAX_EXAMPLE_CHARACTERS = 40000;

    public static function defaultProvider(): ?string
    {
        return self::string('provider');
    }

    /**
     * The provider names the operator lets a workspace choose from, as
     * written in the configuration. The default provider is always one of
     * them. DraftGenerators::offered() keeps those that exist.
     *
     * @return list<string>
     */
    public static function offeredProviders(): array
    {
        $offered = config('noongun.drafting.offered', []);
        $names = [];

        foreach ([self::defaultProvider(), ...(is_array($offered) ? $offered : [])] as $name) {
            if (is_string($name) && trim($name) !== '' && ! in_array(trim($name), $names, true)) {
                $names[] = trim($name);
            }
        }

        return $names;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function providers(): array
    {
        $providers = config('noongun.drafting.providers', []);
        $configured = [];

        foreach (is_array($providers) ? $providers : [] as $name => $entry) {
            if (is_string($name) && is_array($entry)) {
                $configured[$name] = $entry;
            }
        }

        return $configured;
    }

    public static function generatorClass(string $provider): ?string
    {
        return self::string("providers.{$provider}.generator");
    }

    public static function model(string $provider): ?string
    {
        return self::string("providers.{$provider}.model");
    }

    /**
     * The instance key the operator set in the environment, if any.
     */
    public static function instanceKey(string $provider): ?string
    {
        return self::string("providers.{$provider}.api_key");
    }

    /**
     * The name of a provider as a person reads it.
     */
    public static function label(string $provider): string
    {
        return self::string("providers.{$provider}.label") ?? Str::headline($provider);
    }

    /**
     * How the keys of a provider start, when that is known.
     */
    public static function keyPrefix(string $provider): ?string
    {
        return self::string("providers.{$provider}.key_prefix");
    }

    /**
     * The provider whose keys start the way the given key does, by the
     * longest "key_prefix" that fits, or null when none fits. A hint to
     * catch a key pasted under the wrong provider, and nothing more: a key
     * is never chosen or routed by its shape.
     */
    public static function providerWithKeysLike(#[SensitiveParameter] string $key): ?string
    {
        $found = null;
        $length = 0;

        foreach (array_keys(self::providers()) as $provider) {
            $prefix = self::keyPrefix($provider);

            if ($prefix !== null && strlen($prefix) > $length && str_starts_with($key, $prefix)) {
                $found = $provider;
                $length = strlen($prefix);
            }
        }

        return $found;
    }

    /**
     * How much effort the model spends on one answer, for providers that
     * take such a setting. Null leaves it to the provider's default.
     */
    public static function effort(string $provider): ?string
    {
        $effort = self::string("providers.{$provider}.effort");

        return $effort !== null && preg_match('/\A[a-z]{1,16}\z/', $effort) === 1 ? $effort : null;
    }

    public static function maxTokens(): int
    {
        return self::int('max_tokens', 16000, 256);
    }

    public static function timeoutSeconds(): int
    {
        return self::int('timeout', 120, 5);
    }

    /**
     * Empty when none is configured, which PromptRenderer refuses.
     */
    public static function promptVersion(): string
    {
        return self::string('prompt_version') ?? '';
    }

    /**
     * How many recently approved posts a draft request carries as examples
     * for each target channel. 0 sends none.
     */
    public static function examplesPerChannel(): int
    {
        return self::bounded('examples.per_channel', 5, self::MAX_EXAMPLES_PER_CHANNEL);
    }

    /**
     * The most characters all examples of one request hold together. 0
     * sends none.
     */
    public static function exampleCharacters(): int
    {
        return self::bounded('examples.max_characters', 6000, self::MAX_EXAMPLE_CHARACTERS);
    }

    public static function currency(): string
    {
        return strtoupper(substr(self::string('currency') ?? 'USD', 0, 3));
    }

    /**
     * Price per million tokens for a model, or null when none is set.
     * "cached_input" and "cache_write" are the prices of input tokens the
     * provider read from or wrote to its prompt cache, and are the input
     * price when the table does not give them.
     *
     * @return array{input: float, output: float, cached_input: float, cache_write: float}|null
     */
    public static function price(string $provider, string $model): ?array
    {
        $prices = config('noongun.drafting.prices');
        $price = is_array($prices) && is_array($prices[$provider] ?? null) ? ($prices[$provider][$model] ?? null) : null;

        if (! is_array($price)) {
            return null;
        }

        $input = self::amount($price['input'] ?? null);
        $output = self::amount($price['output'] ?? null);

        if ($input === null || $output === null) {
            return null;
        }

        return [
            'input' => $input,
            'output' => $output,
            'cached_input' => self::amount($price['cached_input'] ?? null) ?? $input,
            'cache_write' => self::amount($price['cache_write'] ?? null) ?? $input,
        ];
    }

    private static function amount(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value >= 0 ? (float) $value : null;
    }

    private static function string(string $key): ?string
    {
        $value = config("noongun.drafting.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * A whole number from 0 to $max. A value that is not a number gives
     * the default.
     */
    private static function bounded(string $key, int $default, int $max): int
    {
        $value = config("noongun.drafting.{$key}");

        return min($max, max(0, is_numeric($value) ? (int) $value : $default));
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.drafting.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
