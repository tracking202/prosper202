<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The visitor's IP address, as every click endpoint, pixel, page and audit
 * row records it: the one place in the served tree that reads a forwarding
 * header (ForwardedIpHeaderReadTest refuses a read anywhere else).
 *
 * The rule is the one connect.php and connect2.php each applied by
 * rewriting $_SERVER['HTTP_X_FORWARDED_FOR'] in place, which a dozen sites
 * then read back with their own fallbacks (`?? REMOTE_ADDR`, `?? '0.0.0.0'`,
 * nothing at all in offrtr.php): they agreed only because the bootstrap had
 * already run, and none of them checked the value was an address, so a
 * header of `nope` was stored in 202_ips and handed to the GEO, ISP and
 * filter lookups as one.
 *
 *  1. The first non-empty header of FORWARDING_HEADERS, most specific first:
 *     a CDN's own header (Cloudflare, Sucuri, a cluster) before the generic
 *     ones, X-Forwarded-For last and ignored when it names this server.
 *  2. Its leftmost hop (a proxy chain is `client, proxy1, proxy2`), trimmed.
 *  3. Kept only when it parses as an IPv4 or IPv6 address, in canonical form
 *     (inet_ntop), so `2001:DB8::1` and `2001:db8:0::1` are one visitor.
 *     A hop with a port, `unknown`, or anything else that is not an
 *     address falls back to REMOTE_ADDR, under the same check.
 *  4. Nothing usable at all (a CLI run has no REMOTE_ADDR): ''.
 *
 * What a header can win (CLAUDE.md error pattern #16). Every header here is
 * sent by the client; there is no trusted-proxy setting, so behind no proxy
 * a visitor chooses this value outright. It is believed because the click
 * path is the visitor's own record: the GEO and ISP of their click, the
 * rotator rule that routes them, the address shown in reports, and the
 * product depends on it — behind Cloudflare or a load balancer REMOTE_ADDR
 * is the proxy, and every click would share one address, one country and
 * one duplicate-IP filter bucket; `p202 tracker test --geo` simulates a
 * visitor's country through X-Forwarded-For. A false claim moves the
 * claimant's own click. It must never key a decision whose loser is someone
 * else — a rate limit, a dedupe, a lockout: those key on REMOTE_ADDR
 * (ServerStateStore::softIpRateLimit(), the intake rate limits). Two
 * existing consumers are such decisions and are weaker for it, which a
 * trusted-proxy setting, not this method, would fix: the click filter's
 * duplicate-IP check (FILTER::checkLastIps) and the login throttle's per-IP
 * limb (AUTH::is_rate_limited; its per-username limb is unaffected).
 */
final class VisitorIp
{
    /** Most specific first; the order is the precedence. */
    public const FORWARDING_HEADERS = [
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_X_SUCURI_CLIENTIP',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
    ];

    private function __construct()
    {
    }

    /**
     * The visitor's address, or '' when the request carries none.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function fromServer(array $server): string
    {
        $claimed = self::claimedHeader($server);
        if ($claimed !== null) {
            $address = self::address($claimed);
            if ($address !== '') {
                return $address;
            }
        }

        return self::address(self::scalar($server['REMOTE_ADDR'] ?? null));
    }

    /**
     * The X-Forwarded-For header exactly as the client sent it, for a
     * diagnostic log line that wants to show what arrived. Never a stored
     * value or a decision: that is fromServer().
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function forwardedForAsSent(array $server): string
    {
        return self::scalar($server['HTTP_X_FORWARDED_FOR'] ?? null);
    }

    /** @param array<string, mixed> $server */
    private static function claimedHeader(array $server): ?string
    {
        foreach (self::FORWARDING_HEADERS as $key) {
            $value = self::scalar($server[$key] ?? null);
            // empty() semantics, as the bootstrap's match(true) had: '' and
            // '0' are no header at all.
            if ($value === '' || $value === '0') {
                continue;
            }
            // A proxy that forwards to itself names this server; the client
            // is then REMOTE_ADDR. The whole header is compared, as before.
            if ($key === 'HTTP_X_FORWARDED_FOR' && $value === self::scalar($server['SERVER_ADDR'] ?? null)) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private static function address(string $value): string
    {
        $first = trim(explode(',', $value, 2)[0]);
        if ($first === '' || filter_var($first, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = inet_pton($first);
        $canonical = $packed === false ? false : inet_ntop($packed);

        return $canonical === false ? $first : $canonical;
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : '';
    }
}
