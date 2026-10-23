<?php

declare(strict_types=1);

namespace Prosper202\Click;

use Prosper202\Database\Connection;

/**
 * The click ids the 1.9.79 upgrade adds to 202_google: gbraid, wbraid and
 * ttclid (ClickRecord). An install's files are replaced first and the
 * upgrade run after, and in between a statement that names one is the
 * server's 1054. The click path and the postbacks have nobody to answer, so
 * there a click is recorded without its ids, and the tokens they fill read
 * none, rather than the click or the conversion being lost. The reads that
 * predate them read them here, apart, so a failure cannot take the rest of
 * a click's values with it.
 */
final class AdClickIdColumns
{
    /** In the order the table has them. */
    public const ADDED = ['gbraid', 'wbraid', 'ttclid'];

    private function __construct()
    {
    }

    /**
     * The click's ids, '' for none: for a click its caller has already read
     * as the account's. On the write handle, which sees a click its caller's
     * transaction has just written.
     *
     * @return array{gbraid: string, wbraid: string, ttclid: string}
     */
    public static function of(Connection $conn, int $clickId): array
    {
        try {
            $stmt = $conn->prepareWrite('SELECT gbraid, wbraid, ttclid FROM 202_google WHERE click_id = ? LIMIT 1');
            $conn->bind($stmt, 'i', [$clickId]);
            $row = $conn->fetchOne($stmt) ?? [];
        } catch (\RuntimeException $e) {
            if (!self::missing($e)) {
                throw $e;
            }
            $row = [];
        }

        return [
            'gbraid' => (string) ($row['gbraid'] ?? ''),
            'wbraid' => (string) ($row['wbraid'] ?? ''),
            'ttclid' => (string) ($row['ttclid'] ?? ''),
        ];
    }

    /** Whether a URL has a [[token]] for one of them. */
    public static function namedIn(string $url): bool
    {
        return preg_match('/\[\[(?:' . implode('|', self::ADDED) . ')\]\]/i', $url) === 1;
    }

    /** Whether $e, or one it wraps, is the server's 1054 for one of them. */
    public static function missing(\Throwable $e): bool
    {
        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            if (Connection::isMysqlError($current, 1054, '') && self::namesOne($current->getMessage())) {
                return true;
            }
        }

        return false;
    }

    /**
     * "Unknown column 'gbraid' in 'field list'", or '2g.gbraid' when the
     * statement names it so. The quoted name, not the words, which follow
     * the server's lc_messages.
     */
    private static function namesOne(string $message): bool
    {
        return preg_match("/'(?:[0-9A-Za-z_]+\\.)?(?:" . implode('|', self::ADDED) . ")'/", $message) === 1;
    }
}
