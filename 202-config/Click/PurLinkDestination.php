<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Conversion\TrafficSourcePixels;

/**
 * The URL a redirect standing in for a PurLink goes to: the link's own
 * template when it is one of the campaign's URLs with another query (a deep
 * link), else the URL the campaign chose. Scheme, host, port and path must
 * be those of a URL the campaign's redirect uses (CampaignUrls): only the
 * query may differ. A [[token]] those URLs hold stays for the replacer; any
 * other is taken out, so a link keeps its destination and fills no value
 * the campaign's own URL does not send.
 */
final class PurLinkDestination
{
    public const PARAM = 'p202_to';
    private const MAX_BYTES = 2048;

    private function __construct()
    {
    }

    /**
     * @param string $chosen the campaign URL the redirect would use (rotation applied)
     * @param mixed $requested the request's p202_to
     * @param array<string, mixed> $campaign a row with aff_campaign_rotate, aff_campaign_url and aff_campaign_url_2..5
     */
    public static function choose(string $chosen, mixed $requested, array $campaign): string
    {
        if (!is_string($requested) || $requested === '' || strlen($requested) > self::MAX_BYTES) {
            return $chosen;
        }
        $place = self::place($requested);
        if ($place === null) {
            return $chosen;
        }
        $plain = self::withoutOfferMarker($requested);
        $samePlace = false;
        $tokens = [];
        foreach (CampaignUrls::inRotation($campaign) as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            // The campaign's own PurLink names no deep link: rotation stands.
            if ($plain === $url || $requested === $url) {
                return $chosen;
            }
            if (self::place($url) === $place) {
                $samePlace = true;
                $tokens = array_merge($tokens, TrafficSourcePixels::tokensNamedIn($url));
            }
        }
        if (!$samePlace) {
            return $chosen;
        }
        $known = TrafficSourcePixels::tokenSpellings();
        try {
            // Less every token as written, no bracket pair may remain: one
            // could be, or become once a token beside it is emptied or
            // filled, a token the campaign's URL does not hold. One pass, so
            // a token that taking another out puts together is still there
            // to be seen.
            $rest = TrafficSourcePixels::withoutTokens($requested, $known);
            if (str_contains($rest, '[[') || str_contains($rest, ']]')) {
                error_log('Prosper202 PurLink destination: a link\'s own URL holds brackets that are not a whole'
                    . ' token; the campaign\'s URL is used');

                return $chosen;
            }

            // A token the campaign's URL does not hold is left empty, as the
            // replacer leaves one no click has a value for.
            return TrafficSourcePixels::withoutTokens($requested, array_values(array_diff($known, $tokens)));
        } catch (\RuntimeException $e) {
            error_log('Prosper202 PurLink destination: ' . $e->getMessage() . '; the campaign\'s URL is used');

            return $chosen;
        }
    }

    /** "&p202_to=…" for a hop that chooses the destination itself, or "" for a value choose() never takes. */
    public static function query(mixed $requested): string
    {
        if (!is_string($requested) || $requested === '' || strlen($requested) > self::MAX_BYTES) {
            return '';
        }

        return '&' . self::PARAM . '=' . rawurlencode($requested);
    }

    /** scheme://host:port/path in one spelling, or null for a URL a redirect may not take. */
    private static function place(string $url): ?string
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        $host = strtolower($parts['host'] ?? '');
        $scheme = strtolower($parts['scheme'] ?? '');
        if ($host === '' || ($scheme !== 'http' && $scheme !== 'https')) {
            return null;
        }
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        $path = $parts['path'] ?? '';

        return $scheme . '://' . $host . ':' . $port . ($path === '' ? '/' : $path);
    }

    /** The template less the acip= PurLinkTemplate::advanced() adds. */
    private static function withoutOfferMarker(string $url): string
    {
        $fragmentAt = strpos($url, '#');
        $head = $fragmentAt === false ? $url : substr($url, 0, $fragmentAt);
        $fragment = $fragmentAt === false ? '' : substr($url, $fragmentAt);
        $head = (string) preg_replace('/([?&])acip=[0-9]+(?=&|$)/', '$1', $head, 1);
        $head = str_replace(['?&', '&&'], ['?', '&'], $head);
        $head = preg_replace('/[?&]$/', '', $head);

        return $head . $fragment;
    }
}
