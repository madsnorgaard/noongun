<?php

namespace App\Domain\Channels\Exceptions;

use RuntimeException;

/**
 * ChannelManager could not give an adapter for a platform: it is not
 * configured, its class is missing, or the class is not a ChannelAdapter.
 */
class ChannelAdapterNotAvailable extends RuntimeException
{
    public static function notConfigured(string $platform): self
    {
        return new self("No channel adapter is configured for platform [{$platform}]. Add it to config/noongun.php under channels.");
    }

    public static function classMissing(string $platform, string $class): self
    {
        return new self("The channel adapter for platform [{$platform}] is configured as [{$class}], but that class does not exist.");
    }

    public static function notAnAdapter(string $platform, string $class): self
    {
        return new self("The channel adapter for platform [{$platform}] is [{$class}], which does not implement ChannelAdapter.");
    }

    public static function platformMismatch(string $platform, string $class, string $reported): self
    {
        return new self("The channel adapter [{$class}] is registered for platform [{$platform}] but reports platform [{$reported}].");
    }
}
