<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Http\RequestHost;

/**
 * The base every tracking link, pixel and landing-page snippet this install
 * hands out starts with: `scheme://host[:port]/<install path>/`.
 *
 * The setup pages build it as 'http://' . getTrackingDomain() .
 * get_absolute_url(): the tracking domain is user 1's preference, or this
 * server's own name when it is empty, and the path is where the install
 * sits under the document root. The API built its own from the CALLER's
 * preference and nothing else, so on a fresh install (an empty domain)
 * every tracking URL was a 404, a host-only domain — the form Personal
 * settings stores — gave a link with no scheme, and an install in a
 * subdirectory lost the directory. This is the pages' rule, in a form the
 * API can call: no globals, the server values passed in.
 *
 * One difference, chosen: the scheme. The pages write 'http://' always;
 * here a domain stored with a scheme keeps it, and otherwise the scheme the
 * request arrived on is used, so an install reached over HTTPS hands out
 * HTTPS links.
 *
 * forRequest() and requestUrl() are the other base: this install on the
 * origin the request itself arrived at, for the URLs a response points
 * back at its own host with — the cloaked redirects (cl.php, lpc.php), the
 * tracking202outbound cookie record_simple.php hands the landing page, the
 * outbound and cloaking URLs stored with a click, the error log's script
 * URL. Those were written 'http://' . $_SERVER['SERVER_NAME'] . …, which
 * dropped the port (SERVER_NAME has none), so on an install served from a
 * non-default port every one of them pointed at port 80; forced http on an
 * HTTPS install; took nginx's configured name instead of the host the
 * browser asked for; and, in dl.php and lp.php, left out the install
 * directory. RequestHostSourceTest refuses any other reader of the Host
 * header or the server name.
 *
 * The host is the Host header, validated (RequestHost); when it is not a
 * host, the server's own name, as build() falls back to it. The scheme is
 * p202_request_is_https(), the answer the session cookie's Secure flag and
 * build() already take, which believes X-Forwarded-Proto from any peer —
 * the stance VisitorIp takes on the forwarding headers, for the same
 * reason: an install behind a TLS-terminating proxy or CDN sees plain HTTP,
 * and not believing the proxy would hand every HTTPS visitor an http://
 * redirect (rtr.php's cloaked redirect already read X-Forwarded-Proto for
 * that reason). A false claim chooses only the scheme of the claimant's own
 * response, on the host it asked for; the host is never taken from a
 * forwarded header (no X-Forwarded-Host).
 */
final class TrackingBaseUrl
{
    private function __construct()
    {
    }

    /**
     * @param string $storedDomain user 1's user_tracking_domain, as stored
     * @param array<string, mixed> $server normally $_SERVER
     * @param string $installRoot the install's directory on disk
     */
    public static function build(string $storedDomain, array $server, string $installRoot): string
    {
        $host = TrackingDomain::normalize($storedDomain);
        if ($host === '') {
            $host = self::serverHost($server);
        }
        if (preg_match('#^\s*(https?)://#i', $storedDomain, $m) === 1) {
            $scheme = strtolower($m[1]);
        } else {
            require_once dirname(__DIR__) . '/request-https.php';
            $scheme = p202_request_is_https($server) ? 'https' : 'http';
        }

        return $scheme . '://' . $host . self::installPath($server, $installRoot);
    }

    /**
     * This install on the origin the request arrived at:
     * `scheme://host[:port]/<install path>/`.
     *
     * @param array<string, mixed> $server normally $_SERVER
     * @param string|null $installRoot the install's directory on disk; this
     *     install's when omitted
     */
    public static function forRequest(array $server, ?string $installRoot = null): string
    {
        return self::requestOrigin($server) . self::installPath($server, $installRoot ?? dirname(__DIR__, 2));
    }

    /**
     * The URL this request was made to: its origin and REQUEST_URI (the
     * origin alone, with '/', when the request target is not a path).
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function requestUrl(array $server): string
    {
        $target = is_string($server['REQUEST_URI'] ?? null) ? $server['REQUEST_URI'] : '';

        return self::requestOrigin($server) . (str_starts_with($target, '/') ? $target : '/');
    }

    /**
     * `scheme://host[:port]` of this request.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function requestOrigin(array $server): string
    {
        require_once dirname(__DIR__) . '/request-https.php';

        return (p202_request_is_https($server) ? 'https' : 'http') . '://' . self::requestHost($server);
    }

    /**
     * `host[:port]` of this request: the Host header when it is a host
     * (RequestHost), otherwise the server's own name.
     *
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function requestHost(array $server): string
    {
        return RequestHost::fromServer($server) ?? self::serverHost($server);
    }

    /**
     * The server's own name, as getTrackingDomain() falls back to it: only
     * host characters, and the port when it is not 80 or 443.
     *
     * @param array<string, mixed> $server
     */
    private static function serverHost(array $server): string
    {
        $name = is_scalar($server['SERVER_NAME'] ?? null) ? (string) $server['SERVER_NAME'] : '';
        $host = (string) preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', $name);
        if ($host === '') {
            $host = 'localhost';
        }
        $port = (int) ($server['SERVER_PORT'] ?? 80);
        if ($port !== 80 && $port !== 443 && $port > 0 && !str_contains($host, ':')) {
            $host .= ':' . $port;
        }

        return $host;
    }

    /**
     * get_absolute_url(): the install directory relative to the document
     * root, with a slash at each end; '/' when it cannot be told.
     *
     * @param array<string, mixed> $server
     */
    public static function installPath(array $server, string $installRoot): string
    {
        $docRoot = is_scalar($server['DOCUMENT_ROOT'] ?? null) ? realpath((string) $server['DOCUMENT_ROOT']) : false;
        $root = realpath($installRoot);
        if ($docRoot === false || $root === false || !str_starts_with($root . '/', rtrim($docRoot, '/') . '/')) {
            return '/';
        }
        $relative = trim(substr($root, strlen(rtrim($docRoot, '/'))), '/');

        return $relative === '' ? '/' : '/' . $relative . '/';
    }
}
