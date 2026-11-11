<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * How a redirector rule's criterion value is read: one rule for every
 * reader (rtr.php, offrtr.php, the Redirectors page, Overview › Rotator
 * Breakdown, `p202 rotator test` and `p202 diff`, whose Go twins hold to
 * tests/fixtures/rotator-criterion-values.json) and every writer.
 *
 * The value is a comma-separated list, and one value means every visitor:
 * ALL. rtr.php read `ALL` and `all` as every visitor, offrtr.php only
 * `ALL`, so a rule stored as `all` (the API stored values as sent before
 * 1.9.79, and the page stores what is typed) sent the same visitor to the
 * rule from a redirector link and to the default from a landing page's
 * offer link. Neither read a value with a space beside it (`United
 * States(US), all`, which the API also stored as sent): every value is
 * now trimmed, and ALL is ALL in any case. Rows already stored are read
 * this way; the writers store ALL (CLAUDE.md #25).
 *
 * ALL matches every visitor whatever the statement: `is not ALL` did on
 * both scripts, and still does.
 */
final class Criterion
{
    /** The value that matches every visitor, as the writers store it. */
    public const EVERYONE = 'ALL';

    private function __construct()
    {
    }

    /**
     * A stored value as the redirects compare it: split on commas, each
     * value trimmed, the empty ones dropped.
     *
     * @return list<string>
     */
    public static function values(?string $stored): array
    {
        $values = [];
        foreach (explode(',', (string) $stored) as $value) {
            $value = trim($value);
            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * The visitor's ISP as an ISP criterion compares it: the part of
     * getIspData()'s answer before its first comma, trimmed. A stored value
     * is split on commas (values()), so it can't hold "Comcast Cable
     * Communications, LLC" whole; offrtr.php compared the whole answer and
     * so never matched such an ISP, while rtr.php compared the first part.
     */
    public static function visitorIsp(string $isp): string
    {
        return trim(explode(',', $isp, 2)[0]);
    }

    /** Whether one value is the one that matches every visitor. */
    public static function isEveryone(string $value): bool
    {
        return strcasecmp(trim($value), self::EVERYONE) === 0;
    }

    /**
     * Whether a criterion with these values matches every visitor.
     *
     * @param list<string> $values values() of the stored value
     */
    public static function matchesEveryone(array $values): bool
    {
        foreach ($values as $value) {
            if (self::isEveryone($value)) {
                return true;
            }
        }

        return false;
    }

    /** A value as the writers store it: values(), joined with ',', every visitor as ALL. */
    public static function normalize(string $value): string
    {
        return implode(',', array_map(
            static fn (string $v): string => self::isEveryone($v) ? self::EVERYONE : $v,
            self::values($value)
        ));
    }

    /** The values a criterion of a fixed set takes, compared in any case (CriterionMatcher). */
    public const FIXED_VALUES = [
        'eu' => ['true', 'false', 'Unknown'],
        'fraud' => ['true', 'false'],
        'visitor' => ['filtered', 'unique'],
    ];

    /**
     * Why a typed value cannot be stored as a criterion of this type, in
     * words for the person who typed it, or null. An IP address or range has
     * a form of its own, and eu, fraud and visitor a fixed set of values; a
     * value holding ALL is held to none: it matches every visitor whatever
     * the type, and the IP check refused it as no address.
     */
    public static function refusal(string $type, string $value): ?string
    {
        $values = self::values($value);
        if (self::matchesEveryone($values)) {
            return null;
        }
        if ($type === 'ip') {
            return IpCriterion::refusal($value);
        }
        if ($type === 'iprange') {
            return $values === [] ? 'An IP range rule needs at least one range.' : IpRange::refusal($value);
        }
        $allowed = self::FIXED_VALUES[$type] ?? null;
        if ($allowed === null) {
            return null;
        }
        $unknown = array_values(array_filter(
            $values,
            static fn (string $v): bool => !in_array(strtolower($v), array_map('strtolower', $allowed), true)
        ));

        return $unknown === [] ? null
            : '"' . implode('", "', $unknown) . '" never matches a ' . $type . ' rule: use ' . implode(' or ', $allowed) . '.';
    }

    /**
     * A value as the writers store it for its type, the one normalizer of
     * every writer: an IP list as canonical addresses, a country as
     * Name(CC) whether typed as a code ("us"), a name or Name(CC), a device
     * in lower case. A value holding ALL, and a country this class cannot
     * name, are kept as normalize() leaves them.
     */
    public static function stored(string $type, string $value): string
    {
        $values = self::values($value);
        if (self::matchesEveryone($values)) {
            return self::normalize($value);
        }
        if ($type === 'ip') {
            return IpCriterion::normalize($value)['value'];
        }
        if ($type === 'country') {
            $values = array_map(self::storedCountry(...), $values);
        } elseif ($type === 'device') {
            $values = array_map(strtolower(...), $values);
        }

        return implode(',', array_values(array_unique($values)));
    }

    /** One country value as stored: Name(CC) when it names a country without its code in brackets. */
    private static function storedCountry(string $value): string
    {
        if (preg_match('/\(([A-Za-z]{2})\)$/D', $value) === 1) {
            return $value;
        }
        $code = CountryNames::code($value);

        return $code === null ? $value : CountryNames::value($code);
    }

    /**
     * The country a country value names, as its upper-case code: the code
     * in the brackets that end it, or the value itself when it is two
     * letters (rows stored before the writers normalized them). Null when
     * it names none that way.
     */
    public static function countryCode(string $value): ?string
    {
        if (preg_match('/^([A-Za-z]{2})$|\(([A-Za-z]{2})\)$/D', $value, $m) === 1) {
            return strtoupper($m[2] ?? $m[1]);
        }

        return null;
    }

    /**
     * What storing a typed value changed, for the person who typed it:
     * each value stored() rewrote, as [typed, stored]. Trimming, dropped
     * empties and the case of ALL are not listed.
     *
     * @return list<array{string, string}>
     */
    public static function rewritten(string $type, string $value): array
    {
        $values = self::values($value);
        if (self::matchesEveryone($values) || ($type !== 'country' && $type !== 'device')) {
            return [];
        }
        $changes = [];
        foreach ($values as $typed) {
            $stored = self::stored($type, $typed);
            if ($stored !== $typed) {
                $changes[] = [$typed, $stored];
            }
        }

        return $changes;
    }

    /**
     * A criterion in words, as the Redirectors page and the Rotator
     * Breakdown list it: "any visitor" when it matches every visitor,
     * else its type, statement and values.
     *
     * @param array<string, string> $types a label for each type
     */
    public static function describe(string $type, string $statement, ?string $value, array $types = []): string
    {
        $values = self::values($value);
        if (self::matchesEveryone($values)) {
            return 'any visitor';
        }

        $verb = $statement === 'is_not' ? 'is not' : 'is';

        return ($types[$type] ?? $type) . ' ' . $verb . ' ' . implode(', ', $values);
    }
}
