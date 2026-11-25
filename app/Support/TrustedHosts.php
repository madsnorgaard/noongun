<?php

namespace App\Support;

/**
 * The host names this instance answers to: the host of APP_URL, and the
 * loopback names, which the container health check uses. A request for any
 * other host is refused outside the local environment, so a forged Host
 * header never ends up in a link or a redirect.
 */
class TrustedHosts
{
    private const array LOOPBACK = ['^localhost$', '^127\.0\.0\.1$', '^\[::1\]$'];

    /**
     * @return list<string>
     */
    public static function patterns(): array
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return self::LOOPBACK;
        }

        return [...self::LOOPBACK, '^'.preg_quote($host).'$'];
    }
}
