<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Tests\Support\AssembledStrings;
use Tests\Support\SourceScan;

/**
 * An IPv6 address is a 202_ips row whose ip_address holds the id of its
 * 202_ips_v6 row. Joined as `202_ips_v6.ip_id = 202_ips.ip_address`, a
 * number compared with text, the server decides whether the IPv4 address
 * 2.0.1.1 is IPv6 row 2 (`SELECT 2 = '2.0.1.1'` is 1), so an IPv4 visitor
 * was read as another visitor's IPv6 address: by the click path's lookup,
 * then by Analyze › IPs and the IP level of the group report after the
 * lookup was fixed.
 *
 * Every SQL string in the served tree that joins 202_ips_v6 compares the
 * reference in one of the two spellings that leave nothing to the server:
 * an all-digit ip_address cast to a number (IpAddressSql::join(), the API's
 * readers), or the v6 id cast to text (the lookups). Read from the strings
 * as PHP assembles them, one guard per join, so a second join in a string
 * that has one guarded fails. Wherever it is written (an ON, a WHERE, an
 * EXISTS or IN subquery), an ip_id compared bare with an ip_address, in
 * either order, is refused, in any string of the tree. A comma join, USING
 * and NATURAL JOIN of the table are refused by name: their comparison is
 * not read.
 *
 * What this cannot see: a join or comparison assembled from parts in
 * separate strings, and a comparison where either column sits inside an
 * expression other than the two casts (`v.ip_id + 0 = i.ip_address`).
 */
final class Ipv6RowJoinIsGuardedTest extends TestCase
{
    /** A join of the v6 table, or the v6 table first and 202_ips joined to it. */
    private const JOIN = '/\bJOIN\s+`?202_ips_v6`?\b'
        . '|\bFROM\s+`?202_ips_v6`?\s+(?:AS\s+)?\w*\s*(?:INNER\s+|LEFT\s+(?:OUTER\s+)?)?JOIN\s+`?202_ips`?(?!\w)/i';

    private const COMMA_JOIN = '/,\s*`?202_ips_v6`?\b|\b202_ips_v6`?\s+(?:AS\s+)?\w*\s*,/i';

    private const UNREAD_JOIN = '/\bUSING\s*\(|\bNATURAL\s+(?:(?:LEFT|RIGHT)\s+(?:OUTER\s+)?)?JOIN\b/i';

    /** An ip_id and an ip_address compared as they are, in either order, or one IN a subquery of the other. */
    private const BARE = '/\bip_id`?\s*(?:=|<=>|!=|<>)\s*(?:`?\w+`?\.)?`?ip_address\b'
        . '|\bip_address`?\s*(?:=|<=>|!=|<>)\s*(?:`?\w+`?\.)?`?ip_id\b'
        . '|\bip_(?:id|address)`?\s+(?:NOT\s+)?IN\s*\(\s*SELECT\s+(?:DISTINCT\s+)?'
        . '(?:`?\w+`?\.)?`?ip_(?:id|address)\b/i';

    private const AS_NUMBER = '/(\w+)\.ip_address\s+REGEXP\s+\'\^\[0-9\]\+\\\\?\$\'\s+AND'
        . '\s+\w+\.ip_id\s*=\s*CAST\(\1\.ip_address\s+AS\s+UNSIGNED\)/i';

    private const AS_TEXT = '/\.ip_address\s*=\s*CAST\(\s*`?202_ips_v6`?\.ip_id'
        . '\s+AS\s+CHAR\s+CHARACTER\s+SET\s+utf8mb4\)/i';

    /** Sites known to join the table; the scan has to find at least these. */
    private const FLOOR = [
        '202-config/DataEngine/IpAddressSql.php' => 1,
        '202-config/Click/StoredAddressIds.php' => 1,
        '202-config/Repository/Mysql/MysqlLocationRepository.php' => 1,
        'api/v3/Controllers/ClicksController.php' => 1,
        'api/v3/Controllers/ReportsController.php' => 1,
        'api/v3/Support/ReportFilter.php' => 1,
    ];

