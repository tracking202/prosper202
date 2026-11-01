<?php

declare(strict_types=1);

namespace Prosper202\Click\Filter;

/**
 * The click filter's built-in crawler ranges: the Google, Yahoo, Microsoft,
 * Level 3 and PSI blocks FILTER::checkNetrange() has always held, moved here
 * unchanged so the API's address test asks the same list. IPv4 only, as
 * before: ip2long() answers false for an IPv6 address, which no range holds.
 * The fifth Google range is written as a signed 32-bit number and so never
 * matches on a 64-bit PHP; it is the second range's twin (3512041472 is
 * -782925824 unsigned), so nothing is missed. Kept as it was.
 */
final class CrawlerRanges
{
    private function __construct()
    {
    }

    public static function contains(string $address): bool
    {
        $ip_address = ip2long($address);

        /* google1 */
        if (($ip_address >= 1208926208) and ($ip_address <= 1208942591)) {
            return true;
        }
        /* google2 */
        if (($ip_address >= 3512041472) and ($ip_address <= 3512074239)) {
            return true;
        }
        /* google3 */
        if (($ip_address >= 1123631104) and ($ip_address <= 1123639295)) {
            return true;
        }
        /* Google4 */
        if (($ip_address >= 1089052672) and ($ip_address <= 1089060863)) {
            return true;
        }
        /* google5 */
        if (($ip_address >= -782925824) and ($ip_address <= -782893057)) {
            return true;
        }

        /* level 3 communications */
        if (($ip_address >= 1094189056) and ($ip_address <= 1094451199)) {
            return true;
        }

        /* yahoo1 */
        if (($ip_address >= 3515031552) and ($ip_address <= 3515039743)) {
            return true;
        }
        /* Yahoo2 */
        if (($ip_address >= 3633393664) and ($ip_address <= 3633397759)) {
            return true;
        }
        /* Yahoo3 */
        if (($ip_address >= 3640418304) and ($ip_address <= 3640426495)) {
            return true;
        }
        /* Yahoo4 */
        if (($ip_address >= 1209925632) and ($ip_address <= 1209991167)) {
            return true;
        }
        /* Yahoo5 */
        if (($ip_address >= 1241907200) and ($ip_address <= 1241972735)) {
            return true;
        }

        /* Performance Systems International Inc. */
        if (($ip_address >= 637534208) and ($ip_address <= 654311423)) {
            return true;
        }
        /* Microsoft */
        if (($ip_address >= 3475898368) and ($ip_address <= 3475963903)) {
            return true;
        }
        /* MSN */
        if (($ip_address >= 1093926912) and ($ip_address <= 1094189055)) {
            return true;
        }

        return false;
    }
}
