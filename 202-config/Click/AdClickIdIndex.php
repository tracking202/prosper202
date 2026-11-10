<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * Which clicks carry an ad click id (202_clicks_ad_ids): one narrow row for
 * each id a click keeps in 202_google, keyed by the first 8 bytes of the
 * id's SHA-256, whatever kind of id it is. A global postback with no SubID
 * finds its click through it (ClickByAdClickId).
 *
 * 202_google has no key on its ids, and a key on each of the six made the
 * table four times its size and every click's insert several times dearer
 * (measured on 4 million rows: +1,209 MB and 56 to 593 µs a click, against
 * +93 MB and 11 µs for these rows). The hash only narrows: the reader
 * compares the id itself, as 202_google stores it.
 *
 * Written with the click's 202_google row, in its transaction
 * (MysqlClickRepository::writeAdClickIdRows()), and deleted with the click
 * (ClickRetention). The
 * 1.9.80 upgrade makes the table and fills it for the clicks of the last
 * 30 days, the ones a postback can still be matched to; until it has run
 * a click is recorded without its rows, and the upgrade writes them.
 */
final class AdClickIdIndex
{
    public const TABLE = '202_clicks_ad_ids';

    /** Clicks read from 202_google per upgrade statement: each holds the rows it reads for well under a second. */
    public const BACKFILL_BATCH = 50000;

    private function __construct()
    {
    }

    /** The 8 bytes an id is found by. */
    public static function hash(string $id): string
    {
        return substr(hash('sha256', $id, true), 0, 8);
    }

    /**
     * The clicks with a row for $id, newest id first, at most $limit + 1 of
     * them (one more than the limit says there are more). On the write
     * handle: a replica may not have the click yet.
     *
     * @return list<int>
     */
    public static function clicksWith(Connection $conn, string $id, int $limit): array
    {
        $stmt = $conn->prepareWrite(
            // As hexadecimal: the bytes are not text, and a server may refuse them as a string.
            'SELECT x.click_id FROM ' . self::TABLE . ' AS x WHERE x.id_hash = UNHEX(?)'
                . ' ORDER BY x.click_id DESC LIMIT ' . ($limit + 1)
        );
        $conn->bind($stmt, 's', [bin2hex(self::hash($id))]);

        return array_map(static fn (array $row): int => (int) $row['click_id'], $conn->fetchAll($stmt));
    }

    /**
     * The upgrade's statement for the 202_google rows of clicks $from to
     * $to: one pass over them, a row for each id that is not ''. The hash
     * is hash()'s (UpgradeFillsTheAdClickIdIndex compares them).
     */
    public static function backfillSql(int $from, int $to): string
    {
        $id = 'CASE k.n WHEN 1 THEN g.gclid WHEN 2 THEN g.gbraid WHEN 3 THEN g.wbraid WHEN 4 THEN g.ttclid'
            . ' WHEN 5 THEN g.msclkid ELSE g.fbclid END';

        return 'INSERT IGNORE INTO ' . self::TABLE . ' (click_id, id_hash)'
            . ' SELECT g.click_id, UNHEX(LEFT(SHA2(' . $id . ', 256), 16)) FROM 202_google AS g'
            . ' JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6) AS k'
            . ' WHERE g.click_id BETWEEN ' . $from . ' AND ' . $to . ' AND ' . $id . " <> ''";
    }

    /** Whether $e, or one it wraps, is the server's 1146 for the table. */
    public static function tableMissing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (
                Connection::isMysqlError($current, 1146, '')
                && preg_match('/\b' . self::TABLE . '\b/', $current->getMessage()) === 1
            ) {
                return true;
            }
        }

        return false;
    }
}
