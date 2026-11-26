<?php

namespace App\Domain\Channels\Contracts;

/**
 * An account the platform listed during connect() that was not connected,
 * and why. It holds what is needed to name the account to the user and
 * nothing else: never a token.
 */
final readonly class SkippedAccount
{
    /**
     * @param  string  $id  the platform's id of the account
     * @param  string  $name  what the platform calls the account
     * @param  string  $reason  a sentence for the user, with the next step where there is one
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $reason,
    ) {}

    /**
     * @return array{id: string, name: string, reason: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'reason' => $this->reason];
    }

    public static function fromArray(mixed $value): ?self
    {
        if (! is_array($value) || ! is_string($value['id'] ?? null) || ! is_string($value['name'] ?? null) || ! is_string($value['reason'] ?? null)) {
            return null;
        }

        return new self($value['id'], $value['name'], $value['reason']);
    }
}
