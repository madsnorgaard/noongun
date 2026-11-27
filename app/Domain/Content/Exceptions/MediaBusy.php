<?php

namespace App\Domain\Content\Exceptions;

use RuntimeException;

/**
 * An upload that was not looked at, because as many images as this
 * instance decodes at once were already being decoded. Nothing is wrong
 * with the file: sending it again a little later works.
 */
class MediaBusy extends RuntimeException
{
    private function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public static function forUser(int $retryAfterSeconds): self
    {
        return new self(self::text('Another image of yours is still being processed. This one was not looked at. Try it again in a moment.'), $retryAfterSeconds);
    }

    public static function forInstance(int $retryAfterSeconds): self
    {
        return new self(self::text('The server is processing as many images as it can at once. This one was not looked at. Try it again in a moment.'), $retryAfterSeconds);
    }

    private static function text(string $key): string
    {
        $text = __($key);

        return is_string($text) ? $text : $key;
    }
}
