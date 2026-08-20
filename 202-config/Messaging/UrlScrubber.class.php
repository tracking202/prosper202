<?php
// 202-config/Messaging/UrlScrubber.class.php
// Cheap belt-and-suspenders: offer URLs are templates and rarely hold PII,
// but redact any hardcoded email/phone param VALUES before sync just in case.

final class UrlScrubber
{
    private const EMAIL = '/^[^@\s]+@[^@\s]+\.[^@\s]+$/';
    private const PHONE = '/^\+?[0-9][0-9\-\.\s\(\)]{6,}$/';

    public static function scrub(string $url): string
    {
        $q = parse_url($url, PHP_URL_QUERY);
        if ($q === null || $q === false || $q === '') {
            return $url;
        }
        parse_str($q, $params);
        $changed = false;
        foreach ($params as $k => $v) {
            if (!is_string($v)) { continue; }
            // parse_str already urldecodes ('+' becomes a space); trim so a
            // leading space from an encoded '+' doesn't defeat the anchors.
            $decoded = trim(rawurldecode($v));
            if (preg_match(self::EMAIL, $decoded) || preg_match(self::PHONE, $decoded)) {
                $params[$k] = '[redacted]';
                $changed = true;
            }
        }
        if (!$changed) { return $url; }
        // http_build_query urlencodes {macros}; only touch params if we actually redacted,
        // and rebuild only the query portion to preserve the rest of the URL.
        $rebuilt = http_build_query($params);
        $base = strtok($url, '?');
        $frag = parse_url($url, PHP_URL_FRAGMENT);
        return $base . '?' . $rebuilt . ($frag !== null && $frag !== false ? '#' . $frag : '');
    }
}
