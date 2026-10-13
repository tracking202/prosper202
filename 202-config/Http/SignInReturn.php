<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Where the sign-in page may send a person back to: a page on this install.
 *
 * The page sent there is named by a query parameter anyone can put in a
 * link, so it is a path or nothing. A first `/` is not enough: a browser
 * reads `//host/...` and `/\host/...` as another site, and a control
 * character can split the Location header. What passes is one `/` followed
 * by printable ASCII with no backslash, and nothing a URL parser reads as a
 * scheme or a host.
 */
final class SignInReturn
{
    /** The path to send the person to, or null for anything that is not a path on this site. */
    public static function path(string $raw): ?string
    {
        if ($raw === '' || $raw[0] !== '/' || strlen($raw) > 2048) {
            return null;
        }
        if (isset($raw[1]) && ($raw[1] === '/' || $raw[1] === '\\')) {
            return null;
        }
        if (preg_match('/^[\x21-\x7e]+$/D', $raw) !== 1 || str_contains($raw, '\\')) {
            return null;
        }
        $parts = parse_url($raw);
        if ($parts === false || isset($parts['scheme']) || isset($parts['host']) || isset($parts['user'])) {
            return null;
        }
        return $raw;
    }
}
