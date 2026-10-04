<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Closure;

/**
 * The URL a redirect script sends visitors to while MySQL is down: dl.php's
 * `url_`, lp.php's `lp_` and off.php's `ac_` memcache keys, each written on
 * the script's normal path and read only when there is no database.
 *
 * They were written once, without expiry, and never again, so after an offer
 * URL changed an outage still sent visitors to the URL of the link's first
 * click. refresh() rewrites the key whenever the normal path sees a different
 * URL, and only then; it costs the get the scripts already made, no query.
 */
final class FallbackRedirectUrl
{
    private function __construct()
    {
    }

    /**
     * @param Closure(string): mixed         $cacheGet getCache($key); false when absent
     * @param Closure(string, string): mixed $cacheSet setCache($key, $url, 0)
     *
     * @return bool whether the key was written
     */
    public static function refresh(string $key, string $currentUrl, Closure $cacheGet, Closure $cacheSet): bool
    {
        if ($cacheGet($key) === $currentUrl) {
            return false;
        }
        $cacheSet($key, $currentUrl);

        return true;
    }
}
