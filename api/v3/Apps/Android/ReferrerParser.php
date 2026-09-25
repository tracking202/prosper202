<?php

declare(strict_types=1);

namespace Api\V3\Apps\Android;

/**
 * Reads Google Play's install referrer: the value of the store link's
 * `referrer=` parameter, which Play hands the app decoded once, e.g.
 * `p202=123.AbC…&utm_source=news`.
 *
 * The string is a query string written by whoever built the link, so the
 * parser never rejects it (plan §5.4: truncated with a flag, never
 * rejected). What it decides is the class the referrer belongs to:
 *
 *   - `p202` present  → ours: the install token (one value; two p202
 *                       parameters are ambiguous and read as a bad token);
 *   - empty, or Play's organic marker (utm_source=google-play,
 *     utm_medium=organic) → organic;
 *   - anything else   → third party: a gclid, Meta's encrypted envelope in
 *                       utm_content, or another tracker's utm_* — its
 *                       fields are kept for the report.
 *
 * Parsed values are capped at the column width; the raw referrer is kept to
 * RAW_LIMIT bytes with a truncation flag.
 */
final class ReferrerParser
{
    public const RAW_LIMIT = 2048;
    public const FIELD_LIMIT = 255;
    public const UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    private function __construct()
    {
    }

    /**
     * @return array{
     *   class: 'ours'|'organic'|'third_party',
     *   tokens: list<string>,
     *   fields: array<string, string|null>,
     *   raw: string,
     *   truncated: bool,
     *   meta_envelope: bool
     * }
     */
    public static function parse(string $referrer): array
    {
        $fields = array_fill_keys([...self::UTM, 'gclid'], null);
        $tokens = [];
        $pairs = trim($referrer) === '' ? [] : explode('&', $referrer);
        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }
            $eq = strpos($pair, '=');
            $name = urldecode($eq === false ? $pair : substr($pair, 0, $eq));
            $value = urldecode($eq === false ? '' : substr($pair, $eq + 1));
            if ($name === 'p202') {
                $tokens[] = $value;
                continue;
            }
            if (array_key_exists($name, $fields) && $fields[$name] === null && $value !== '') {
                $fields[$name] = self::cap($value);
            }
        }

        $meta = $fields['utm_content'] !== null && self::isMetaEnvelope($fields['utm_content']);
        if ($tokens !== []) {
            $class = 'ours';
        } elseif (self::isOrganic($pairs, $fields)) {
            $class = 'organic';
        } else {
            $class = 'third_party';
        }

        $raw = $referrer;
        $truncated = strlen($raw) > self::RAW_LIMIT;
        if ($truncated) {
            $raw = mb_strcut($raw, 0, self::RAW_LIMIT, 'UTF-8');
        }

        return [
            'class' => $class,
            'tokens' => $tokens,
            'fields' => $fields,
            'raw' => $raw,
            'truncated' => $truncated,
            'meta_envelope' => $meta,
        ];
    }

    /**
     * No referrer at all, or exactly Play's organic marker (and nothing a
     * campaign would add to it).
     *
     * @param list<string> $pairs
     * @param array<string, string|null> $fields
     */
    private static function isOrganic(array $pairs, array $fields): bool
    {
        if ($pairs === [] || array_filter($pairs, static fn (string $p): bool => $p !== '') === []) {
            return true;
        }
        if ($fields['utm_source'] !== 'google-play' || $fields['utm_medium'] !== 'organic') {
            return false;
        }
        foreach ($fields as $name => $value) {
            if ($value !== null && $name !== 'utm_source' && $name !== 'utm_medium') {
                return false;
            }
        }

        return true;
    }

    /** Meta's install referrer: utm_content holding {"source": {"data": …, "nonce": …}}. */
    private static function isMetaEnvelope(string $content): bool
    {
        $decoded = json_decode($content, true);

        return is_array($decoded) && isset($decoded['source']) && is_array($decoded['source'])
            && isset($decoded['source']['data'], $decoded['source']['nonce']);
    }

    /**
     * A decoded value as a column can hold it: percent-decoding can produce
     * bytes that are not UTF-8 (`%FF`), which a utf8mb4 column refuses under
     * strict mode — failing the whole install, retry after retry. Invalid
     * sequences become "?", and the value is cut on a character boundary.
     */
    private static function cap(string $value): string
    {
        $value = mb_scrub($value, 'UTF-8');

        return strlen($value) > self::FIELD_LIMIT ? mb_strcut($value, 0, self::FIELD_LIMIT, 'UTF-8') : $value;
    }
}
