<?php
// 202-config/Messaging/UrlScrubber.class.php
// Offer URLs are templates and rarely hold PII, but any email/phone query
// value is redacted before sync. A URL that cannot be parsed is never sent.

final class UrlScrubber
{
    private const EMAIL = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';
    private const PHONE = '/^\+?[0-9][0-9\-\.\s\(\)]{6,}$/';

    public static function scrub(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host']) || $parts['host'] === '') {
            // Unparseable or hostless: nothing of it is sent.
            return '';
        }
        $q = $parts['query'] ?? '';
        if ($q === '') {
            return $url;
        }
        parse_str($q, $params);
        $changed = false;
        $params = self::redact($params, $changed);
        if (!$changed) { return $url; }
        // http_build_query urlencodes {macros}; only rebuild when something was
        // redacted, and only the query portion.
        $base = strtok($url, '?');
        $frag = $parts['fragment'] ?? null;
        return $base . '?' . http_build_query($params) . ($frag !== null ? '#' . $frag : '');
    }

    /**
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    private static function redact(array $params, bool &$changed): array
    {
        foreach ($params as $k => $v) {
            if (is_array($v)) {
                $params[$k] = self::redact($v, $changed);
                continue;
            }
            if (!is_string($v)) { continue; }
            // parse_str already urldecodes ('+' becomes a space); trim so a
            // leading space from an encoded '+' doesn't defeat the anchors.
            $decoded = trim(rawurldecode($v));
            if (preg_match(self::EMAIL, $decoded) || preg_match(self::PHONE, $decoded)) {
                $params[$k] = '[redacted]';
                $changed = true;
            }
        }
        return $params;
    }
}
