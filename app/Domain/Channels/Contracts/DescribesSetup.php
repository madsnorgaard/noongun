<?php

namespace App\Domain\Channels\Contracts;

/**
 * Optional for a ChannelAdapter. Lets the Channels page explain what the
 * instance owner still has to configure before a platform can be connected,
 * without the page knowing anything about the platform.
 */
interface DescribesSetup
{
    /**
     * The environment variables that must be set before this platform can be
     * connected and are not. Empty when the adapter is ready.
     *
     * @return list<string>
     */
    public function missingSettings(): array;

    /**
     * The exact callback address the instance owner registers with the
     * platform. It is the one sent in authorizationUrl() and connect().
     */
    public function redirectUri(): string;
}
