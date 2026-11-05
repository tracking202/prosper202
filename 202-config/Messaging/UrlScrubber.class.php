<?php
// 202-config/Messaging/UrlScrubber.class.php
// An offer URL as the analytics profile may carry it: the scheme, host and
// port, each path segment and query key that reads as words (short letter
// words joined by '-' or '_', each with at most a short number, or a number of
// at most six digits), and query values that are tracking macros or short
// numbers; everything else (tokens, hashes, dotted names, long numbers) is
// redacted. A number counts as long when a part holds seven or more digits in
// all, however they are split up; and when the kept path segments, query keys
// and query values hold seven or more digits between them, every one of them
// with a digit in it is redacted, so a number cannot be spread across parts.
// Macros follow the same rules inside their brackets.
// Sign-in details and the fragment are never kept, a URL that is not http(s)
// with a plain host is not sent at all, and the result is cut to 512
// characters at a part boundary.

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

    /** A tracking macro the URL is a template for: {clickid}, [[subid]], #s1#; the name inside is checked separately. */
    private const MACRO = '/^(?:\{([A-Za-z0-9_.:-]{1,40})\}|\[\[([A-Za-z0-9_.:-]{1,40})\]\]|#([A-Za-z0-9_]{1,40})#)$/D';

    /** Tracker namespaces a dotted macro may start with, e.g. {campaign.id}; any other dotted name is redacted. */
    private const MACRO_NAMESPACES = [
        'campaign', 'offer', 'lander', 'landing', 'trafficsource', 'traffic', 'source', 'affiliate',
        'affiliatenetwork', 'network', 'click', 'visit', 'device', 'os', 'browser', 'geo', 'country',
        'conversion', 'ad', 'adset', 'creative', 'placement', 'site', 'p202', 'var', 'custom',
    ];

    /** Most digits a kept part may hold in all, however they are separated. */
    private const MAX_DIGITS = 6;

    /** Longest scrubbed URL sent; longer ones are cut at a part boundary. */
    private const MAX_URL_LENGTH = 512;

    /** A query value kept as written besides a macro: an id of at most six digits. */
    private const SHORT_NUMBER = '/^[0-9]{1,6}$/D';

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

        $segments = [];
        $path = $parts['path'] ?? '';
        if ($path !== '') {
            $segments = array_map(
                static fn (string $segment): string => $segment === '' ? '' : self::keep(rawurldecode($segment)),
                explode('/', $path)
            );
        }

        // Split by hand: parse_str() keeps only the last of a repeated key
        // and reads `a[b]` as nesting.
        $keys = [];
        $values = [];
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            if ($pair === '') {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, null);
            $keys[] = self::keep(urldecode($key));
            $values[] = $value === null ? null : self::keepValue(urldecode($value));
        }

        $digits = 0;
        foreach ([...$segments, ...$keys, ...$values] as $kept) {
            $digits += self::digitCount((string) $kept);
        }
        if ($digits > self::MAX_DIGITS) {
            $segments = self::redactDigits($segments);
            $keys = self::redactDigits($keys);
            $values = self::redactDigits($values);
        }

        $out .= implode('/', $segments);
        $pairs = [];
        foreach ($keys as $i => $key) {
            $pairs[] = $values[$i] === null ? $key : $key . '=' . $values[$i];
        }
        if ($pairs !== []) {
            $out .= '?' . implode('&', $pairs);
        }

        return self::cap($out, strlen($scheme . '://' . $host));
    }

    /**
     * Every part with a digit in it redacted, for when the parts together hold too many digits.
     *
     * @param array<int, ?string> $parts
     * @return array<int, ?string>
     */
    private static function redactDigits(array $parts): array
    {
        return array_map(
            static fn (?string $part): ?string => $part !== null && self::digitCount($part) > 0 ? self::REDACTED : $part,
            $parts
        );
    }

    /** $url cut to the length cap at the last '/', '?' or '&' past the host, with the cut part marked redacted. */
    private static function cap(string $url, int $originLength): string
    {
        if (strlen($url) <= self::MAX_URL_LENGTH) {
            return $url;
        }
        $head = substr($url, 0, self::MAX_URL_LENGTH - strlen(self::REDACTED));
        $cut = max((int) strrpos($head, '/'), (int) strrpos($head, '?'), (int) strrpos($head, '&'));
        if ($cut < $originLength) {
            return substr($url, 0, $originLength);
        }

        return substr($head, 0, $cut + 1) . self::REDACTED;
    }

    /** $text when it is a macro whose name passes the same rules as a path part. */
    private static function isMacro(string $text): bool
    {
        if (preg_match(self::MACRO, $text, $m) !== 1) {
            return false;
        }
        $name = $m[1] !== '' ? $m[1] : ($m[2] ?? '') . ($m[3] ?? '');
        $words = preg_split('/[.:]/', $name) ?: [];
        if (count($words) > 1 && !in_array(strtolower($words[0]), self::MACRO_NAMESPACES, true)) {
            return false;
        }
        foreach ($words as $word) {
            if ($word === '' || !self::readsAsWords($word)) {
                return false;
            }
        }

        return self::digitCount($name) <= self::MAX_DIGITS;
    }

    private static function digitCount(string $text): int
    {
        return preg_match_all('/[0-9]/', $text);
    }

    /** A query value: kept only when it is a macro or a short number. */
    private static function keepValue(string $text): string
    {
        return self::isMacro($text) || preg_match(self::SHORT_NUMBER, $text) === 1 ? $text : self::REDACTED;
    }

    /** $text when it is a macro or reads as words with no token or long number in it, else the redaction mark. */
    private static function keep(string $text): string
    {
        if (self::isMacro($text)) {
            return $text;
        }

        return self::readsAsWords($text) ? $text : self::REDACTED;
    }

    /** Short words joined by '-' or '_' with no token in them and at most six digits in all. */
    private static function readsAsWords(string $text): bool
    {
        if (strlen($text) > self::MAX_LENGTH
            || preg_match(self::WORDS, $text) !== 1
            || self::digitCount($text) > self::MAX_DIGITS
            || preg_match(self::HEX_TOKEN, $text) === 1) {
            return false;
        }
        $stem = preg_replace('/\\.[A-Za-z]+$/D', '', $text) ?? '';
        foreach (preg_split('/[-_]/', $stem) ?: [] as $word) {
            if (preg_match(self::WORD, $word) !== 1) {
                return false;
            }
        }

        return true;
    }
}
