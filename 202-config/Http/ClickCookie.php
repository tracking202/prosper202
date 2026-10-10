<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Reads a click cookie (tracking202subid, tracking202subid_a_<campaign>,
 * tracking202pci, tracking202outbound): the cookie itself, or else its
 * `-legacy` twin.
 *
 * The click path sets every one of these twice: once with `Secure;
 * SameSite=None`, so a landing page on another site and a pixel on another
 * site can carry it, and once as `<name>-legacy` with neither attribute.
 * Every reader read only the first. Measured in Chromium 141: on a tracker
 * served over plain HTTP from a host name, a `Secure` cookie is refused
 * outright, and `SameSite=None` without `Secure` is refused too, so the
 * first cookie never exists there; the `-legacy` one is kept wherever a
 * Lax cookie is (a top-level response, a landing page or shop on the
 * tracker's own site) and sent back on the same terms. So on such an
 * install lp.php found no click and set no click_out, and gpx.php fell back
 * to the visitor's address and could credit someone else's click, while
 * the browser was sending the right click id under the other name. (On
 * 127.0.0.1 or localhost Chromium accepts Secure cookies over HTTP, which
 * is why a local test never showed it.)
 *
 * The first cookie wins when both are there: over HTTPS it is the one a
 * cross-site response can refresh, so it is never older than its twin.
 * Neither can reach a landing page or pixel on another site over plain
 * HTTP — a cross-site response may not set a Lax cookie and a cross-site
 * image does not send one — so there the address fallback is all there is,
 * and HTTPS is the remedy.
 */
final class ClickCookie
{
    public const LEGACY_SUFFIX = '-legacy';

    private function __construct()
    {
    }

    /**
     * The cookie's value as the request sent it, or its -legacy twin's when
     * it is missing or empty; null when neither is there. A value that is
     * not a string (a `name[]` cookie) is returned as it is, for the caller
     * to refuse.
     *
     * @param array<string, mixed> $cookies normally $_COOKIE
     */
    public static function value(array $cookies, string $name): mixed
    {
        foreach ([$name, $name . self::LEGACY_SUFFIX] as $key) {
            if (isset($cookies[$key]) && $cookies[$key] !== '') {
                return $cookies[$key];
            }
        }

        return null;
    }
}
