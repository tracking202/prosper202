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
 * A pin covers one name and port. A redirect to any other — an
 * http-to-https rule built from the claimed Host, say — is resolved by DNS
 * and leaves this machine, so the pinned call follows none: the hour
 * answers 3xx, is left unprocessed and retried, and the cron's log line
 * names where it was sent. (A call on the stored domain follows redirects
 * as it always did; that address is the operator's.)
 *
 * Both get timeouts: the call had none, so a rebuild whose call could not
 * be answered — a single-worker `php -S` calling itself — held the cron run
 * for good.
 *
 * curlOptions() is the whole set the cron hands curl, so nothing at the
 * call site can override the pin (the cron's own defaults used to be merged
 * in front of it, and set redirects on).
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
     * Every curl option for a call on base()'s URL: http(s) only, TLS
     * verified, the timeouts, and with no domain stored, the pin to this
     * listener, no proxy and no redirects.
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @return array<int, mixed>
     */
    public static function curlOptions(string $storedDomain, array $server): array
    {
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            // curl's defaults, said here: a pinned https call is verified
            // against the name the request claimed, so a name this server
            // holds no certificate for fails rather than connects.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ];
        $listener = self::listener($server);
        if (TrackingDomain::normalize($storedDomain) !== '' || $listener === null) {
            return $options + [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5];
        }
        [, $name, $port] = $listener;
        $address = self::listeningAddress($server);
        $options[CURLOPT_RESOLVE] = [
            self::urlHost($name) . ':' . $port . ':' . (str_contains($address, ':') ? '[' . $address . ']' : $address),
        ];
        $options[CURLOPT_PROXY] = '';
        $options[CURLOPT_NOPROXY] = '*';
        $options[CURLOPT_FOLLOWLOCATION] = false;
        $options[CURLOPT_MAXREDIRS] = 0;

        return $options;
    }

    /**
     * Whether a call made with curlOptions()'s $options follows redirects:
     * for the cron's log line, which sets and reads no option itself.
     *
     * @param array<int, mixed> $options
     */
    public static function followsRedirects(array $options): bool
    {
        return ($options[CURLOPT_FOLLOWLOCATION] ?? false) === true;
    }

    /**
     * The listener that served this request: [scheme, name, port], or null
     * when this process serves no request (the PHP CLI: no request method,
     * no server name, no server port).
     *
     * A request whose name or port cannot be read is still pinned — under
     * the listening address as its name, on its scheme's default port —
     * because answering null there would build the old, unpinned URL from
     * the same request.
     *
     * @param array<string, mixed> $server
     * @return array{string, string, int}|null
     */
    private static function listener(array $server): ?array
    {
        $given = static fn (string $key): bool => is_scalar($server[$key] ?? null) && (string) $server[$key] !== '';
        $serverName = $given('SERVER_NAME') ? (string) $server['SERVER_NAME'] : '';
        if ($serverName === '' && !$given('REQUEST_METHOD') && !$given('SERVER_PORT')) {
            return null;
        }
        // The connection's own scheme: HTTPS is the web server's, set for a
        // TLS connection it terminated. A proxy's X-Forwarded-Proto describes
        // the proxy's side, not this listener's.
        $https = is_scalar($server['HTTPS'] ?? null) && !in_array(strtolower((string) $server['HTTPS']), ['', 'off', '0'], true);
        $port = $given('SERVER_PORT') ? (string) $server['SERVER_PORT'] : '';
        $port = preg_match('/^[0-9]{1,5}$/D', $port) === 1 && (int) $port >= 1 && (int) $port <= 65535
            ? (int) $port
            : ($https ? 443 : 80);
        $name = self::hostOf($serverName);
        if ($name === '') {
            $name = self::listeningAddress($server);
        }

        return [$https ? 'https' : 'http', $name, $port];
    }

    /**
     * The host in a server name, or '' when it cannot be read. Under nginx's
     * catch-all the name is the Host header (connect.php), port and all:
     * `name:8080` is `name` (the call takes the listener's own port), and an
     * IPv6 literal loses its brackets. Host characters only, as the server's
     * name is read everywhere else (TrackingBaseUrl's fallback); a name left
     * with a colon that is not an IPv6 address cannot be written in a URL.
     */
    private static function hostOf(string $name): string
    {
        $name = trim($name);
        if (str_starts_with($name, '[')) {
            $end = strpos($name, ']');
            $name = $end === false ? '' : substr($name, 1, $end - 1);
        } elseif (filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            $name = (string) preg_replace('/:[0-9]*$/D', '', $name);
        }
        $name = (string) preg_replace('/[^a-zA-Z0-9.\-:]/', '', $name);
        if (str_contains($name, ':') && filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return '';
        }

        return $name;
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
