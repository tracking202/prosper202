<?php

declare(strict_types=1);

namespace Api\V3\Apps;

use Api\V3\Bootstrap;
use Api\V3\Support\ServerStateStore;

/**
 * The HTTP plumbing every public (pre-auth) app route shares, written once
 * so the trust-boundary decisions are made in one place (plan §4.6):
 *
 *  - the method check (405) and a declared body over the cap (413), both
 *    before anything connects to the database;
 *  - database bring-up, answering 503 so a device retries later;
 *  - the soft rate limit, keyed on the validated TCP peer and never on a
 *    client header (CLAUDE.md #16), with injective bucket names
 *    (ServerStateStore, #17), failing open so the limiter's own failure
 *    never blocks devices;
 *  - the bounded body read.
 *
 * Callers: the Apple /.well-known/ postback entry points (through
 * Apple\PostbackIntake), whose URLs Apple dictates, and the pre-auth routes
 * in api/v3/index.php — GET /apps/schema today, POST /apps/installs with the
 * Android intake. Every step answers and exits on refusal, so a caller
 * reads as the list of steps it takes.
 */
final class PublicIntake
{
    /**
     * Refuse what can be refused without a database: a method this route
     * does not serve (405, with Allow) and a body that declares itself over
     * the cap (413). Returns the validated method.
     *
     * Nothing here reads state, so calling it twice is the same as calling
     * it once (the postback prelude does, then PostbackIntake::serve() does).
     *
     * @param list<string> $allowedMethods upper-case; HEAD is served wherever GET is
     */
    public static function preflight(int $maxBodyBytes, array $allowedMethods): string
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $allowed = $allowedMethods;
        if (in_array('GET', $allowed, true) && !in_array('HEAD', $allowed, true)) {
            $allowed[] = 'HEAD';
        }

        if (!in_array($method, $allowed, true)) {
            header('Allow: ' . implode(', ', array_values(array_diff($allowed, ['HEAD']))));
            Bootstrap::errorResponse('Method not allowed', 405);
            exit;
        }

        // Advisory only, in the refusing direction: a chunked request
        // declares no length and the header can lie, so what actually
        // arrived is measured by readBody()'s caller, which stays the
        // authority for the 413.
        $declaredLength = $_SERVER['CONTENT_LENGTH'] ?? null;
        if (
            $method === 'POST'
            && is_string($declaredLength)
            && ctype_digit($declaredLength)
            && (int)$declaredLength > $maxBodyBytes
        ) {
            Bootstrap::errorResponse('Request body too large', 413);
            exit;
        }

        return $method;
    }

    /**
     * The database, or a 503: a non-200 makes the device retry later, so an
     * outage loses nothing.
     */
    public static function database(): \mysqli
    {
        try {
            Bootstrap::init();
            return Bootstrap::db();
        } catch (\Throwable) {
            Bootstrap::errorResponse('Service unavailable', 503);
            exit;
        }
    }

    /**
     * The soft per-peer limit: answers 429 with Retry-After and exits when
     * the peer is over it.
     *
     * Behind a TLS-terminating proxy the peer is the proxy, so the ceiling is
     * an aggregate one; that is the price of never keying on a header an
     * attacker chooses. softIpRateLimit() fails open itself; the catch here
     * covers building the store.
     */
    public static function rateLimit(string $bucket, int $perWindow, int $windowSeconds): void
    {
        try {
            $retryAfter = (new ServerStateStore())->softIpRateLimit($bucket, $perWindow, $windowSeconds);
        } catch (\Throwable $e) {
            error_log('p202 apps: rate limiter unavailable for ' . $bucket . ', serving request: ' . $e->getMessage());
            $retryAfter = null;
        }
        if ($retryAfter !== null) {
            header('Retry-After: ' . $retryAfter);
            Bootstrap::errorResponse('Rate limit exceeded', 429, ['retry_after_seconds' => $retryAfter]);
            exit;
        }
    }

    /**
     * The request body, read to at most one byte past the cap so the caller
     * can tell "exactly at the cap" from "over it" and answer 413 itself.
     */
    public static function readBody(int $maxBodyBytes): string
    {
        $rawBody = file_get_contents('php://input', false, null, 0, $maxBodyBytes + 1);
        if ($rawBody === false) {
            Bootstrap::errorResponse('Failed to read request body', 500);
            exit;
        }
        return $rawBody;
    }
}
