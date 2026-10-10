<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

/**
 * A click's IP address as text, over a 202_ips row.
 *
 * An IPv6 address is a 202_ips row whose ip_address holds the id of its
 * 202_ips_v6 row (MysqlLocationRepository::insertIp), the packed address
 * there. Read raw, the Visitors list showed an IPv6 visitor's address as
 * that id ("1"). Only an all-digit ip_address is such a reference, compared
 * as a number: a text comparison with the v6 id is left to the server, and
 * `2 = '2.0.1.1'` is true on MariaDB 10.11. A reference whose v6 row is gone
 * reads as no address rather than as its id. GET /reports/breakdown?
 * breakdown=ip reads the same way (ReportsController).
 */
final class IpAddressSql
{
    private function __construct()
    {
    }

    /** The LEFT JOIN of the v6 row, as $v6 , for a 202_ips row aliased $ips. */
    public static function join(string $ips, string $v6): string
    {
        self::alias($ips);
        self::alias($v6);

        return " LEFT JOIN 202_ips_v6 AS $v6 ON ($ips.ip_address REGEXP '^[0-9]+\$'"
            . " AND $v6.ip_id = CAST($ips.ip_address AS UNSIGNED)) ";
    }

    /** The address as text, over the aliases join() was given. */
    public static function address(string $ips, string $v6): string
    {
        self::alias($ips);
        self::alias($v6);

        return "CASE WHEN $ips.ip_address REGEXP '^[0-9]+\$' THEN INET6_NTOA($v6.ip_address) ELSE $ips.ip_address END";
    }

    private static function alias(string $alias): void
    {
        if (preg_match('/^[A-Za-z0-9_]+$/D', $alias) !== 1) {
            throw new \InvalidArgumentException("IpAddressSql: '$alias' is not a table alias");
        }
    }
}
