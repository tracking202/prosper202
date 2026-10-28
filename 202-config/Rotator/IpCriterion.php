<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * A redirector rule's "IP address" criterion: a comma-separated list of
 * addresses, matched against the visitor's address (VisitorIp, already in
 * canonical form).
 *
 * The redirects compared the stored text with in_array(), so the same
 * address written another way never matched: `2001:DB8::77` (upper case),
 * `2001:0db8:0:0::77` (zeros written out), or the second address of
 * `203.0.113.50, 198.51.100.88` (the space after the comma stays on the
 * value). An address is one address however it is spelled (CLAUDE.md
 * error pattern #17, in reverse: here the comparison told apart two
 * spellings of one thing). Both sides now go through inet_pton/inet_ntop.
 *
 * Rules already stored are read the same way, so they match without being
 * re-saved. A value that is not an address never matches, as before; on
 * write the API and the Redirectors page refuse one (refusal() names it),
 * and store the canonical list (normalize()). A range (`203.0.113.0/24`) is not an
 * address and is refused too: the criterion has only ever compared single
 * addresses, and accepting one would store a rule that matches nobody.
 */
final class IpCriterion
{
    private function __construct()
    {
    }

    /**
     * Whether the visitor's address is one of the rule's.
     *
     * @param list<string> $values the rule's value split on ','
     */
    public static function contains(array $values, string $visitorIp): bool
    {
        $visitor = self::canonical($visitorIp);
        if ($visitor === null) {
            return false;
        }
        foreach ($values as $value) {
            if (self::canonical($value) === $visitor) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule value as it should be stored: each address in canonical form,
     * joined with ','; and every item that is not an address, as typed.
     *
     * @return array{value: string, invalid: list<string>}
     */
    public static function normalize(string $value): array
    {
        $addresses = [];
        $invalid = [];
        foreach (explode(',', $value) as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $canonical = self::canonical($item);
            if ($canonical === null) {
                $invalid[] = $item;
                continue;
            }
            $addresses[$canonical] = $canonical;
        }

        return ['value' => implode(',', $addresses), 'invalid' => $invalid];
    }

    /**
     * Why a typed value cannot be stored as an IP criterion, in words for
     * the person who typed it; null when it can (store normalize()'s value).
     * One wording for both writers, the API and the Redirectors page.
     */
    public static function refusal(string $value): ?string
    {
        $ips = self::normalize($value);
        if ($ips['invalid'] !== []) {
            return '"' . implode('", "', $ips['invalid']) . '" is not an IP address, so the rule would never match.'
                . ' List single addresses, comma-separated (ranges are not supported).';
        }

        return $ips['value'] === '' ? 'An IP address rule needs at least one address.' : null;
    }

    private static function canonical(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || filter_var($value, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($value);
        $text = $packed === false ? false : inet_ntop($packed);

        return $text === false ? null : $text;
    }
}
