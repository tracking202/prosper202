<?php

declare(strict_types=1);

/**
 * How a signed-in session is kept, for the two bootstraps that read one:
 * connect.php (every page) and the v3 API (a dashboard on the same origin,
 * Api\V3\SessionSignIn). One copy, so the API cannot start the session with a
 * cookie the pages would not have sent, or write to it another way.
 */

require_once __DIR__ . '/request-https.php';

if (!function_exists('p202_session_settings')) {
    /**
     * The session's settings, before it starts.
     *
     * - gc_maxlifetime 50000 s, the sign-in's own limit (AUTH::sessionClaimsSignIn());
     *   PHP's default (1440 s) silently destroyed sessions.
     * - The cookie ends when the browser closes, is HttpOnly (not exposed to
     *   document.cookie), SameSite Lax (not sent on a cross-site POST, still
     *   on a top-level navigation), and Secure when the request came over
     *   HTTPS, as p202_request_is_https() reads it behind a proxy too.
     * - Strict mode: an id the server never issued is refused.
     */
    function p202_session_settings(): void
    {
        ini_set('session.gc_maxlifetime', '50000');
        ini_set('session.cookie_lifetime', '0');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', p202_request_is_https($_SERVER) ? '1' : '0');
        ini_set('session.use_strict_mode', '1');
    }
}

if (!function_exists('withWritableSession')) {
    /**
     * Reopen a read-only session just long enough to persist writes, then
     * release the session lock again for the rest of the request.
     */
    function withWritableSession(callable $callback): void
    {
        $openedHere = false;

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (headers_sent()) {
                error_log('withWritableSession: headers already sent; session writes may not persist');
                $callback();
                return;
            }

            session_start();
            $openedHere = true;
        }

        $callback();

        if ($openedHere && session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }
}
