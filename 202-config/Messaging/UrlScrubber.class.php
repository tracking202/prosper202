<?php
// 202-config/Messaging/UrlScrubber.class.php
// An offer URL as the analytics profile may carry it: the scheme, host and
// port, each path segment and query key that reads as words (short letter
// words joined by '-' or '_', each with at most a short number, or a number of
// at most six digits), and query values that are tracking macros or short
// numbers; everything else (tokens, hashes, dotted names, long numbers) is
// redacted. Sign-in details and the fragment are never kept, and a URL that
// is not http(s) with a plain host is not sent at all.

final class UrlScrubber
{
    public const REDACTED = 'redacted';

    /** A path segment or query key kept as written: words joined by '-' or '_', with an optional page extension. */
    private const WORDS = '/^[A-Za-z0-9]+(?:[-_][A-Za-z0-9]+)*(?:\\.(?:php|html?|aspx?|jsp|cfm|cgi))?$/Di';

    /** One word: letters with at most a four-digit number before or after, or a number of at most six digits. */
    private const WORD = '/^(?:[0-9]{0,4}[A-Za-z]{1,24}[0-9]{0,4}|[0-9]{1,6})$/D';

    /** Eight or more hex characters mixing letters and digits: a hash or a token. */
    private const HEX_TOKEN = '/(?=[0-9a-f]*[0-9])(?=[0-9a-f]*[a-f])[0-9a-f]{8,}/i';

    /** Longest path segment or query key kept. */
    private const MAX_LENGTH = 64;

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

    /** $text when it is a macro or reads as words with no token or long number in it, else the redaction mark. */
    private static function keep(string $text): string
    {
        if (preg_match(self::MACRO, $text) === 1) {
            return $text;
        }
        if (strlen($text) > self::MAX_LENGTH
            || preg_match(self::WORDS, $text) !== 1
            || preg_match(self::LONG_NUMBER, $text) === 1
            || preg_match(self::HEX_TOKEN, $text) === 1) {
            return self::REDACTED;
        }
        $stem = preg_replace('/\\.[A-Za-z]+$/D', '', $text) ?? '';
        foreach (preg_split('/[-_]/', $stem) ?: [] as $word) {
            if (preg_match(self::WORD, $word) !== 1) {
                return self::REDACTED;
            }
        }

        return $text;
    }
}
