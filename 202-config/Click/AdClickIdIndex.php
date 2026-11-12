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
 * 202_google has no key on its ids, and a key on each of the six (oppref,
 * the seventh, came after) made the
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

    /** The version whose upgrade makes and fills the table: before it, a lookup knows nothing. */
    public const SINCE = '1.9.80';

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
     * The clicks with a row for $id that have a visit since $since, newest id
     * first, at most $limit + 1 of them (one more than the limit says there
     * are more). The window is asked before the limit, so old clicks
     * carrying the id never crowd out a recent one; the visit is read
     * with its click_time bound, which keeps the probe to the window's
     * partitions. On the write handle: a replica may not have the click yet.
     *
     * @return list<int>
     */
    public static function clicksWith(Connection $conn, string $id, int $since, int $limit): array
    {
        $stmt = $conn->prepareWrite(self::lookupSql($limit));
        // As hexadecimal: the bytes are not text, and a server may refuse them as a string.
        $conn->bind($stmt, 'si', [bin2hex(self::hash($id)), $since]);

        return array_map(static fn (array $row): int => (int) $row['click_id'], $conn->fetchAll($stmt));
    }

    /** clicksWith()'s statement: the hash, then the earliest visit time, bound. */
    public static function lookupSql(int $limit): string
    {
        return 'SELECT x.click_id FROM ' . self::TABLE . ' AS x WHERE x.id_hash = UNHEX(?)'
            . ' AND EXISTS (SELECT 1 FROM 202_clicks AS v WHERE v.click_id = x.click_id AND v.click_time >= ?)'
            . ' ORDER BY x.click_id DESC LIMIT ' . ($limit + 1);
    }

    /**
     * The upgrade's statement for the clicks with ids $from to $to that
     * have a visit since $since: a row for each id of their 202_google rows
     * that is not ''. The clicks are chosen by their visit times, not by
     * the id range alone (ids are not in time order, CLAUDE.md #26). The
     * hash is hash()'s (AdClickIdIndexUpgradeIntegrationTest compares the
     * rows with the click path's).
     */
    public static function backfillSql(int $from, int $to, int $since): string
    {
        $id = 'CASE k.n WHEN 1 THEN g.gclid WHEN 2 THEN g.gbraid WHEN 3 THEN g.wbraid WHEN 4 THEN g.ttclid'
            . ' WHEN 5 THEN g.msclkid WHEN 6 THEN g.fbclid ELSE g.oppref END';

        return 'INSERT IGNORE INTO ' . self::TABLE . ' (click_id, id_hash)'
            . ' SELECT g.click_id, UNHEX(LEFT(SHA2(' . $id . ', 256), 16))'
            . ' FROM (SELECT DISTINCT c.click_id FROM 202_clicks AS c WHERE c.click_id BETWEEN ' . $from . ' AND ' . $to
            . ' AND c.click_time >= ' . $since . ') AS w'
            . ' JOIN 202_google AS g ON g.click_id = w.click_id'
            . ' JOIN (SELECT 1 AS n UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7) AS k'
            . ' WHERE ' . $id . " <> ''";
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
