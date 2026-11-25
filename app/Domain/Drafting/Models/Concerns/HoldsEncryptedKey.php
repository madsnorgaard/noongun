<?php

namespace App\Domain\Drafting\Models\Concerns;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use SensitiveParameter;

/**
 * For the two models on llm_credentials. A stored key can become unreadable:
 * APP_KEY was changed, or the database was restored next to another key.
 * Reading api_key then throws. These methods read it without throwing and
 * replace such a row, so the key can be entered again from the interface.
 *
 * @mixin Model
 */
trait HoldsEncryptedKey
{
    /**
     * The key, or null when the stored value cannot be decrypted. The
     * stored value itself is never returned or reported.
     */
    public function readableKey(): ?string
    {
        try {
            $key = $this->getAttribute('api_key');
        } catch (DecryptException) {
            return null;
        }

        return is_string($key) ? $key : null;
    }

    public function isReadable(): bool
    {
        return $this->readableKey() !== null;
    }

    /**
     * Sets or replaces the key for a provider, within the rows this model
     * sees. A row that cannot be read is removed first, because saving over
     * it would have to decrypt it to compare.
     */
    public static function store(string $provider, #[SensitiveParameter] string $apiKey): static
    {
        $existing = static::query()->where('provider', $provider)->first();

        if ($existing !== null && ! $existing->isReadable()) {
            $existing->delete();
        }

        return static::query()->updateOrCreate(['provider' => $provider], ['api_key' => $apiKey]);
    }
}
