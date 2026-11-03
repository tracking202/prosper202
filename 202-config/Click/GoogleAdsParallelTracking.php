<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * Google Ads parallel tracking for a landing-page tracker: the page itself as
 * the ad's final URL, and pt.php as the tracking template carrying the
 * tracker and its variables. The page's script takes the template's click by
 * the gclid Google appends to both (ParallelTrackingClick).
 *
 * Google requires {lpurl} in a template set above the ad, and in the
 * background call replaces it with its own address that answers 204, so the
 * page is not loaded twice. Paid 1.9.56's templates had no lpurl: those go to
 * the tracker's landing page.
 */
final class GoogleAdsParallelTracking
{
    private const SUBSTITUTE_HOSTS = ['google.com', 'www.google.com'];

    private function __construct()
    {
    }

    /** The landing page as saved: no t202id or tracking variables for the visitor to see. */
    public static function finalUrl(string $landingPageUrl): string
    {
        return trim($landingPageUrl);
    }

    /**
     * @param string $linkBase the links' base, `scheme://host/…/` (TrackingBaseUrl)
     * @param string $variableQuery '&…' (TrackingLinkVariables::query())
     */
    public static function trackingTemplate(string $linkBase, string $trackerIdPublic, string $variableQuery): string
    {
        return $linkBase . 'tracking202/redirect/pt.php?t202id=' . $trackerIdPublic . $variableQuery
            . '&lpurl={lpurl}';
    }

    /**
     * Where pt.php sends its caller. lpurl is the caller's to set, so it is
     * followed only to Google's stand-in or to the landing page's own host;
     * anything else goes to the saved page, as a template with no lpurl does.
     *
     * @param array<string, string> $adClickIds ClickRequest::adClickIds(), added where lpurl lacks them
     * @param string $passOn the saved page's extra query (getPrePopVars())
     */
    public static function redirectTarget(
        string $landingPageUrl,
        string $lpurl,
        array $adClickIds,
        string $passOn
    ): string {
        if (self::isSubstitute($lpurl)) {
            return $lpurl;
        }

        $lpQuery = [];
        $onPage = self::sameSite($lpurl, $landingPageUrl);
        if ($onPage) {
            parse_str((string) parse_url($lpurl, PHP_URL_QUERY), $lpQuery);
        }
        $add = $onPage ? '' : $passOn;
        foreach ($adClickIds as $name => $value) {
            if ($value !== '' && !isset($lpQuery[$name])) {
                $add .= $name . '=' . rawurlencode($value) . '&';
            }
        }

        return self::withQuery($onPage ? $lpurl : $landingPageUrl, $add);
    }

    /** Google's stand-in for {lpurl} in a background call, e.g. https://www.google.com/asnc/…. */
    public static function isSubstitute(string $url): bool
    {
        $parts = self::parts($url);

        return $parts !== null
            && strtolower($parts['scheme'] ?? '') === 'https'
            && in_array(strtolower($parts['host'] ?? ''), self::SUBSTITUTE_HOSTS, true)
            && !isset($parts['port'])
            && str_starts_with($parts['path'] ?? '', '/asnc/');
    }

    private static function sameSite(string $url, string $landingPageUrl): bool
    {
        $a = self::parts($url);
        $b = parse_url($landingPageUrl);
        if ($a === null || !is_array($b)) {
            return false;
        }
        $scheme = strtolower($a['scheme'] ?? '');

        return ($scheme === 'https' || $scheme === 'http')
            && ($a['host'] ?? '') !== ''
            && strtolower($a['host']) === strtolower($b['host'] ?? '');
    }

    /**
     * A URL's parts, or null for one a browser could read another way: a
     * backslash, a space or control byte, or a user in the authority.
     *
     * @return array<string, int|string>|null
     */
    private static function parts(string $url): ?array
    {
        if ($url === '' || preg_match('/[\\\\\s\x00-\x1f\x7f]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        return $parts;
    }

    /** $query ('a=1&b=2&') added before any #fragment. */
    private static function withQuery(string $url, string $query): string
    {
        $query = rtrim($query, '&');
        if ($query === '') {
            return $url;
        }
        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }
        $url = rtrim($url, '?&');

        return $url . (str_contains($url, '?') ? '&' : '?') . $query . $fragment;
    }
}
