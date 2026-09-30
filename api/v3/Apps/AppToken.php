<?php

declare(strict_types=1);

namespace Api\V3\Apps;

/**
 * The app token: the value an app build presents, in the X-P202-App-Token
 * header, to the pre-auth app routes (GET /apps/schema today).
 *
 * It is an IDENTIFIER, not a secret. It ships inside every copy of the app
 * binary, so anyone can extract it; what it buys is that a request names one
 * registration without an API key, and that a token lifted into another app
 * is answered with a visible mismatch rather than silently accepted. It is
 * still kept out of logs, query strings and staged-change records, and it
 * rotates (POST /apps/{id}/app-token/rotate) so a leaked build can be cut
 * off from the next release on.
 *
 * Header-only: a token in a GET query string would be captured by ordinary
 * request logging.
 */
final class AppToken
{
    /** The request header the token travels in. */
    public const HEADER = 'X-P202-App-Token';

    /** The same header as RequestContext::header() looks it up. */
    public const HEADER_LOOKUP = 'x-p202-app-token';

    public static function mint(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Whether a presented value has the shape mint() produces: exactly 64
     * hexadecimal characters. Anything else is a paste of the wrong value (an
     * API key, a truncated copy) and is answered as such rather than with a
     * misleading "unknown token".
     */
    public static function isWellFormed(string $token): bool
    {
        return strlen($token) === 64 && ctype_xdigit($token);
    }
}