    public function testEveryJoinOfTheV6TableComparesTheReferenceSafely(): void
    {
        $found = [];
        $failures = [];
        foreach (SourceScan::phpFiles() as $file => $source) {
            if (stripos($source, 'ip_address') === false && stripos($source, '202_ips_v6') === false) {
                continue;
            }
            foreach (AssembledStrings::of($source) as ['line' => $line, 'text' => $text]) {
                $problem = self::problem($text);
                $joins = preg_match_all(self::JOIN, self::readable($text));
                if ($joins > 0) {
                    $found[$file] = ($found[$file] ?? 0) + $joins;
                }
                if ($problem !== null) {
                    $failures[] = "$file:$line $problem";
                }
            }
        }

        self::assertSame([], $failures, "A join of 202_ips_v6 that leaves the comparison to the server.\n"
            . 'Join it through IpAddressSql::join(), or compare as the lookups do:'
            . " 202_ips.ip_address = CAST(202_ips_v6.ip_id AS CHAR CHARACTER SET utf8mb4).\n"
            . implode("\n", $failures));
        foreach (self::FLOOR as $file => $least) {
            self::assertGreaterThanOrEqual($least, $found[$file] ?? 0, "the scan found no join of 202_ips_v6 in $file");
        }
    }

    /** @return array<string, array{string, ?string}> */
    public static function shapes(): array
    {
        $number = "i.ip_address REGEXP '^[0-9]+\$' AND i6.ip_id = CAST(i.ip_address AS UNSIGNED)";

        return [
            'the old report join' => [
                ' LEFT JOIN 202_ips_v6 AS 2i6 ON (2i6.ip_id = 2i.ip_address COLLATE utf8mb4_general_ci) ',
                'compared bare',
            ],
            'the old lookup' => [
                'SELECT 202_ips.ip_id FROM 202_ips_v6 INNER JOIN 202_ips ON (202_ips_v6.ip_id = 202_ips.ip_address)',
                'compared bare',
            ],
            'an outer join written out' => [
                'FROM 202_ips i LEFT OUTER JOIN 202_ips_v6 i6 ON (i6.ip_id = i.ip_address)',
                'compared bare',
            ],
            'backquoted' => ['FROM 202_ips i JOIN `202_ips_v6` i6 ON (i6.ip_id = i.ip_address)', 'compared bare'],
            'a cast with no all-digit test' => [
                'FROM 202_ips i JOIN `202_ips_v6` i6 ON (i6.ip_id = CAST(i.ip_address AS UNSIGNED))',
                'unguarded',
            ],
            'a comma join' => [
                'FROM 202_ips i, 202_ips_v6 i6 WHERE i6.ip_id = CAST(i.ip_address AS UNSIGNED)',
                'comma',
            ],
            'the v6 table first in a comma join' => [
                'FROM 202_ips_v6 i6, 202_ips i WHERE i6.ip_id = CAST(i.ip_address AS UNSIGNED)',
                'comma',
            ],
            'a second join beside a guarded one' => [
                "FROM 202_ips i JOIN 202_ips_v6 i6 ON ($number) JOIN 202_ips_v6 j6 ON (j6.ip_address = ?)",
                'unguarded',
            ],
            'a guard on another alias' => [
                "FROM 202_ips i JOIN 202_ips_v6 i6 ON (k.ip_address REGEXP '^[0-9]+\$'"
                    . ' AND i6.ip_id = CAST(i.ip_address AS UNSIGNED))',
                'unguarded',
            ],
            'IpAddressSql::join()' => [
                " LEFT JOIN 202_ips_v6 AS \x00 ON (\x00.ip_address REGEXP '^[0-9]+\\\$'"
                    . " AND \x00.ip_id = CAST(\x00.ip_address AS UNSIGNED)) ",
                null,
            ],
            'the API\'s join' => ["INNER JOIN 202_ips_v6 i6 ON ($number)", null],
            'the lookups' => [
                'SELECT 202_ips.ip_id FROM 202_ips_v6 INNER JOIN 202_ips ON (202_ips.ip_address'
                    . ' = CAST(202_ips_v6.ip_id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_general_ci)',
                null,
            ],
            'an EXISTS subquery' => [
                'WHERE EXISTS (SELECT 1 FROM 202_ips_v6 v WHERE v.ip_id = i.ip_address)',
                'compared bare',
            ],
            'the address first' => ['FROM 202_ips_v6 v WHERE i.ip_address = v.ip_id', 'compared bare'],
            'backquoted columns' => ['WHERE `v`.`ip_id` = `i`.`ip_address`', 'compared bare'],
            'an IN subquery' => ['WHERE i.ip_address IN (SELECT ip_id FROM 202_ips_v6)', 'compared bare'],
            'a NOT IN subquery' => [
                'WHERE i.ip_address NOT IN (SELECT DISTINCT v.ip_id FROM 202_ips_v6 v)',
                'compared bare',
            ],
            'a comparison in a string that names no v6 table' => [
                'SELECT 1 FROM 202_ips i JOIN ' . "\x00" . ' v ON (v.ip_id <=> i.ip_address)',
                'compared bare',
            ],
            'USING' => ['FROM 202_ips i JOIN 202_ips_v6 USING (ip_id)', 'unread'],
            'NATURAL JOIN' => ['FROM 202_ips i NATURAL LEFT JOIN 202_ips_v6', 'unread'],
            'the API\'s filter' => [
                ' IN (SELECT i.ip_id FROM 202_ips i INNER JOIN 202_ips_v6 i6 ON ('
                    . "i.ip_address REGEXP '^[0-9]+\$' AND i6.ip_id = CAST(i.ip_address AS UNSIGNED))"
                    . ' WHERE i6.ip_address = ?)',
                null,
            ],
            'ids compared with ids' => ['FROM 202_clicks_advance ca JOIN 202_ips ip ON ca.ip_id = ip.ip_id', null],
            'an insert' => ['INSERT INTO 202_ips_v6 SET ip_address = ?, location_id = 0', null],
            'a table name' => ['202_ips_v6', null],
        ];
    }

