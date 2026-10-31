<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Http\RequestHost;

/**
 * The base every tracking link, pixel and landing-page snippet this install
 * hands out starts with: `scheme://host[:port]/<install path>/`.
 *
 * The host is the stored tracking domain (the caller's for a link handed
 * back, the owner's for a URL sent on), or with none the host the request
 * arrived at (domainForResponse(); it was this server's own name and port)
 * or the server's own name (build()), and the path is where the install sits
 * under the document root. The API built its own from the caller's
 * preference and nothing else, so on a fresh install (an empty domain)
 * every tracking URL was a 404, a host-only domain — the form Personal
 * settings stores — gave a link with no scheme, and an install in a
 * subdirectory lost the directory. This is the pages' rule, in a form the
 * API can call: no globals, the server values passed in.
 *
 * The scheme is https unless the install says it is http-only: a domain
 * stored with a scheme keeps it (storedScheme()), and otherwise the scheme
 * the request arrived on is used — the install's own URL, which whoever
 * asked has just reached it on. The pages and the API build on
 * buildForResponse() alike (p202TrackingBaseForResponse(),
 * TrackingBaseLookup), and HandBuiltLinkSchemeTest refuses a link built on
 * a scheme chosen anywhere else.
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
        $scheme = self::storedScheme($storedDomain);
        if ($scheme === null) {
            require_once dirname(__DIR__) . '/request-https.php';
            $scheme = p202_request_is_https($server) ? 'https' : 'http';
        }

        return $scheme . '://' . $host . self::installPath($server, $installRoot);
    }

    /** The scheme a tracking domain was saved with, `http` or `https`; null when it was saved without one. */
    public static function storedScheme(string $storedDomain): ?string
    {
        return preg_match('#^\s*(https?)://#i', $storedDomain, $m) === 1 ? strtolower($m[1]) : null;
    }

    /**
     * The scheme buildForResponse() takes from the stored domain, or null
     * when it takes the request's: a domain with no host in it is not used
     * at all, its scheme included.
     *
     * @param string $storedDomain the account's user_tracking_domain, as stored
     */
    public static function storedSchemeForResponse(string $storedDomain): ?string
    {
        return TrackingDomain::normalize($storedDomain) === '' ? null : self::storedScheme($storedDomain);
    }

    /**
     * The tracking domain of a URL handed back to the requester, `host[:port]`:
     * the stored domain when there is one, otherwise the host this request
     * arrived at (requestHost()).
     *
     * getTrackingDomain() fell back to the server's own name and port, which
     * is the address the web server listens on, not the one a browser
     * reaches: behind a reverse proxy or a Docker port mapping the links,
     * snippets and postback URLs on every page carried the internal port
     * (http://example.com:8080/… when the site answers on 443, or
     * http://localhost/… when the container's 80 is published on 8080) —
     * measured, the API's tracker URL and the setup pages all named
     * 127.0.0.1:8128 for a request that came in as proxy.example:9443. The
     * Host header is the address the requester used, so in the requester's
     * own response it is the one that works, and a forged one misleads only
     * the forger (CLAUDE.md #16). Anything sent to someone else — an email,
     * the hosted service, the server calling itself — takes the stored
     * domain instead (p202TrackingBaseUrl(), PasswordResetLink).
     *
     * @param string $storedDomain user 1's user_tracking_domain, as stored
     * @param array<string, mixed> $server normally $_SERVER
     */
    public static function domainForResponse(string $storedDomain, array $server): string
    {
        $stored = TrackingDomain::normalize($storedDomain);

        return $stored !== '' ? $stored : self::requestHost($server);
    }

    /**
     * build() for a URL handed back to the requester: with no stored domain,
     * this install on the request's own origin (forRequest()) rather than on
     * the server's name and port (domainForResponse() says why).
     *
     * @param string $storedDomain user 1's user_tracking_domain, as stored
     * @param array<string, mixed> $server normally $_SERVER
     * @param string $installRoot the install's directory on disk
     */
    public static function buildForResponse(string $storedDomain, array $server, string $installRoot): string
    {
        if (TrackingDomain::normalize($storedDomain) === '') {
            return self::forRequest($server, $installRoot);
        }

        return self::build($storedDomain, $server, $installRoot);
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
     * The server's own name: only host characters, and the port when it is
     * not 80 or 443. build()'s fallback, for the URLs sent to someone else.
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
