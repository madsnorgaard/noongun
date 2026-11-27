<?php

namespace App\Domain\Channels\Events;

/**
 * The platform confirmed that the token of an active channel is good, and
 * what it may do was recorded on the channel. Fired by CheckChannelTokenJob
 * inside the workspace of the channel. Not fired when the check failed or
 * the channel was marked as needing a reconnect.
 */
final readonly class ChannelTokenVerified
{
    public function __construct(
        public int $workspaceId,
        public int $channelId,
    ) {}
}
