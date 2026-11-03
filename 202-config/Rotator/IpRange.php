<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

/**
 * One value of a redirector rule's "IP range" criterion, in the forms the
 * paid 1.9.56 redirector read (ip_in_range()): a CIDR block (`203.0.113.0/24`,
 * or `10.1/16` with its missing octets 0), an address and netmask
 * (`203.0.113.0/255.255.255.0`), a wildcard (`203.0.113.*`) and a span
 * (`203.0.113.10-203.0.113.20`). IPv6 blocks and spans are read too, and a
 * single address is a range of one, which 1.9.56 never matched.
 */
final class IpRange
{
    private function __construct()
    {
    }

    /** Whether $range names a range this class can read. */
    public static function isRange(string $range): bool
    {
        return self::parse($range) !== null;
    }

    /** Whether the visitor's address falls in $range; false when either cannot be read. */
    public static function contains(string $range, string $visitorIp): bool
    {
        $parsed = self::parse($range);
        $ip = self::packed($visitorIp);
        if ($parsed === null || $ip === null || strlen($ip) !== strlen($parsed[1])) {
            return false;
        }
        [$kind, $a, $b] = $parsed;
        if ($kind === 'mask') {
            return ($ip & $b) === ($a & $b);
        }

        return strcmp($ip, $a) >= 0 && strcmp($ip, $b) <= 0;
    }

    /**
     * Why a typed value cannot be stored as an IP range criterion, or null.
     */
    public static function refusal(string $value): ?string
    {
        $invalid = [];
        foreach (Criterion::values($value) as $range) {
            if (!self::isRange($range)) {
                $invalid[] = $range;
            }
        }
        if ($invalid === []) {
            return null;
        }

        return '"' . implode('", "', $invalid) . '" is not an IP range, so the rule would never match.'
            . ' Write ranges as 203.0.113.0/24, 203.0.113.0/255.255.255.0, 203.0.113.* or 203.0.113.10-203.0.113.20.';
    }

    /**
     * A range as ['mask', network, mask] or ['span', low, high], in packed form.
     *
     * @return array{'mask'|'span', string, string}|null
     */
    private static function parse(string $range): ?array
    {
        $range = trim($range);
        if ($range === '') {
            return null;
        }
        if (str_contains($range, '/')) {
            [$address, $mask] = explode('/', $range, 2);
            $address = trim($address);
            $mask = trim($mask);
            if (str_contains($mask, '.')) {
                $network = self::packed($address);
                $netmask = self::packed(str_replace('*', '0', $mask));
                if ($network === null || $netmask === null || strlen($network) !== 4 || strlen($netmask) !== 4) {
                    return null;
                }

                return ['mask', $network, $netmask];
            }
            if (preg_match('/^\d{1,3}$/D', $mask) !== 1) {
                return null;
            }
            $network = str_contains($address, ':') ? self::packed($address) : self::packed(self::padded($address));
            if ($network === null || (int) $mask > strlen($network) * 8) {
                return null;
            }

            return ['mask', $network, self::prefixMask((int) $mask, strlen($network))];
        }
        if (str_contains($range, '*')) {
            $low = self::packed(str_replace('*', '0', $range));
            $high = self::packed(str_replace('*', '255', $range));

            return $low === null || $high === null || strlen($low) !== 4 ? null : ['span', $low, $high];
        }
        if (str_contains($range, '-')) {
            [$from, $to] = explode('-', $range, 2);
            $low = self::packed($from);
            $high = self::packed($to);
            if ($low === null || $high === null || strlen($low) !== strlen($high) || strcmp($low, $high) > 0) {
                return null;
            }

            return ['span', $low, $high];
        }
        $single = self::packed($range);

        return $single === null ? null : ['span', $single, $single];
    }

    /** A partial IPv4 network (`10.1`) with its missing octets 0, as 1.9.56 read it. */
    private static function padded(string $address): string
    {
        $octets = explode('.', $address);
        if (count($octets) > 4) {
            return $address;
        }
        while (count($octets) < 4) {
            $octets[] = '0';
        }

        return implode('.', array_map(static fn (string $o): string => $o === '' ? '0' : $o, $octets));
    }

    private static function prefixMask(int $bits, int $bytes): string
    {
        $mask = '';
        for ($i = 0; $i < $bytes; $i++) {
            $take = max(0, min(8, $bits - $i * 8));
            $mask .= chr($take === 0 ? 0 : (0xFF << (8 - $take)) & 0xFF);
        }

        return $mask;
    }

    private static function packed(string $address): ?string
    {
        $address = trim($address);
        if ($address === '' || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($address);

        return $packed === false ? null : $packed;
    }
}
