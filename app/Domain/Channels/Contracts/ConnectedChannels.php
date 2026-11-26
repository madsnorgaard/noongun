<?php

namespace App\Domain\Channels\Contracts;

use App\Domain\Channels\Models\Channel;
use Illuminate\Support\Collection;

/**
 * What connect() saved, together with the accounts the platform listed that
 * were left out. An adapter with nothing to report returns a plain
 * Collection of channels; one that left accounts out returns this.
 *
 * @extends Collection<int, Channel>
 */
final class ConnectedChannels extends Collection
{
    /** @var list<SkippedAccount> */
    private array $skipped = [];

    /**
     * @param  iterable<int, Channel>  $channels
     * @param  list<SkippedAccount>  $skipped
     */
    public static function with(iterable $channels, array $skipped): self
    {
        $connected = new self($channels);
        $connected->skipped = $skipped;

        return $connected;
    }

    /**
     * @return list<SkippedAccount>
     */
    public function skipped(): array
    {
        return $this->skipped;
    }
}
