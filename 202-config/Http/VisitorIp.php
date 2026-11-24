<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The client's IP address, as every click endpoint, pixel, page, rate limit
 * and audit row takes it: the one place in the served tree that reads a
 * forwarding header (ForwardedIpHeaderReadTest refuses a read anywhere else).
 *
 * Identity is what the client cannot choose (CLAUDE.md #16):
 *
 *  1. The connecting peer, REMOTE_ADDR, validated. When it is not one of
 *     the proxies P202_TRUSTED_PROXIES names (TrustedProxies) it is the
 *     client, and every forwarding header is ignored: it is the client's own
 *     claim.
 *  2. From a trusted proxy, X-Forwarded-For is walked from its right end,
 *     the hop that proxy appended, past every hop that is itself a trusted
 *     proxy; the first one that is not is the client. What a client sends in
 *     the header lands to the left of that hop and is never reached. A hop
 *     may carry a port (`198.51.100.7:8080`, `[2001:db8::1]:443`).
 *  3. A hop on the way that is not an address (`unknown`, garbage) stops
 *     the walk at the trusted proxy that passed it on, which then stands for
 *     the client: an address nobody chose, never a guess past it. So does a
 *     chain of more than MAX_HOPS.
 *  4. Every hop trusted: the leftmost, which is one of the proxies.
 *  5. Nothing usable at all (a CLI run has no REMOTE_ADDR): ''.
 *
 * The single-value headers (CF-Connecting-IP, X-Real-IP, Client-IP and the
 * rest of FORWARDING_HEADERS) are never read for the address: each is only
 * as good as a proxy that overwrites it, which nothing here can tell, and
 * the proxies that set one also append X-Forwarded-For. Their presence still
 * marks a request that came through a proxy (knownClient()).
 *
 * With no setting, behind a CDN or a load balancer every request is the
 * proxy's address: one address, one country, one rate-limit bucket. That is
 * the safe answer for an install that has not said which proxy it is behind.
 */
final class VisitorIp
{
    /** Headers a proxy (or a client claiming to be one) names a client address in. */
    public const FORWARDING_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_X_SUCURI_CLIENTIP',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',
        'HTTP_FORWARDED',
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
    ];

    /** Hops walked before the chain is not believed. */
    public const MAX_HOPS = 20;

    private function __construct()
    {
    }

    /**
     * The client's address, or '' when the request carries none.
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @param ?TrustedProxies $trusted the environment's setting when not given
     */
    public static function fromServer(array $server, ?TrustedProxies $trusted = null): string
    {
        $trusted ??= TrustedProxies::fromEnvironment();
        $peer = self::address(self::scalar($server['REMOTE_ADDR'] ?? null));
        if ($peer === '' || !$trusted->trusts($peer)) {
            return $peer;
        }
        $hops = explode(',', self::scalar($server['HTTP_X_FORWARDED_FOR'] ?? null));
        if (count($hops) > self::MAX_HOPS) {
            return $peer;
        }
        $client = $peer;
        foreach (array_reverse($hops) as $hop) {
            if (trim($hop) === '') {
                continue;
            }
            $address = self::address(self::withoutPort(trim($hop)));
            if ($address === '') {
                return $client;
            }
            $client = $address;
            if (!$trusted->trusts($address)) {
                return $address;
            }
        }

        return $client;
    }

    /**
     * fromServer(), or '' when the peer is no trusted proxy but the request
     * carries a forwarding header: it may have come through a proxy this
     * install was not told about, whose address would stand in for the
     * client's. For an answer that is better unknown than wrong (the sign-in
     * location that holds analytics for an EU account).
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @param ?TrustedProxies $trusted the environment's setting when not given
     */
    public static function knownClient(array $server, ?TrustedProxies $trusted = null): string
    {
        $trusted ??= TrustedProxies::fromEnvironment();
        $peer = self::address(self::scalar($server['REMOTE_ADDR'] ?? null));
        if ($peer !== '' && !$trusted->trusts($peer)) {
            foreach (self::FORWARDING_HEADERS as $key) {
                if (self::scalar($server[$key] ?? null) !== '') {
                    return '';
                }
            }
        }

        return self::fromServer($server, $trusted);
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
        $value = $server['HTTP_X_FORWARDED_FOR'] ?? null;

        return is_string($value) || is_int($value) ? (string) $value : '';
    }

    /** `198.51.100.7:8080` and `[2001:db8::1]:443` without the port; anything else as it is. */
    private static function withoutPort(string $hop): string
    {
        if (preg_match('/^\[([0-9A-Fa-f:.]+)\](?::[0-9]{1,5})?$/D', $hop, $m) === 1) {
            return $m[1];
        }
        if (preg_match('/^([0-9.]+):[0-9]{1,5}$/D', $hop, $m) === 1) {
            return $m[1];
        }

        return $hop;
    }

    private static function address(string $value): string
    {
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return '';
        }
        $packed = inet_pton($value);
        $canonical = $packed === false ? false : inet_ntop($packed);

        return $canonical === false ? $value : $canonical;
    }

    private static function scalar(mixed $value): string
    {
        return is_string($value) || is_int($value) ? trim((string) $value) : '';
    }
}
