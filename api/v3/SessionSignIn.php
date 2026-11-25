<?php

declare(strict_types=1);

namespace Api\V3;

/**
 * A request signed in by the pages' own session cookie, for a dashboard on
 * the same origin that holds no API key.
 *
 * The session is read by the pages' rules (AUTH::signedInUserId():
 * its fingerprint and age, the user's standing and session generation read
 * now, the session ended when either says no), so switching a user off or
 * changing their password ends this access on the next call as it ends the
 * pages'. Every method but GET and HEAD carries the session's anti-CSRF
 * token in X-P202-CSRF, compared by AUTH::csrf_token_matches(): the cookie
 * rides along on a request another site makes, the header cannot. The
 * remember-me cookie is not tried here: a browser holding only that signs
 * in through a page first.
 *
 * A request with an Authorization header, whatever it holds, is a key's
 * (Auth::fromRequest()), cookie or not.
 */
final class SessionSignIn
{
    public const CSRF_HEADER = 'X-P202-CSRF';

    /** Methods that change nothing, so carry no token. */
    private const SAFE_METHODS = ['GET', 'HEAD'];

    /**
     * Whether this request asks to be signed in by its session: it bears no
     * Authorization header at all, and it carries the session cookie.
     *
     * @param array<string, mixed> $headers
     * @param array<string, mixed> $cookies
     */
    public static function applies(array $headers, array $cookies): bool
    {
        if (self::header($headers, 'authorization') !== null) {
            return false;
        }
        $cookie = $cookies[session_name()] ?? null;

        return is_string($cookie) && $cookie !== '';
    }

    /**
     * The principal of a signed-in session, or an AuthException: 401 when
     * the session names nobody who may act, 403 for a write without the
     * session's token.
     *
     * @param array<string, mixed> $headers
     */
    public static function authenticate(string $method, array $headers, \mysqli $db): Auth
    {
        self::start();

        $userId = \AUTH::signedInUserId();
        if ($userId === null) {
            throw new AuthException(
                'Not signed in: the session has ended or names no account. Sign in again, or send an API key in the Authorization header.',
                401
            );
        }

        // A signed-in session holds a token from connect.php; one blanked
        // since is seeded again, as connect.php does, so /me can hand it out.
        if (!\AUTH::csrf_token_usable($_SESSION['token'] ?? null)) {
            \withWritableSession(static function (): void {
                if (!\AUTH::csrf_token_usable($_SESSION['token'] ?? null)) {
                    $_SESSION['token'] = \AUTH::mintCsrfToken();
                }
            });
        }

        if (!in_array(strtoupper($method), self::SAFE_METHODS, true)
            && !\AUTH::csrf_token_matches(self::header($headers, strtolower(self::CSRF_HEADER)))) {
            throw new AuthException(
                'A write signed in by the session needs the session\'s token in the ' . self::CSRF_HEADER
                . ' header (GET /me answers it as auth.csrf_token). Nothing was changed.',
                403
            );
        }

        return Auth::forSignedInUser($userId, $db);
    }

    /** The session's anti-CSRF token, for GET /me; null when it holds none. */
    public static function csrfToken(): ?string
    {
        $token = $_SESSION['token'] ?? null;

        return \AUTH::csrf_token_usable($token) ? $token : null;
    }

    private static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        require_once dirname(__DIR__, 2) . '/202-config/session-settings.php';
        p202_session_settings();
        // Read and released at once: a dashboard's parallel calls must not
        // queue on the session file's lock. Writes reopen it
        // (withWritableSession()).
        if (!session_start(['read_and_close' => true])) {
            throw new AuthException('Authentication unavailable', 500);
        }
    }

    /**
     * A header's value by its lower-case name, or null. A header sent twice
     * reaches getallheaders() as one string; an array is read as its first.
     *
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $lowerName): ?string
    {
        foreach ($headers as $name => $value) {
            if (strtolower((string) $name) !== $lowerName) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? null;
            }

            return is_string($value) ? $value : null;
        }

        return null;
    }
}
