<?php

declare(strict_types=1);

namespace Prosper202\Click;

/**
 * The query a tracking link carries after `t202id=`: the traffic source's
 * custom variables and the built-in tokens, built the way Get Links builds
 * it (tracking202/ajax/generate_tracking_link.php).
 *
 * - each live custom variable of the link's traffic source is
 *   `parameter=placeholder`, unless its parameter is a built-in token, in
 *   which case its placeholder becomes that token's default;
 * - a value given for a built-in token replaces its default;
 * - a built-in token is written when it has a value, and `t202kw=` always.
 *
 * The API answered every link with a fixed
 * `t202kw={keyword}&c1={c1}&c2={c2}&c3={c3}&c4={c4}`: placeholders no traffic
 * source substitutes, and none of the source's own variables.
 */
final class TrackingLinkVariables
{
    /** The tokens the redirects read, in the order the page writes them. */
    public const BUILT_IN = [
        'c1', 'c2', 'c3', 'c4',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        't202ref', 't202b', 't202kw',
    ];

    private function __construct()
    {
    }

    /**
     * @param list<array{parameter: string, placeholder: string}> $customVariables
     *   the traffic source's live variables, in their stored order
     * @param array<string, string> $values caller-given values for built-in tokens
     * @return string '&…' — always at least '&t202kw='
     */
    public static function query(array $customVariables, array $values): string
    {
        $parts = [];
        $builtIn = [];
        foreach ($customVariables as $variable) {
            if (in_array($variable['parameter'], self::BUILT_IN, true)) {
                $builtIn[$variable['parameter']] = $variable['placeholder'];
            } else {
                $parts[] = $variable['parameter'] . '=' . $variable['placeholder'];
            }
        }
        foreach (self::BUILT_IN as $token) {
            $given = trim((string) ($values[$token] ?? ''));
            if ($given !== '') {
                $builtIn[$token] = $given;
            }
            if (isset($builtIn[$token]) || $token === 't202kw') {
                $parts[] = $token . '=' . ($builtIn[$token] ?? '');
            }
        }

        return '&' . implode('&', $parts);
    }

    /**
     * Why a value for a built-in token cannot go into a link, or null. The
     * value is written into the URL as given — a traffic source's macro such
     * as {keyword} or [kw] must reach it unencoded — so a character that
     * would end the parameter or the URL is refused rather than escaped.
     */
    public static function problem(string $value): ?string
    {
        if (preg_match('/[&#?\s\x00-\x1f\x7f]/', $value) === 1) {
            return 'Must not contain &, #, ?, spaces or control characters: it is written into the link as given';
        }
        if (strlen($value) > 255) {
            return 'At most 255 characters';
        }

        return null;
    }
}
