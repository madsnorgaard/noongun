<?php

namespace App\Domain\Drafting;

use LogicException;
use SensitiveParameter;

/**
 * An API key for one LLM provider, and where it came from. The value is
 * private: it does not show in dumps, in JSON or in a stack trace, and the
 * object refuses to be serialized, so it cannot end up in a queue payload or
 * a cache. Only a DraftGenerator reads the value, at the moment it builds
 * the request, and it does so with revealFor(): a key is given out only for
 * the provider it belongs to, so a key of one provider cannot be sent to
 * another whatever a caller mixes up.
 */
final readonly class LlmKey
{
    public function __construct(
        public string $provider,
        #[SensitiveParameter] private string $value,
        public CredentialScope $scope,
    ) {}

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * The value, for a request to the given provider and no other.
     *
     * @throws LogicException when the key belongs to another provider
     */
    public function revealFor(string $provider): string
    {
        if ($provider !== $this->provider) {
            throw new LogicException("An LLM key for [{$this->provider}] was asked for a request to [{$provider}]. A key is only used with its own provider.");
        }

        return $this->value;
    }

    /**
     * @return array{provider: string, scope: string, value: string}
     */
    public function __debugInfo(): array
    {
        return ['provider' => $this->provider, 'scope' => $this->scope->value, 'value' => '[redacted]'];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        throw new LogicException('An LLM key cannot be serialized. Resolve it again where it is needed.');
    }
}