    /** @dataProvider shapes */
    public function testTheScanReadsEachShape(string $sql, ?string $expected): void
    {
        $problem = self::problem($sql);
        if ($expected === null) {
            self::assertNull($problem, $sql);
        } else {
            self::assertNotNull($problem, $sql);
            self::assertStringStartsWith($expected, $problem);
        }
    }

    private static function problem(string $text): ?string
    {
        $text = self::readable($text);
        if (preg_match(self::BARE, $text, $bare) === 1) {
            return 'compared bare: `' . $bare[0] . '` leaves the comparison of a v6 id with an address to the server';
        }
        if (stripos($text, '202_ips_v6') === false) {
            return null;
        }
        if (preg_match(self::COMMA_JOIN, $text) === 1) {
            return 'comma join of 202_ips_v6: its comparison is not read; write it as a JOIN … ON';
        }
        if (preg_match(self::UNREAD_JOIN, $text, $unread) === 1) {
            return 'unread: ' . $unread[0]
                . ' beside 202_ips_v6 compares columns this does not read; write a JOIN … ON';
        }
        $joins = preg_match_all(self::JOIN, $text);
        $guards = preg_match_all(self::AS_NUMBER, $text) + preg_match_all(self::AS_TEXT, $text);
        if ($joins > $guards) {
            return sprintf('unguarded: %d join(s) of 202_ips_v6, %d guarded', $joins, $guards);
        }

        return null;
    }

    /** An unread part (an alias passed in) reads as one alias, the same at each place. */
    private static function readable(string $text): string
    {
        return str_replace(AssembledStrings::UNREAD, 'unread_alias', $text);
    }
}
