<?php

/*
| Reverse proxies whose X-Forwarded-For and X-Forwarded-Proto headers are
| trusted, for example "tailscale serve" or Caddy in front of the app. Empty
| means none: the app then ignores those headers. Set NOONGUN_TRUSTED_PROXIES
| to a comma separated list of addresses or CIDR ranges, or to "*" when the
| app port is only reachable through the proxy.
|
| X-Forwarded-Host and X-Forwarded-Port are never trusted, see
| bootstrap/app.php. The host comes from the Host header, which the proxy
| must pass on unchanged and which must match APP_URL.
*/

$proxies = trim((string) env('NOONGUN_TRUSTED_PROXIES', ''));

return [

    'proxies' => match (true) {
        $proxies === '' => null,
        $proxies === '*' => '*',
        default => array_values(array_filter(array_map('trim', explode(',', $proxies)))),
    },

];
