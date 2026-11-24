<?php

namespace App\Domain\Channels;

enum ChannelStatus: string
{
    /** Connected with a working token. The only status that may publish. */
    case Active = 'active';

    /**
     * Returned by a connect, with a token, but not yet confirmed by the
     * user. Never publishes. Becomes active when the user keeps it, and is
     * removed when the user declines or does not answer in time.
     */
    case Pending = 'pending';

    /** The token expired, was revoked or lost a permission. A human must connect again. */
    case NeedsReconnect = 'needs_reconnect';

    /** Disconnected by the user. Tokens are erased; the row stays for its published variants. */
    case Disconnected = 'disconnected';

    public function canPublish(): bool
    {
        return $this === self::Active;
    }

    /**
     * True for a channel the user chose to have connected and has not
     * disconnected since. Connecting again renews such a channel without
     * asking; every other account has to be confirmed first.
     */
    public function isConfirmed(): bool
    {
        return $this === self::Active || $this === self::NeedsReconnect;
    }
}
