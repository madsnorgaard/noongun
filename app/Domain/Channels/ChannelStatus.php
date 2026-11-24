<?php

namespace App\Domain\Channels;

enum ChannelStatus: string
{
    /** Connected with a working token. The only status that may publish. */
    case Active = 'active';

    /** The token expired, was revoked or lost a permission. A human must connect again. */
    case NeedsReconnect = 'needs_reconnect';

    /** Disconnected by the user. Tokens are erased; the row stays for its published variants. */
    case Disconnected = 'disconnected';

    public function canPublish(): bool
    {
        return $this === self::Active;
    }
}
