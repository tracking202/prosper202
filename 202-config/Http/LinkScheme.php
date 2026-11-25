<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Which scheme a browser will read in a URL a visitor or a request supplied.
 *
 * A browser drops leading and trailing control characters and spaces and
 * every tab and newline before it reads the scheme, so a blocklist that reads
 * the bytes as sent passes `java<tab>script:` and `<0x01>javascript:`, and
 * each runs as script in an href or a `window.location`. The scheme is read
 * here from the URL with every byte from 0x00 to 0x20 and 0x7f removed, which
 * removes at least what a browser removes, so whatever scheme the browser
 * sees is the one read here; anything but http and https is refused.
 */
final class LinkScheme
{
    private function __construct()
    {
    }

    /**
     * The URL for an href: http, https, scheme-relative and relative URLs
     * pass as they were sent (trimmed); anything with another scheme is ''.
     */
    public static function forLink(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $scheme = self::schemeOf($url);

        return $scheme === null || $scheme === 'http' || $scheme === 'https' ? $url : '';
    }

    /**
     * The URL to send a visitor on to: an absolute http or https URL, or null.
     * A relative or scheme-relative URL is refused too, since the page that
     * sends it on does not know which host the sender meant.
     */
    public static function forRedirect(string $url): ?string
    {
        $url = trim($url);
        $scheme = self::schemeOf($url);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $rest = substr(self::withoutIgnored($url), strlen($scheme) + 1);

        return str_starts_with($rest, '//') && strlen($rest) > 2 ? $url : null;
    }

    /** The scheme a browser reads, lower-cased, or null for a URL with none. */
    public static function schemeOf(string $url): ?string
    {
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', self::withoutIgnored($url), $m) !== 1) {
            return null;
        }

        return strtolower($m[1]);
    }

    private static function withoutIgnored(string $url): string
    {
        return (string) preg_replace('/[\x00-\x20\x7f]+/', '', $url);
    }
}
