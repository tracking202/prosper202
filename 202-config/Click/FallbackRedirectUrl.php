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
 * URL, and only then. It costs one memcache get per request and no query.
 */
final class FallbackRedirectUrl
{
    private function __construct()
    {
    }

    /**
     * lp.php's and off.php's fallback: the primary offer URL with `&subid=p202`,
     * or '' when the campaign has none. The suffix alone is truthy, and the
     * outage branch would redirect to it as a relative path; '' disables it.
     */
    public static function withSubid(mixed $primaryUrl): string
    {
        $url = is_string($primaryUrl) ? $primaryUrl : '';

        return trim($url) === '' ? '' : $url . '&subid=p202';
    }

    /**
     * @param Closure(string): mixed         $cacheGet getCache($key); false when absent
     * @param Closure(string, string): mixed $cacheSet setCache($key, $url, 0)
     *
     * @return bool whether the key was written: false when it was already
     *              current, or when the write failed
     */
    public static function refresh(string $key, string $currentUrl, Closure $cacheGet, Closure $cacheSet): bool
    {
        if ($cacheGet($key) === $currentUrl) {
            return false;
        }

        return $cacheSet($key, $currentUrl) !== false;
    }
}
