<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * The click ids the 1.9.79 upgrade adds to 202_google: gbraid, wbraid,
 * ttclid, msclkid and fbclid (ClickRecord), and the oppref the 1.9.80
 * upgrade adds after them. An install's files are replaced
 * first and the upgrade run after, and in between a statement that names
 * one is the server's 1054. The click path and the postbacks have nobody to
 * answer, so there a click is recorded without its ids, and the tokens they
 * fill read none, rather than the click or the conversion being lost. The
 * reads that predate them read them here, apart, so a failure cannot take
 * the rest of a click's values with it.
 */
final class AdClickIdColumns
{
    /** In the order the table has them. */
    public const ADDED = ['gbraid', 'wbraid', 'ttclid', 'msclkid', 'fbclid', 'oppref'];

    /**
     * The ones the 1.9.80 upgrade adds: a database the 1.9.79 upgrade has
     * reached keeps the others while it waits for this one.
     */
    public const ADDED_IN_1_9_80 = ['oppref'];

    private function __construct()
    {
    }

    /**
     * The click's ids, '' for none, each the greatest of its rows' (sql()):
     * for a click its caller has already read as the account's. On the write
     * handle, which sees a click its caller's transaction has just written.
     *
     * @return array{gbraid: string, wbraid: string, ttclid: string, msclkid: string, fbclid: string, oppref: string}
     */
    public static function of(Connection $conn, int $clickId): array
    {
        try {
            $row = self::read($conn, $clickId, self::ADDED);
        } catch (\RuntimeException $e) {
            if (!self::missing($e)) {
                throw $e;
            }
            $row = [];
            if (self::missingSince1980($e)) {
                try {
                    $row = self::read($conn, $clickId, array_values(array_diff(self::ADDED, self::ADDED_IN_1_9_80)));
                } catch (\RuntimeException $before) {
                    if (!self::missing($before)) {
                        throw $before;
                    }
                }
            }
        }

        return [
            'gbraid' => (string) ($row['gbraid'] ?? ''),
            'wbraid' => (string) ($row['wbraid'] ?? ''),
            'ttclid' => (string) ($row['ttclid'] ?? ''),
            'msclkid' => (string) ($row['msclkid'] ?? ''),
            'fbclid' => (string) ($row['fbclid'] ?? ''),
            'oppref' => (string) ($row['oppref'] ?? ''),
        ];
    }

    /**
     * @param list<string> $names
     * @return array<string, mixed>
     */
    private static function read(Connection $conn, int $clickId, array $names): array
    {
        $stmt = $conn->prepareWrite(
            'SELECT ' . implode(', ', array_map(static fn (string $n): string => "MAX($n) AS $n", $names))
                . ' FROM 202_google WHERE click_id = ?'
        );
        $conn->bind($stmt, 'i', [$clickId]);

        return $conn->fetchOne($stmt) ?? [];
    }

    /**
     * One of a click's ad click ids (ClickRecord::AD_CLICK_IDS) as a scalar
     * subquery, for a statement that reads clicks: the greatest of the
     * click's 202_google values, NULL when it has no row (the redirector
     * writes none). 202_google's key is (click_id, gclid), so a click can
     * have more than one row, and a join to it repeats the click once for
     * each (GoogleRowsAreNotJoinedTest refuses one; rowJoin() is the join
     * to one row). No row says which visit wrote it, so none can be read as
     * the latest; and taking the row with a gclid would drop an id another
     * row holds. Each id is read across the rows instead: '' sorts below
     * every id, so an id any row has is read, and rows that disagree always
     * give the same one.
     *
     * @param string $clickId the statement's click id column, alias.column
     */
    public static function sql(string $id, string $clickId): string
    {
        if (!array_key_exists($id, ClickRecord::AD_CLICK_IDS)) {
            throw new \InvalidArgumentException("$id is not an ad click id a click keeps");
        }
        self::column($clickId);

        // FORCE INDEX: MariaDB 11 can pick a full scan per row here (M4R1); PRIMARY (click_id, …) always exists.
        return "(SELECT MAX(g_ids.$id) FROM 202_google AS g_ids FORCE INDEX (PRIMARY) WHERE g_ids.click_id = $clickId)";
    }

    /**
     * The join to one of the click's 202_google rows, as $alias, for the
     * values that belong together (the UTM values): the row with the
     * greatest gclid, the last in the table's own key. A click with more
     * than one row is read once, and its UTM values are one row's, never a
     * mix. That row's gclid is the greatest, sql('gclid')'s; the other ids
     * are read with sql(), each the greatest of the rows'. No row (the
     * redirector writes none) joins NULL.
     *
     * The key compares gclids in its collation, so no two of a click's rows
     * have gclids it calls equal, and one row has none greater. Measured on
     * 500k clicks (MariaDB 10.11): about 12% more handler reads than the
     * plain join, fewer than an equality with a MAX() subquery.
     *
     * @param string $alias   the alias the statement reads the row by
     * @param string $clickId the statement's click id column, alias.column
     */
    public static function rowJoin(string $alias, string $clickId): string
    {
        if (preg_match('/\A[A-Za-z0-9_]+\z/D', $alias) !== 1 || strtolower($alias) === 'g_row') {
            throw new \InvalidArgumentException("$alias is not an alias rowJoin() can give");
        }
        self::column($clickId);

        return "LEFT JOIN 202_google AS $alias ON ($alias.click_id = $clickId AND NOT EXISTS (SELECT 1 FROM 202_google"
            . " AS g_row FORCE INDEX (PRIMARY) WHERE g_row.click_id = $alias.click_id AND g_row.gclid > $alias.gclid))";
    }

    private static function column(string $clickId): void
    {
        if (preg_match('/\A[A-Za-z0-9_]+\.[A-Za-z0-9_]+\z/D', $clickId) !== 1) {
            throw new \InvalidArgumentException("$clickId is not a column");
        }
    }

    /** Whether a URL has a [[token]] for one of them. */
    public static function namedIn(string $url): bool
    {
        return preg_match('/\[\[(?:' . implode('|', self::ADDED) . ')\]\]/i', $url) === 1;
    }

    /** Whether $e, or one it wraps, is the server's 1054 for one of them. */
    public static function missing(\Throwable $e): bool
    {
        return self::missingOneOf($e, self::ADDED);
    }

    /** Whether $e, or one it wraps, is the server's 1054 for one the 1.9.80 upgrade adds. */
    public static function missingSince1980(\Throwable $e): bool
    {
        return self::missingOneOf($e, self::ADDED_IN_1_9_80);
    }

    /** @param list<string> $names */
    private static function missingOneOf(\Throwable $e, array $names): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (Connection::isMysqlError($current, 1054, '') && self::namesOne($current->getMessage(), $names)) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Unknown column 'gbraid' in 'field list'", or '2g.gbraid' when the
     * statement names it so. The quoted name, not the words, which follow
     * the server's lc_messages.
     *
     * @param list<string> $names
     */
    private static function namesOne(string $message, array $names): bool
    {
        return preg_match("/'(?:[0-9A-Za-z_]+\\.)?(?:" . implode('|', $names) . ")'/", $message) === 1;
    }
}
