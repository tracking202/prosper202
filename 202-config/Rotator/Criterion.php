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

    /**
     * Why a typed value cannot be stored as a criterion of this type, in
     * words for the person who typed it, or null. Only an IP criterion has a
     * form of its own (IpCriterion::refusal()), and a value holding ALL is
     * held to none: it matches every visitor whatever the type, and the IP
     * check refused it as no address.
     */
    public static function refusal(string $type, string $value): ?string
    {
        if ($type !== 'ip' || self::matchesEveryone(self::values($value))) {
            return null;
        }

        return IpCriterion::refusal($value);
    }

    /** A value as the writers store it for its type: an IP list as canonical addresses, unless it holds ALL. */
    public static function stored(string $type, string $value): string
    {
        if ($type === 'ip' && !self::matchesEveryone(self::values($value))) {
            return IpCriterion::normalize($value)['value'];
        }

        return self::normalize($value);
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
