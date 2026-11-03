<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * Whether a redirector rule matches a visitor: one evaluation for rtr.php
 * and offrtr.php, which each carried a copy that read 8 of the 23 types the
 * paid 1.9.56 redirector did, so a stored rule of another type never
 * matched and its visitors went on to the next rule or the default.
 *
 * Semantics are 1.9.56's, with two of its slips read as meant: an `is` IP
 * range or referer list matches when any value does (1.9.56 needed every
 * range, and counted a referer list once per value), and `is_not` when none
 * does. A c1-c4 criterion needs the value on the link, whatever the
 * statement, as in 1.9.56; a missing keyword or utm value is ''. A fraud
 * or visitor criterion that cannot be decided (no IPQualityScore key or
 * answer, a failed read) matches neither way: 1.9.56 read an unknown score
 * as "not fraud", which sent every visitor down a "not fraud" rule.
 */
final class CriterionMatcher
{
    /** Every type a rule can test, as Setup › Redirectors labels them. */
    public const TYPES = [
        'country' => 'Country',
        'region' => 'State/Region',
        'eu' => 'In the European Union',
        'city' => 'City',
        'isp' => 'ISP/Carrier',
        'fraud' => 'High fraud score (IPQualityScore)',
        'ip' => 'IP address',
        'iprange' => 'IP range',
        'browser' => 'Browser',
        'platform' => 'OS',
        'device' => 'Device type',
        'visitor' => 'Visitor (filtered or unique)',
        'c1' => 'c1',
        'c2' => 'c2',
        'c3' => 'c3',
        'c4' => 'c4',
        't202kw' => 'Keyword (t202kw)',
        'referer' => 'Referer',
        'utm_source' => 'utm_source',
        'utm_medium' => 'utm_medium',
        'utm_campaign' => 'utm_campaign',
        'utm_term' => 'utm_term',
        'utm_content' => 'utm_content',
    ];

    private function __construct()
    {
    }

    /**
     * Whether every criterion of a rule matches; a rule with none matches.
     *
     * @param iterable<array<string, mixed>> $criteria rows of type, statement and value
     */
    public static function rule(iterable $criteria, VisitorFacts $visitor): bool
    {
        foreach ($criteria as $criterion) {
            if (!self::criterion(
                (string) ($criterion['type'] ?? ''),
                (string) ($criterion['statement'] ?? ''),
                isset($criterion['value']) ? (string) $criterion['value'] : null,
                $visitor
            )) {
                return false;
            }
        }

        return true;
    }

    /** Whether one criterion matches; a type this class does not know never does. */
    public static function criterion(string $type, string $statement, ?string $value, VisitorFacts $visitor): bool
    {
        $values = Criterion::values($value);
        if (Criterion::matchesEveryone($values)) {
            return true;
        }
        $is = $statement !== 'is_not';

        switch ($type) {
            case 'ip':
                return IpCriterion::contains($values, $visitor->ip) === $is;
            case 'iprange':
                $inAny = false;
                foreach ($values as $range) {
                    if (IpRange::contains($range, $visitor->ip)) {
                        $inAny = true;
                        break;
                    }
                }
                return $inAny === $is;
            case 'referer':
                $hit = false;
                foreach ($values as $pattern) {
                    if (self::refererMatches($pattern, $visitor->referer)) {
                        $hit = true;
                        break;
                    }
                }
                return $hit === $is;
            case 'eu':
                return self::listed(self::flag($visitor->geo['is_european_union'] ?? null), $values, true) === $is;
            case 'fraud':
                $fraud = $visitor->fraud();
                return $fraud !== null && self::listed($fraud ? 'true' : 'false', $values, true) === $is;
            case 'visitor':
                $filtered = $visitor->filtered();
                return $filtered !== null && self::listed($filtered ? 'filtered' : 'unique', $values, true) === $is;
            case 'c1':
            case 'c2':
            case 'c3':
            case 'c4':
                $param = $visitor->param($type);
                return $param !== '' && self::listed($param, $values) === $is;
            case 'utm_source':
            case 'utm_medium':
            case 'utm_campaign':
            case 'utm_term':
            case 'utm_content':
                return self::listed($visitor->param($type), $values) === $is;
            case 't202kw':
                return self::listed($visitor->keyword, $values) === $is;
        }

        $actual = self::visitorValue($type, $visitor);
        if ($actual === null) {
            // No ISP without MaxMind ISP: "is not" matches, as it did.
            return $type === 'isp' && !$is;
        }

        return self::listed($actual, $values) === $is;
    }

    /** The value a geo, ISP or user-agent criterion compares; null when there is none to compare. */
    private static function visitorValue(string $type, VisitorFacts $visitor): ?string
    {
        $code = (string) ($visitor->geo['country_code'] ?? '');

        return match ($type) {
            'country' => (string) ($visitor->geo['country'] ?? '') . '(' . $code . ')',
            'region' => (string) ($visitor->geo['region'] ?? '') . '(' . $code . ')',
            'city' => (string) ($visitor->geo['city'] ?? '') . '(' . $code . ')',
            'isp' => $visitor->isp,
            'platform' => $visitor->os,
            'browser' => $visitor->browser,
            'device' => strtolower($visitor->device),
            default => null,
        };
    }

    /** @param list<string> $values */
    private static function listed(string $actual, array $values, bool $anyCase = false): bool
    {
        foreach ($values as $value) {
            if ($anyCase ? strcasecmp($actual, $value) === 0 : $actual === $value) {
                return true;
            }
        }

        return false;
    }

    /** getGeoData()'s EU answer as the values store it: true, false or Unknown. */
    private static function flag(mixed $value): string
    {
        return match (true) {
            $value === true => 'true',
            $value === false => 'false',
            default => (string) ($value ?? 'Unknown'),
        };
    }

    /**
     * A referer value is a case-insensitive pattern found anywhere in the
     * referer, as 1.9.56 read it. One that is no valid pattern, or that runs
     * out of backtracking, is found as text: values are typed as URLs too.
     */
    private static function refererMatches(string $pattern, string $referer): bool
    {
        if (str_contains($pattern, "\x01")) {
            return stripos($referer, $pattern) !== false;
        }
        // A delimiter no typed pattern holds, so none needs escaping.
        $regex = "\x01(" . $pattern . ")\x01i";
        set_error_handler(static fn (): bool => true);
        try {
            $hit = preg_match($regex, $referer);
        } finally {
            restore_error_handler();
        }

        return $hit === false ? stripos($referer, $pattern) !== false : $hit === 1;
    }
}
