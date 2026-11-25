<?php

declare(strict_types=1);

/**
 * Whether the browser reached this install over HTTPS: the web server's own
 * answer (HTTPS, port 443, REQUEST_SCHEME, which its configuration sets), or
 * a TLS-terminating proxy listed in P202_TRUSTED_PROXIES that says so
 * (X-Forwarded-Proto, X-Forwarded-SSL, X-Forwarded-Port, or `proto=https` in
 * the Forwarded element of the client VisitorIp names). The one answer every
 * cookie's Secure flag is set from: connect.php for every page that can load
 * the configuration, the setup wizard (p202_standalone_wizard_token()), which
 * runs before there is one, getSecureStatus(), and the p202vid cookie
 * (RequestSignals::requestIsHttps()). The one reader of those headers
 * (ForwardedIpHeaderReadTest).
 *
 * A small file of its own because connect.php needs it before the session
 * starts, long before the autoloader and functions.php are loaded, and the
 * wizard needs it with no configuration at all.
 *
 * From any other peer the headers are the client's own claim (CLAUDE.md #16)
 * and are not read.
 *
 * @param array<string, mixed> $server normally $_SERVER
 * @param ?\Prosper202\Http\TrustedProxies $trusted the environment's setting when not given
 */
function p202_request_is_https(array $server, ?\Prosper202\Http\TrustedProxies $trusted = null): bool
{
    $lower = static fn (string $key): string
        => is_scalar($server[$key] ?? null) ? strtolower((string) $server[$key]) : '';

    if ((!empty($server['HTTPS']) && $lower('HTTPS') !== 'off')
        || (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443)
        || $lower('REQUEST_SCHEME') === 'https') {
        return true;
    }
    foreach (['CloudflareRanges', 'TrustedProxies', 'ForwardedHeader', 'VisitorIp'] as $class) {
        if (!class_exists('Prosper202\\Http\\' . $class, false)) {
            require_once __DIR__ . '/Http/' . $class . '.php';
        }
    }
    $trusted ??= \Prosper202\Http\TrustedProxies::fromEnvironment();
    if (!$trusted->trusts(trim($lower('REMOTE_ADDR')))) {
        return false;
    }

    return $lower('HTTP_X_FORWARDED_PROTO') === 'https'
        || $lower('HTTP_X_FORWARDED_SSL') === 'on'
        || (isset($server['HTTP_X_FORWARDED_PORT']) && (int) $server['HTTP_X_FORWARDED_PORT'] === 443)
        || \Prosper202\Http\VisitorIp::forwardedProtoIsHttps($server, $trusted);
}
