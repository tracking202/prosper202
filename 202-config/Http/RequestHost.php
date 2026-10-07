<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The host the browser asked for, read from the Host header: `name[:port]`,
 * case folded, or null when the header is missing or is not a host.
 *
 * Two things are built from it, and both read it here: the Domain attribute
 * of a click cookie (CookieDomain) and this install's own URLs on the origin
 * the request arrived at (TrackingBaseUrl::forRequest(): a redirect
 * Location, the tracking202outbound cookie's value, the URLs stored with a
 * click). The header is the client's to write, so what it may hold is
 * narrow: a value carrying a path, credentials, a quote, whitespace, a
 * second port or anything else that is not a host reaches neither a header
 * nor a cookie (RequestHostSourceTest refuses any other reader).
 *
 * Accepted:
 *  - a host name: dot-separated labels of letters, digits, '-' and '_' (no
 *    label starts or ends with '-', none is longer than 63, 253 in all),
 *    with an optional trailing dot. Underscores are allowed because Docker
 *    and intranet names carry them and browsers request them;
 *  - an IPv4 address;
 *  - an IPv6 address in brackets;
 * each with an optional port of 1-65535 (`host:` is no port, RFC 3986).
 *
 * A host the client chose is still the client's choice: this is for the
 * client's own response (a cookie it keeps only for the host it asked for,
 * a redirect back to the host it asked for). Something sent to anyone else
 * — a password-reset link in an email — must not take its host from here.
 */
final class RequestHost
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function fromServer(array $server): ?string
    {
        $value = $server['HTTP_HOST'] ?? null;
        $host = is_string($value) ? self::parse($value) : null;
        if ($host === null) {
            return null;
        }

        return $host['port'] === null ? $host['name'] : $host['name'] . ':' . $host['port'];
    }

    /**
     * A Host header value taken apart, or null when it is not a host.
     *
     * @return array{name: string, port: ?int, ip: bool}|null name is
     *     bracketed for IPv6; ip tells an address from a host name
     */
    public static function parse(string $value): ?array
    {
        $value = strtolower($value);
        if ($value === '') {
            return null;
        }
        if ($value[0] === '[') {
            $close = strpos($value, ']');
            if ($close === false) {
                return null;
            }
            $address = substr($value, 1, $close - 1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return null;
            }
            $name = '[' . $address . ']';
            $rest = substr($value, $close + 1);
            $ip = true;
        } else {
            $colon = strpos($value, ':');
            $name = $colon === false ? $value : substr($value, 0, $colon);
            $rest = $colon === false ? '' : substr($value, $colon);
            $ip = filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
            if (!$ip && !self::isHostName($name)) {
                return null;
            }
        }
        $port = null;
        if ($rest !== '' && $rest !== ':') {
            if ($rest[0] !== ':' || !ctype_digit(substr($rest, 1))) {
                return null;
            }
            $port = (int) substr($rest, 1);
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        return ['name' => $name, 'port' => $port, 'ip' => $ip];
    }

    private static function isHostName(string $name): bool
    {
        $labels = str_ends_with($name, '.') ? substr($name, 0, -1) : $name;
        if ($labels === '' || strlen($labels) > 253) {
            return false;
        }
        foreach (explode('.', $labels) as $label) {
            if (preg_match('/^[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9_])?$/D', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
