<?php

declare(strict_types=1);

namespace Prosper202\Click;

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
