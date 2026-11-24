<?php

/*
| Reverse proxies whose X-Forwarded-* headers are trusted, for example
| "tailscale serve" or Caddy in front of the app. Empty means none: the app
| then ignores those headers. Set NOONGUN_TRUSTED_PROXIES to a comma separated
| list of addresses or CIDR ranges, or to "*" when the app port is only
| reachable through the proxy.
*/

$proxies = trim((string) env('NOONGUN_TRUSTED_PROXIES', ''));

return [

    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },

];
