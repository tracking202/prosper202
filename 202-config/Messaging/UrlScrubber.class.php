<?php
// 202-config/Messaging/UrlScrubber.class.php
// An offer URL as the analytics profile may carry it: the scheme, host and
// port, each path segment and query key that reads as a plain word, and query
// values that are tracking macros or short numbers; everything else is
// redacted. Sign-in details and the fragment are never kept, and a URL that
// is not http(s) with a plain host is not sent at all.

final class UrlScrubber
{
    public const REDACTED = 'redacted';

    /** A path segment or query key kept as written: letters, digits, '-', '_', '.', '~'. */
    private const PLAIN = '/^[A-Za-z0-9._~-]{1,64}$/D';

    /** A tracking macro the URL is a template for: {clickid}, [[subid]], #s1#. */
    private const MACRO = '/^(\{[A-Za-z0-9_.:-]{1,40}\}|\[\[[A-Za-z0-9_.:-]{1,40}\]\]|#[A-Za-z0-9_]{1,40}#)$/D';

    /** A query value kept as written besides a macro: an id of at most six digits. */
    private const SHORT_NUMBER = '/^[0-9]{1,6}$/D';

    /** Seven or more digits anywhere: a phone number, an account or order number. */
    private const LONG_NUMBER = '/[0-9]{7,}/';

    public static function scrub(string $url): string
    {
        $parts = parse_url(trim($url));
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        if (!in_array($scheme, ['http', 'https'], true)
            || preg_match('/^[a-z0-9.-]{1,253}$/D', $host) !== 1
            || str_contains($host, '@')) {
            return '';
        }

        $out = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '');

        $path = $parts['path'] ?? '';
        if ($path !== '') {
            $segments = array_map(
                static fn (string $segment): string => $segment === '' ? '' : self::keep(rawurldecode($segment)),
                explode('/', $path)
            );
            $out .= implode('/', $segments);
        }

        // Split by hand: parse_str() keeps only the last of a repeated key
        // and reads `a[b]` as nesting.
        $query = $parts['query'] ?? '';
        if ($query !== '') {
            $pairs = [];
            foreach (explode('&', $query) as $pair) {
                if ($pair === '') {
                    continue;
                }
                [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
                $key = self::keep(urldecode($key));
                $pairs[] = $value === null ? $key : $key . '=' . self::keepValue(urldecode($value));
            }
            if ($pairs !== []) {
                $out .= '?' . implode('&', $pairs);
            }
        }

        return $out;
    }

    /** A query value: kept only when it is a macro or a short number. */
    private static function keepValue(string $text): string
    {
        return preg_match(self::MACRO, $text) === 1 || preg_match(self::SHORT_NUMBER, $text) === 1 ? $text : self::REDACTED;
    }

    /** $text when it is a macro or a plain word with no long number in it, else the redaction mark. */
    private static function keep(string $text): string
    {
        if (preg_match(self::MACRO, $text) === 1) {
            return $text;
        }
        if (preg_match(self::PLAIN, $text) === 1 && preg_match(self::LONG_NUMBER, $text) !== 1) {
            return $text;
        }

        return self::REDACTED;
    }
}
