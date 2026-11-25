<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

/**
 * The "little" text format of the Posts API commentary field. In it the
 * characters | { } @ [ ] ( ) < > # \ * _ ~ are syntax, and each must be
 * written with a backslash to be read as plain text.
 *
 * Source: learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/little-text-format,
 * see docs/platform-notes/linkedin.md.
 */
final class LittleText
{
    /** A hashtag as the author typed it, or any single reserved character. */
    private const RESERVED = '/#[\p{L}\p{N}_]+|[|{}@\[\]()<>#\\\\*_~]/u';

    /**
     * Makes what an author typed safe to send as commentary. A hashtag
     * (# followed by a word) is left as it is, so it stays a hashtag.
     * Everything else that is syntax is escaped, which also means a typed
     * mention such as @[Name](urn:li:person:x) is sent as plain text: Noon
     * Gun does not mention people or organisations.
     */
    public static function escape(string $text): string
    {
        return (string) preg_replace_callback(
            self::RESERVED,
            fn (array $match): string => strlen($match[0]) > 1 ? $match[0] : '\\'.$match[0],
            $text,
        );
    }
}
