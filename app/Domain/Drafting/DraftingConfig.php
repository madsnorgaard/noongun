<?php

namespace App\Domain\Drafting;

/**
 * Typed access to config('noongun.drafting'), with values that make no
 * sense (no token limit, a timeout of zero) replaced by safe ones. The only
 * class that reads these keys.
 */
final class DraftingConfig
{
    public static function defaultProvider(): ?string
    {
        return self::string('provider');
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
     * How much effort the model spends on one answer, for providers that
     * take such a setting. Null leaves it to the provider's default.
     */
    public static function effort(string $provider): ?string
    {
        $effort = self::string("providers.{$provider}.effort");

        return $effort !== null && preg_match('/^[a-z]{1,16}$/', $effort) === 1 ? $effort : null;
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

    public static function currency(): string
    {
        return strtoupper(substr(self::string('currency') ?? 'USD', 0, 3));
    }

    /**
     * Price per million tokens for a model, or null when none is set.
     *
     * @return array{input: float, output: float}|null
     */
    public static function price(string $provider, string $model): ?array
    {
        $prices = config('noongun.drafting.prices');
        $price = is_array($prices) && is_array($prices[$provider] ?? null) ? ($prices[$provider][$model] ?? null) : null;

        if (! is_array($price) || ! is_numeric($price['input'] ?? null) || ! is_numeric($price['output'] ?? null)) {
            return null;
        }

        if ((float) $price['input'] < 0 || (float) $price['output'] < 0) {
            return null;
        }

        return ['input' => (float) $price['input'], 'output' => (float) $price['output']];
    }

    private static function string(string $key): ?string
    {
        $value = config("noongun.drafting.{$key}");

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function int(string $key, int $default, int $min): int
    {
        $value = config("noongun.drafting.{$key}");

        return max($min, is_numeric($value) ? (int) $value : $default);
    }
}
