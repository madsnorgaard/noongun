<?php

namespace App\Domain\Channels\Adapters\LinkedIn;

/**
 * The address LinkedIn names for the upload of one image.
 *
 * It comes out of a response, and the image and the member's token are sent
 * to it, so it is not believed. It is used only when it is a plain HTTPS
 * address on a host that is allowed: `https://`, a host name that is one of
 * the allowed names or below one, port 443 or none, then a path, which may
 * have a query. Everything else is refused before anything is sent: another
 * scheme, a user name or password, another port, an IP address, a host name
 * with an empty part, an address without a path, a fragment, a backslash,
 * white space, and any character outside printable ASCII.
 *
 * The list of allowed names is not believed blindly either. A name of one
 * part (`com`) would allow every host below it, so a list that is empty or
 * holds anything that is not a host name of at least two parts allows
 * nothing at all.
 *
 * The same rule is in scripts/spike/linkedin/publish-and-delete.sh.
 */
final class UploadAddress
{
    private const MAX_LENGTH = 4096;

    /**
     * The host is letters, digits, dots and hyphens and nothing else, so an
     * at sign, a bracket or a percent sign cannot be part of it. What
     * follows it must be a port and then a path, or a path. A query straight
     * after the host is refused: parsers agree on where the host ends at a
     * slash, and an upload address of LinkedIn always has a path.
     */
    private const SHAPE = '/^https:\/\/(?<host>[A-Za-z0-9.-]{1,253})(?::(?<port>[0-9]{1,5}))?\/[\x21-\x7E]*\z/';

    /** A host name of at least two parts, none of them empty, in lower case. */
    private const HOST_NAME = '/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+\z/';

    private function __construct(
        public readonly ?string $url,
        public readonly ?string $host,
        public readonly ?string $refusal,
    ) {}

    /**
     * @param  list<string>  $allowedHosts  host names; each allows itself and every name below it
     */
    public static function from(mixed $url, array $allowedHosts): self
    {
        if (! self::usable($allowedHosts)) {
            return new self(null, null, 'allow_list_invalid');
        }

        if (! is_string($url) || $url === '') {
            return new self(null, null, 'missing');
        }

        if (strlen($url) > self::MAX_LENGTH) {
            return new self(null, null, 'too_long');
        }

        if (str_contains($url, '\\') || str_contains($url, '#')) {
            return new self(null, null, 'backslash_or_fragment');
        }

        if (preg_match(self::SHAPE, $url, $match) !== 1) {
            return new self(null, null, 'not_a_plain_https_address');
        }

        $host = strtolower($match['host']);

        if (($match['port'] ?? '') !== '' && $match['port'] !== '443') {
            return new self(null, $host, 'port');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false || preg_match('/^[0-9.]+\z/', $host) === 1) {
            return new self(null, $host, 'ip_address');
        }

        if (preg_match(self::HOST_NAME, $host) !== 1) {
            return new self(null, null, 'not_a_host_name');
        }

        // The HTTP client reads the address with its own parser. Both must
        // see the same host, or the check was of something else.
        if (strtolower((string) parse_url($url, PHP_URL_HOST)) !== $host) {
            return new self(null, $host, 'ambiguous_host');
        }

        foreach ($allowedHosts as $allowed) {
            $allowed = strtolower(trim($allowed));

            if ($host === $allowed || str_ends_with($host, '.'.$allowed)) {
                return new self($url, $host, null);
            }
        }

        return new self(null, $host, 'host_not_allowed');
    }

    /**
     * Whether the list can be used: at least one name, and every name a
     * host name of two parts or more. Capitals and white space around a
     * name are let through; a dot before or after it is not.
     *
     * @param  array<array-key, mixed>  $allowedHosts
     */
    public static function usable(array $allowedHosts): bool
    {
        if ($allowedHosts === []) {
            return false;
        }

        foreach ($allowedHosts as $allowed) {
            if (! is_string($allowed) || preg_match(self::HOST_NAME, strtolower(trim($allowed))) !== 1) {
                return false;
            }
        }

        return true;
    }

    public function allowed(): bool
    {
        return $this->url !== null;
    }
}
