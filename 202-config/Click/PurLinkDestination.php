<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The URL a redirect standing in for a PurLink goes to: the link's own
 * template when it is one of the campaign's URLs with another query (a deep
 * link), else the URL the campaign chose. Scheme, host, port and path must
 * be the campaign's: only the query may differ.
 */
final class PurLinkDestination
{
    public const PARAM = 'p202_to';
    private const MAX_BYTES = 2048;
    private const COLUMNS = [
        'aff_campaign_url', 'aff_campaign_url_2', 'aff_campaign_url_3', 'aff_campaign_url_4', 'aff_campaign_url_5',
    ];

    private function __construct()
    {
    }

    /**
     * @param string $chosen the campaign URL the redirect would use (rotation applied)
     * @param mixed $requested the request's p202_to
     * @param array<string, mixed> $campaign a row with aff_campaign_url and aff_campaign_url_2..5
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
        foreach (self::COLUMNS as $column) {
            $url = $campaign[$column] ?? null;
            if (!is_string($url) || $url === '') {
                continue;
            }
            // The campaign's own PurLink names no deep link: rotation stands.
            if ($plain === $url || $requested === $url) {
                return $chosen;
            }
            if (self::place($url) === $place) {
                $samePlace = true;
            }
        }

        return $samePlace ? $requested : $chosen;
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
