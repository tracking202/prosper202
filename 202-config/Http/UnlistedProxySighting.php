<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * The last peer that sent forwarding headers without being a proxy
 * P202_TRUSTED_PROXIES lists, for the warning on Account › Settings and the
 * signed-in health check: behind such a proxy every visitor is recorded at
 * the proxy's address, and nothing else says so.
 *
 * A file in the install's own 202-config/temp/ (as UnsavedClickRedirects),
 * written at most once every RECORD_EVERY_SECONDS: a request that has
 * nothing to record costs nothing, and one that has costs a stat until the
 * window is over. It holds the peer (REMOTE_ADDR, which the request cannot
 * choose), the names of the headers it sent (never their values), and in how
 * many windows that peer was seen; a different peer starts the count again.
 *
 * The peer may be a visitor who sent the header itself, so a public address
 * is kept masked (StoredVisitorIp::mask(), its /24 or /48, as the click
 * path's diagnostic log keeps every address); a private, loopback or other
 * reserved one, which is no visitor's on the internet, is kept whole.
 *
 * A proxy sends its headers on every request, a client that sends one itself
 * now and then: the warning waits for MIN_WINDOWS windows of the same peer,
 * and asks again of the current setting. Once the setting lists the peer,
 * its next request with forwarding headers removes the sighting.
 */
final class UnlistedProxySighting
{
    public const FILE = '202-config/temp/unlisted-proxy.json';

    public const RECORD_EVERY_SECONDS = 600;

    /** Windows of one peer before the warning shows. */
    public const MIN_WINDOWS = 3;

    /** How long after the last sighting the warning shows. */
    public const RECENT_SECONDS = 7 * 86400;

    private const MAX_BYTES = 2048;

    public static function path(string $root): string
    {
        return rtrim($root, '/') . '/' . self::FILE;
    }

    /**
     * Notes this request when an unlisted peer sent forwarding headers.
     * Never throws and never holds the request up; a sighting that cannot be
     * written is dropped (the next window tries again).
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function record(string $root, array $server, ?TrustedProxies $trusted = null, ?int $now = null): void
    {
        try {
            self::write($root, $server, $trusted ?? TrustedProxies::fromEnvironment(), $now ?? time());
        } catch (\Throwable $e) {
            error_log('Prosper202: the unlisted-proxy sighting was not recorded: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $server */
    private static function write(string $root, array $server, TrustedProxies $trusted, int $now): void
    {
        $seen = VisitorIp::unlistedPeerForwarding($server, $trusted);
        $path = self::path($root);
        if ($seen === null) {
            self::clearOnceListed($root, $server, $trusted);

            return;
        }
        clearstatcache(true, $path);
        $at = @filemtime($path);
        if ($at !== false && $now - $at >= 0 && $now - $at < self::RECORD_EVERY_SECONDS) {
            return;
        }
        $peer = self::kept($seen['peer']);
        $before = self::read($root);
        $same = $before !== null && $before['peer'] === $peer;
        $body = json_encode([
            'peer' => $peer,
            'masked' => self::isPublic($seen['peer']),
            'headers' => $seen['headers'],
            'first_at' => $same ? $before['first_at'] : $now,
            'last_at' => $now,
            'windows' => $same ? $before['windows'] + 1 : 1,
        ]);
        if ($body === false) {
            return;
        }
        $temp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (@file_put_contents($temp, $body) !== strlen($body) || !@rename($temp, $path)) {
            @unlink($temp);
        }
    }

    /** The peer as the file keeps it: whole when reserved, else masked. */
    private static function kept(string $peer): string
    {
        return self::isPublic($peer) ? StoredVisitorIp::mask($peer) : $peer;
    }

    private static function isPublic(string $peer): bool
    {
        return filter_var($peer, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /**
     * Removes the sighting when this request's peer is now a listed proxy
     * and is the one it names. Costs a stat for a listed peer, nothing for
     * any other request.
     *
     * @param array<string, mixed> $server
     */
    private static function clearOnceListed(string $root, array $server, TrustedProxies $trusted): void
    {
        $remote = $server['REMOTE_ADDR'] ?? null;
        $packed = is_string($remote) && filter_var(trim($remote), FILTER_VALIDATE_IP) !== false
            ? inet_pton(trim($remote)) : false;
        $peer = $packed === false ? false : inet_ntop($packed);
        if ($peer === false || !$trusted->trusts($peer) || !is_file(self::path($root))) {
            return;
        }
        $before = self::read($root);
        if ($before !== null && $before['peer'] === self::kept($peer)) {
            @unlink(self::path($root));
        }
    }

    /**
     * The sighting, or null when there is none or the file cannot be read
     * as one.
     *
     * @return array{peer: string, masked: bool, headers: list<string>, first_at: int, last_at: int, windows: int}|null
     */
    public static function read(string $root): ?array
    {
        $path = self::path($root);
        if (!is_file($path)) {
            return null;
        }
        $text = @file_get_contents($path, false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($text) || strlen($text) > self::MAX_BYTES) {
            return null;
        }
        $data = json_decode($text, true);
        if (!is_array($data)
            || !is_string($data['peer'] ?? null) || filter_var($data['peer'], FILTER_VALIDATE_IP) === false
            || !is_bool($data['masked'] ?? null)
            || !is_array($data['headers'] ?? null) || !is_int($data['first_at'] ?? null)
            || !is_int($data['last_at'] ?? null) || !is_int($data['windows'] ?? null) || $data['windows'] < 1) {
            return null;
        }
        $headers = array_values(array_filter(
            $data['headers'],
            static fn (mixed $h): bool => is_string($h) && in_array($h, VisitorIp::FORWARDING_HEADERS, true)
        ));

        return [
            'peer' => $data['peer'],
            'masked' => $data['masked'],
            'headers' => $headers,
            'first_at' => $data['first_at'],
            'last_at' => $data['last_at'],
            'windows' => $data['windows'],
        ];
    }

    /**
     * The sentence Settings and the signed-in health check show, or null.
     *
     * @param array{peer: string, masked: bool, headers: list<string>, first_at: int, last_at: int, windows: int}|null $sighting
     */
    public static function warning(?array $sighting, ?TrustedProxies $trusted = null, ?int $now = null): ?string
    {
        $now ??= time();
        $trusted ??= TrustedProxies::fromEnvironment();
        if ($sighting === null || $sighting['windows'] < self::MIN_WINDOWS
            || $now - $sighting['last_at'] > self::RECENT_SECONDS || $trusted->trusts($sighting['peer'])) {
            return null;
        }
        $names = array_map(self::headerName(...), $sighting['headers']);
        $where = $sighting['masked']
            ? 'an address in ' . $sighting['peer'] . (str_contains($sighting['peer'], ':') ? '/48' : '/24')
                . ' (shortened, as a visitor\'s address would be)'
            : $sighting['peer'];

        return sprintf(
            'Requests from %s carry %s (since %s, the last at %s UTC), but that address is not a proxy'
                . ' P202_TRUSTED_PROXIES lists, so every visitor through it is recorded at the proxy\'s address.'
                . ' If it is your proxy or load balancer, add it to P202_TRUSTED_PROXIES in the web server\'s'
                . ' environment (the FAQ\'s "behind Cloudflare, a load balancer or a reverse proxy"). If it is not, a'
                . ' client sent the header itself, and nothing needs to change.',
            $where,
            $names === [] ? 'forwarding headers' : implode(', ', $names),
            gmdate('Y-m-d H:i', $sighting['first_at']),
            gmdate('Y-m-d H:i', $sighting['last_at'])
        );
    }

    /** HTTP_X_FORWARDED_FOR as X-Forwarded-For, HTTP_CF_CONNECTING_IP as CF-Connecting-IP. */
    private static function headerName(string $key): string
    {
        $words = explode('_', strtolower(substr($key, 5)));

        return implode('-', array_map(
            static fn (string $w): string => in_array($w, ['ip', 'cf'], true) ? strtoupper($w) : ucfirst($w),
            $words
        ));
    }
}
