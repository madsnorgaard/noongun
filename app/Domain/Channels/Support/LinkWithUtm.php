<?php

namespace App\Domain\Channels\Support;

/**
 * Adds the UTM values of a variant to its link, the same way on every
 * platform.
 */
final class LinkWithUtm
{
    /**
     * A value already on the link is kept.
     *
     * @param  array<array-key, mixed>  $utm
     */
    public static function apply(string $link, array $utm): string
    {
        $utm = array_filter($utm, fn (mixed $value): bool => is_scalar($value) && (string) $value !== '');

        if ($utm === []) {
            return $link;
        }

        [$base, $fragment] = array_pad(explode('#', $link, 2), 2, null);
        [$path, $query] = array_pad(explode('?', $base, 2), 2, null);

        parse_str($query ?? '', $existing);

        $query = http_build_query([...$utm, ...$existing], '', '&', PHP_QUERY_RFC3986);

        return $path.'?'.$query.($fragment === null ? '' : '#'.$fragment);
    }
}
