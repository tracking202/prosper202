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
 *     the proxies P202_TRUSTED_PROXIES names (TrustedProxies; Cloudflare's
 *     ranges when unset) it is the client, and every forwarding header is
 *     ignored: it is the client's own claim.
 *  2. From a listed proxy, the chain of hops is walked from its right end,
 *     the hop that proxy appended, past every hop that is itself a listed
 *     proxy; the first one that is not is the client. What a client sends
 *     lands to the left of that hop and is never reached. The chain is
 *     X-Forwarded-For (`client, proxy1`; a hop may carry a port) or the
 *     RFC 7239 Forwarded header's `for=` nodes (ForwardedHeader: quoted,
 *     bracketed IPv6, ports).
 *  3. A hop on the way that names no address (`unknown`, an obfuscated
 *     `for=_hidden`, garbage) stops the walk at the listed proxy that passed
 *     it on, which then stands for the client: an address nobody chose,
 *     never a guess past it. A chain of more than MAX_HOPS, or a Forwarded
 *     header that does not parse, is the peer.
 *  4. Every hop listed: the leftmost, which is one of the proxies.
 *  5. Both headers: each is walked on its own and never merged. When they
 *     name the same client that is the client; when they differ, the peer.
 *     A listed proxy that appends to one passes a client's own copy of the
 *     other through untouched, and nothing here can tell which one it wrote.
 *  6. Nothing usable at all (a CLI run has no REMOTE_ADDR): ''.
 *
 * The single-value headers (CF-Connecting-IP, X-Real-IP, Client-IP and the
 * rest of FORWARDING_HEADERS) are never read for the address: each is only
 * as good as a proxy that overwrites it, which nothing here can tell, and
 * the proxies that set one also append X-Forwarded-For. Their presence still
 * marks a request that came through a proxy (knownClient(),
 * UnlistedProxySighting).
 */
final class VisitorIp
{
    /** Headers a proxy (or a client claiming to be one) names a client address in. */
    public const FORWARDING_HEADERS = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'HTTP_CF_CONNECTING_IP',
        'HTTP_X_CLUSTER_CLIENT_IP',
        'HTTP_X_SUCURI_CLIENTIP',
        'HTTP_X_REAL_IP',
        'HTTP_CLIENT_IP',
        'HTTP_TRUE_CLIENT_IP',
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
        return self::resolve($server, $trusted ?? TrustedProxies::fromEnvironment())['address'];
    }

    /**
     * Whether the Forwarded element of the client fromServer() names says
     * `proto=https`: the scheme the browser used to reach the first proxy.
     * False from an unlisted peer, when X-Forwarded-For named someone else,
     * and when the walk stopped at a hop it could not read.
     * p202_request_is_https() asks it.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function forwardedProtoIsHttps(array $server, ?TrustedProxies $trusted = null): bool
    {
        return self::resolve($server, $trusted ?? TrustedProxies::fromEnvironment())['proto'] === 'https';
    }

    /**
     * fromServer(), or '' when the peer is no listed proxy but the request
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
        if (self::unlistedPeerForwarding($server, $trusted) !== null) {
            return '';
        }

        return self::fromServer($server, $trusted);
    }

    /**
     * The peer and the forwarding headers it sent when it is no listed
     * proxy, or null: the sighting UnlistedProxySighting records. Header
     * names only, from FORWARDING_HEADERS, never their values.
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @return array{peer: string, headers: list<string>}|null
     */
    public static function unlistedPeerForwarding(array $server, ?TrustedProxies $trusted = null): ?array
    {
        $trusted ??= TrustedProxies::fromEnvironment();
        $peer = self::address(self::scalar($server['REMOTE_ADDR'] ?? null));
        if ($peer === '' || $trusted->trusts($peer)) {
            return null;
        }
        $headers = [];
        foreach (self::FORWARDING_HEADERS as $key) {
            if (self::scalar($server[$key] ?? null) !== '') {
                $headers[] = $key;
            }
        }

        return $headers === [] ? null : ['peer' => $peer, 'headers' => $headers];
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

    /**
     * @param array<string, mixed> $server
     * @return array{address: string, proto: ?string}
     */
    private static function resolve(array $server, TrustedProxies $trusted): array
    {
        $peer = self::address(self::scalar($server['REMOTE_ADDR'] ?? null));
        if ($peer === '' || !$trusted->trusts($peer)) {
            return ['address' => $peer, 'proto' => null];
        }
        $xffText = self::scalar($server['HTTP_X_FORWARDED_FOR'] ?? null);
        $forwardedText = self::scalar($server['HTTP_FORWARDED'] ?? null);
        $xff = $xffText === '' ? null : self::walk($peer, self::xffHops($xffText), $trusted);
        $forwarded = null;
        if ($forwardedText !== '') {
            $elements = ForwardedHeader::elements($forwardedText);
            $forwarded = $elements === null
                ? ['address' => $peer, 'proto' => null]
                : self::walk($peer, self::forwardedHops($elements), $trusted);
        }

        return match (true) {
            $forwarded === null && $xff === null => ['address' => $peer, 'proto' => null],
            $forwarded === null => ['address' => $xff['address'], 'proto' => null],
            $xff === null => $forwarded,
            $forwarded['address'] === $xff['address'] => $forwarded,
            default => ['address' => $peer, 'proto' => null],
        };
    }

    /**
     * Walks $hops from the right (see the class comment, 2 to 4).
     *
     * @param list<array{address: string, proto: ?string}>|null $hops null when too many
     * @return array{address: string, proto: ?string}
     */
    private static function walk(string $peer, ?array $hops, TrustedProxies $trusted): array
    {
        if ($hops === null) {
            return ['address' => $peer, 'proto' => null];
        }
        $client = ['address' => $peer, 'proto' => null];
        foreach (array_reverse($hops) as $hop) {
            if ($hop['address'] === '') {
                return ['address' => $client['address'], 'proto' => null];
            }
            $client = $hop;
            if (!$trusted->trusts($hop['address'])) {
                return $hop;
            }
        }

        return $client;
    }

    /** @return list<array{address: string, proto: null}>|null */
    private static function xffHops(string $value): ?array
    {
        $hops = [];
        foreach (explode(',', $value) as $hop) {
            $hop = trim($hop);
            if ($hop === '') {
                continue;
            }
            $hops[] = ['address' => self::address(self::withoutPort($hop)), 'proto' => null];
        }

        return count($hops) > self::MAX_HOPS ? null : $hops;
    }

    /**
     * @param list<array<string, string>> $elements
     * @return list<array{address: string, proto: ?string}>
     */
    private static function forwardedHops(array $elements): array
    {
        $hops = [];
        foreach ($elements as $pairs) {
            $proto = isset($pairs['proto']) ? strtolower($pairs['proto']) : null;
            $hops[] = ['address' => ForwardedHeader::nodeAddress($pairs['for'] ?? ''), 'proto' => $proto];
        }

        return $hops;
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
