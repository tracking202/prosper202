<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Click\TrackingBaseUrl;
use Prosper202\Click\TrackingDomain;

/**
 * Where the cron job's report rebuild calls this server back
 * (202-cronjobs/process_dataengine_job.php fetches dej.php once an hour of
 * the window), and the curl options that keep the call on this machine.
 *
 * With a tracking domain stored, the call goes to it: the operator's
 * configured address, unchanged.
 *
 * With none, the address came from the request that reached the cron: the
 * server's own name, which under nginx's catch-all is the request's Host
 * header (connect.php maps `_` to it). The cron's URL is public — any page's
 * beacon, or anyone, reaches it — so the server fetched a host the caller
 * chose (CLAUDE.md #16: the request's host is for the requester's own
 * response, and this response goes to someone else). Now the call goes to
 * the listener that served this request — its address (SERVER_ADDR), its
 * port and its scheme as the connection used them, not as a proxy reports
 * them — with the name in the URL pinned to that address (CURLOPT_RESOLVE),
 * so whatever name the request claimed, the connection never leaves this
 * machine. A proxy would resolve the name itself and ignore the pin
 * (CLAUDE.md #24), so the call takes none. A run with no listener (the
 * PHP CLI) builds the base as before.
 *
 * Both get timeouts: the call had none, so a rebuild whose call could not
 * be answered — a single-worker `php -S` calling itself — held the cron run
 * for good.
 */
final class SelfCall
{
    public const CONNECT_TIMEOUT = 10;

    /** An hour of clicks is one call; this bounds a call that is not answered, not a slow one. */
    public const TIMEOUT = 600;

    private function __construct()
    {
    }

    /**
     * The base URL (`scheme://host[:port]/<install path>/`) the call is made
     * on.
     *
     * @param string $storedDomain user 1's user_tracking_domain, as stored
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function base(string $storedDomain, array $server, string $installRoot): string
    {
        $listener = self::listener($server);
        if (TrackingDomain::normalize($storedDomain) !== '' || $listener === null) {
            return TrackingBaseUrl::build($storedDomain, $server, $installRoot);
        }
        [$scheme, $name, $port] = $listener;

        return $scheme . '://' . self::urlHost($name) . ':' . $port . TrackingBaseUrl::installPath($server, $installRoot);
    }

    /**
     * The curl options for a call on base()'s URL: the timeouts, and with no
     * domain stored, the pin to this listener and no proxy.
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @return array<int, mixed>
     */
    public static function curlOptions(string $storedDomain, array $server): array
    {
        $options = [CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT, CURLOPT_TIMEOUT => self::TIMEOUT];
        $listener = self::listener($server);
        if (TrackingDomain::normalize($storedDomain) !== '' || $listener === null) {
            return $options;
        }
        [, $name, $port] = $listener;
        $address = self::listeningAddress($server);
        $options[CURLOPT_RESOLVE] = [
            self::urlHost($name) . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address),
        ];
        $options[CURLOPT_PROXY] = '';
        $options[CURLOPT_NOPROXY] = '*';

        return $options;
    }

    /**
     * The listener that served this request: [scheme, name, port], or null
     * when there is none (the PHP CLI) or it cannot be read.
     *
     * @param array<string, mixed> $server
     * @return array{string, string, int}|null
     */
    private static function listener(array $server): ?array
    {
        $name = is_scalar($server['SERVER_NAME'] ?? null) ? (string) $server['SERVER_NAME'] : '';
        // Host characters only, as the server's name is read everywhere else
        // (TrackingBaseUrl's fallback); an IPv6 literal keeps its brackets'
        // contents.
        $name = (string) preg_replace('/[^a-zA-Z0-9.\-:]/', '', trim($name, '[]'));
        $port = is_scalar($server['SERVER_PORT'] ?? null) ? (string) $server['SERVER_PORT'] : '';
        if ($name === '' || preg_match('/^[0-9]{1,5}$/D', $port) !== 1 || (int) $port < 1 || (int) $port > 65535) {
            return null;
        }
        // The connection's own scheme: HTTPS is the web server's, set for a
        // TLS connection it terminated. A proxy's X-Forwarded-Proto describes
        // the proxy's side, not this listener's.
        $https = is_scalar($server['HTTPS'] ?? null) && !in_array(strtolower((string) $server['HTTPS']), ['', 'off', '0'], true);

        return [$https ? 'https' : 'http', $name, (int) $port];
    }

    /** The address this request was accepted on, or loopback when it is unknown or a wildcard. */
    private static function listeningAddress(array $server): string
    {
        $address = is_scalar($server['SERVER_ADDR'] ?? null) ? trim((string) $server['SERVER_ADDR'], " []") : '';
        if (filter_var($address, FILTER_VALIDATE_IP) === false || in_array($address, ['0.0.0.0', '::'], true)) {
            return '127.0.0.1';
        }

        return $address;
    }

    /** A name as it is written in a URL's authority: an IPv6 literal in brackets. */
    private static function urlHost(string $name): string
    {
        return filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false ? '[' . $name . ']' : $name;
    }
}
