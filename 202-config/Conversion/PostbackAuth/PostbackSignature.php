<?php

declare(strict_types=1);

namespace Prosper202\Conversion\PostbackAuth;

/**
 * The signed form of a postback, for a sender that can compute an HMAC: the
 * request carries `p202_ts` (Unix seconds) and `p202_sig`. The key is the
 * account's signing secret (PostbackSigningSecret), which no URL carries,
 * so neither a URL that lands in a log nor a pixel's token signs anything.
 *
 * p202_sig = lower-case hex HMAC-SHA256, keyed with the account's signing
 * secret (its 64 characters as text), of
 *
 *     P202-POSTBACK-V1 \n <endpoint> \n <canonical query>
 *
 * <endpoint> is the script's name without `.php`: pb, gpb, px, gpx or upx,
 * so a request signed for one cannot be replayed at another. <canonical
 * query> is every parameter of the URL except p202_sig, p202_ts included:
 * each name and value decoded, then percent-encoded as RFC 3986 says
 * (rawurlencode(): A-Z a-z 0-9 - . _ ~ as they are, a space as %20), joined
 * `name=value`, sorted by name and then by value as bytes, joined with `&`.
 * A parameter sent twice is in it twice.
 *
 * p202_ts must be within WINDOW seconds of the server's clock. Inside the
 * window the same request can be sent again: the conversion writer
 * deduplicates it (by its transaction id, or once per click without one),
 * as it does a network's own retry.
 */
final class PostbackSignature
{
    public const VERSION = 'P202-POSTBACK-V1';
    public const WINDOW = 300;
    public const ENDPOINTS = ['pb', 'gpb', 'px', 'gpx', 'upx'];

    private function __construct()
    {
    }

    /** @param list<array{0: string, 1: string}> $pairs decoded parameters, p202_sig left out */
    public static function stringToSign(string $endpoint, array $pairs): string
    {
        if (!in_array($endpoint, self::ENDPOINTS, true)) {
            throw new \InvalidArgumentException('"' . $endpoint . '" is not a postback endpoint');
        }
        $encoded = [];
        foreach ($pairs as [$name, $value]) {
            if (strtolower($name) === PresentedCredentials::SIGNATURE) {
                continue;
            }
            $encoded[] = [rawurlencode($name), rawurlencode($value)];
        }
        // strcmp(), not <=>: PHP compares two numeric strings as numbers.
        usort($encoded, static fn (array $a, array $b): int => strcmp($a[0], $b[0]) ?: strcmp($a[1], $b[1]));

        return self::VERSION . "\n" . $endpoint . "\n"
            . implode('&', array_map(static fn (array $p): string => $p[0] . '=' . $p[1], $encoded));
    }

    /** @param list<array{0: string, 1: string}> $pairs */
    public static function sign(string $endpoint, array $pairs, #[\SensitiveParameter] string $secret): string
    {
        return hash_hmac('sha256', self::stringToSign($endpoint, $pairs), $secret);
    }

    /**
     * Whether $signature signs $pairs for $endpoint under $secret: compared
     * in constant time, upper-case hex accepted.
     *
     * @param list<array{0: string, 1: string}> $pairs
     */
    public static function matches(string $endpoint, array $pairs, string $signature, #[\SensitiveParameter] string $secret): bool
    {
        if (preg_match('/\A[0-9a-fA-F]{64}\z/D', $signature) !== 1) {
            return false;
        }

        return hash_equals(self::sign($endpoint, $pairs, $secret), strtolower($signature));
    }

    /**
     * A timestamp as sent, read: Unix seconds as plain digits, or null for
     * anything else ("1.7e9", " 1700000000", "-5").
     */
    public static function timestamp(string $sent): ?int
    {
        return preg_match('/\A[0-9]{1,12}\z/D', $sent) === 1 ? (int) $sent : null;
    }

    /**
     * $query (name => value, as a sender holds them before encoding) with
     * p202_ts and p202_sig added: what a sender in PHP sends, and what the
     * tests and the documentation's example are checked against.
     *
     * @param array<string, string> $query
     */
    public static function signedQuery(string $endpoint, array $query, #[\SensitiveParameter] string $secret, int $now): string
    {
        $query[PresentedCredentials::TIMESTAMP] = (string) $now;
        $pairs = [];
        foreach ($query as $name => $value) {
            $pairs[] = [(string) $name, $value];
        }
        $query[PresentedCredentials::SIGNATURE] = self::sign($endpoint, $pairs, $secret);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
