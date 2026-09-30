<?php

declare(strict_types=1);

/**
 * Whether the browser reached this install over HTTPS, directly or through a
 * TLS-terminating proxy that says so (X-Forwarded-Proto, X-Forwarded-SSL,
 * X-Forwarded-Port) — the one answer every session cookie's Secure flag is
 * set from: connect.php for every page that can load the configuration, and
 * the setup wizard (p202_standalone_wizard_token()), which runs before there
 * is one. getSecureStatus() asks it too.
 *
 * A small file of its own because connect.php needs it before the session
 * starts, long before functions.php is loaded, and the wizard needs it with
 * no configuration at all.
 *
 * The forwarded headers are believed from any peer, and that is safe for
 * what this decides: a Secure flag only withholds the cookie from plain
 * HTTP, so a client that claims HTTPS falsely makes its own cookie
 * unusable over plain HTTP and nobody else's (error pattern #16 asks what
 * the claim can win; here, nothing). Do not reuse it for a decision a false
 * claim could win — trusting a peer, choosing a redirect target.
 *
 * @param array<string, mixed> $server normally $_SERVER
 */
function p202_request_is_https(array $server): bool
{
    $lower = static fn (string $key): string
        => is_scalar($server[$key] ?? null) ? strtolower((string) $server[$key]) : '';

    return (!empty($server['HTTPS']) && $lower('HTTPS') !== 'off')
        || $lower('HTTP_X_FORWARDED_PROTO') === 'https'
        || $lower('HTTP_X_FORWARDED_SSL') === 'on'
        || (isset($server['SERVER_PORT']) && (int) $server['SERVER_PORT'] === 443)
        || (isset($server['HTTP_X_FORWARDED_PORT']) && (int) $server['HTTP_X_FORWARDED_PORT'] === 443)
        || $lower('REQUEST_SCHEME') === 'https';
}
