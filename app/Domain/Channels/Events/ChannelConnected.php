<?php

namespace App\Domain\Channels\Events;

/**
 * A channel holds a token from a new sign-in and is active: a connected
 * channel was renewed, or the user confirmed a pending one. Fired by
 * Channel::recordConnection() and Channel::confirm(), inside the workspace
 * of the channel. Not fired for a channel that is saved as pending.
 */
final readonly class ChannelConnected
{
    public function __construct(
        public int $workspaceId,
        public int $channelId,
    ) {}
}
