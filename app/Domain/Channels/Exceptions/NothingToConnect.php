<?php

namespace App\Domain\Channels\Exceptions;

use App\Domain\Channels\Contracts\SkippedAccount;

/**
 * The grant gave access to no account that can be connected. It names the
 * accounts the platform did list and why each was left out, so the user is
 * told more than that nothing happened.
 */
final class NothingToConnect extends ChannelException
{
    /** @var list<SkippedAccount> */
    private array $skipped = [];

    /**
     * @param  list<SkippedAccount>  $skipped
     */
    public static function because(string $platform, string $message, array $skipped = []): self
    {
        $exception = self::permanent($platform, $message, ['skipped_accounts' => count($skipped)]);
        $exception->skipped = $skipped;

        return $exception;
    }

    /**
     * @return list<SkippedAccount>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
