<?php

declare(strict_types=1);

namespace Prosper202\Http;

/**
 * Whether an address is one of Cloudflare's edge servers, whose
 * CF-Connecting-IP header names the visitor. A static list: nothing is
 * fetched at runtime.
 */
final class CloudflareRanges
{
    // From https://www.cloudflare.com/ips-v4 and https://www.cloudflare.com/ips-v6, fetched 2026-11-03.
    public const CIDRS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    private function __construct()
    {
    }

    public static function contains(string $ip): bool
    {
        foreach (self::CIDRS as $cidr) {
            if (self::inCidr($ip, $cidr)) {
                return true;
            }
        }

        return false;
    }

    /** Whether $ip lies in $cidr, compared as packed bytes of the same family. */
    public static function inCidr(string $ip, string $cidr): bool
    {
        [$network, $bits] = explode('/', $cidr, 2);
        $address = filter_var($ip, FILTER_VALIDATE_IP) === false ? false : inet_pton($ip);
        $base = inet_pton($network);
        $bits = (int) $bits;
        if ($address === false || $base === false || strlen($address) !== strlen($base)
            || $bits < 0 || $bits > strlen($base) * 8) {
            return false;
        }
        $whole = intdiv($bits, 8);
        if (substr($address, 0, $whole) !== substr($base, 0, $whole)) {
            return false;
        }
        $rest = $bits % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($address[$whole]) & $mask) === (ord($base[$whole]) & $mask);
    }
}
