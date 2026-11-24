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

    /** The ids of the UTM values a click keeps in 202_google, by value: sql() reads them, utm() names the value. */
    public const UTM_IDS = [
        'utm_source' => 'utm_source_id',
        'utm_medium' => 'utm_medium_id',
        'utm_campaign' => 'utm_campaign_id',
        'utm_term' => 'utm_term_id',
        'utm_content' => 'utm_content_id',
    ];

    /**
     * One of a click's ad click ids (ClickRecord::AD_CLICK_IDS) or UTM_IDS
     * as a scalar subquery, for a statement that reads clicks: the greatest
     * of the click's 202_google values, NULL when it has no row (the
     * redirector writes none). 202_google's key is (click_id, gclid), so a
     * click can have more than one row, and a join to it repeats the click
     * once for each (GoogleRowsAreNotJoinedTest refuses one). No row says
     * which visit wrote it, so none can be read as the latest; and taking
     * the row with a gclid would drop an id another row holds. Each id is
     * read across the rows instead: '' sorts below every id (0 below every
     * UTM value's), so an id any row has is read, and rows that disagree
     * always give the same one.
     *
     * @param string $clickId the statement's click id column, alias.column
     */
    public static function sql(string $id, string $clickId): string
    {
        if (!array_key_exists($id, ClickRecord::AD_CLICK_IDS) && !in_array($id, self::UTM_IDS, true)) {
            throw new \InvalidArgumentException("$id is not an ad click id or a UTM value's id a click keeps");
        }
        if (preg_match('/\A[A-Za-z0-9_]+\.[A-Za-z0-9_]+\z/D', $clickId) !== 1) {
            throw new \InvalidArgumentException("$clickId is not a column");
        }

        // FORCE INDEX: MariaDB 11 can pick a full scan per row here (M4R1); PRIMARY (click_id, …) always exists.
        return "(SELECT MAX(g_ids.$id) FROM 202_google AS g_ids FORCE INDEX (PRIMARY) WHERE g_ids.click_id = $clickId)";
    }

    /**
     * One of a click's UTM values (a key of UTM_IDS) as a scalar subquery:
     * the value of the id sql() reads, NULL when there is none.
     *
     * @param string $clickId the statement's click id column, alias.column
     */
    public static function utm(string $name, string $clickId): string
    {
        // Each table named in full, for the scans that read a statement's tables.
        $lookup = match ($name) {
            'utm_source' => '(SELECT utm_v.utm_source FROM 202_utm_source AS utm_v WHERE utm_v.utm_source_id = ',
            'utm_medium' => '(SELECT utm_v.utm_medium FROM 202_utm_medium AS utm_v WHERE utm_v.utm_medium_id = ',
            'utm_campaign' => '(SELECT utm_v.utm_campaign FROM 202_utm_campaign AS utm_v WHERE utm_v.utm_campaign_id = ',
            'utm_term' => '(SELECT utm_v.utm_term FROM 202_utm_term AS utm_v WHERE utm_v.utm_term_id = ',
            'utm_content' => '(SELECT utm_v.utm_content FROM 202_utm_content AS utm_v WHERE utm_v.utm_content_id = ',
            default => throw new \InvalidArgumentException("$name is not a UTM value a click keeps"),
        };

        return $lookup . self::sql(self::UTM_IDS[$name], $clickId) . ')';
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
