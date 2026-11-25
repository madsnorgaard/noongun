<?php

namespace App\Domain\Drafting;

use LogicException;
use SensitiveParameter;

/**
 * An API key for one LLM provider, and where it came from. The value is
 * private: it does not show in dumps, in JSON or in a stack trace, and the
 * object refuses to be serialized, so it cannot end up in a queue payload or
 * a cache. Only a DraftGenerator calls reveal(), at the moment it builds the
 * request.
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
