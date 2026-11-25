<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

use InvalidArgumentException;

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
    /**
     * A hashtag, or any single reserved character.
     *
     * A hashtag is kept only in its plainest form: a # at the start of the
     * text or after white space, followed by letters and digits, and ending
     * there. So the # of an address (page#section) and of "C#" is escaped,
     * and so is a tag with an underscore, because the underscore is syntax
     * too and nothing documents how the two meet. What is not kept as a
     * hashtag still reads the same; it is only not a link.
     */
    private const RESERVED = '/(?<![^\s])#[\p{L}\p{N}]+(?![\p{L}\p{N}_])|[|{}@\[\]()<>#\\\\*_~]/u';

    /**
     * Makes what an author typed safe to send as commentary. Everything that
     * is syntax is escaped, which also means that a typed mention (an at
     * sign, a name in square brackets and a URN in round ones) is sent as
     * plain text: Noon Gun does not mention people or organisations.
     *
     * @throws InvalidArgumentException when the text is not valid UTF-8, so that it is never sent as an empty post
     */
    public static function escape(string $text): string
    {
        $escaped = preg_replace_callback(
            self::RESERVED,
            fn (array $match): string => strlen($match[0]) > 1 ? $match[0] : '\\'.$match[0],
            $text,
        );

        if ($escaped === null) {
            throw new InvalidArgumentException('The text is not valid UTF-8.');
        }

        return $escaped;
    }
}
