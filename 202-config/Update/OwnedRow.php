<?php

declare(strict_types=1);

namespace Prosper202\Update;

use Prosper202\Database\Connection;

/**
 * The ownership check every Update operation makes on an id it was handed: a
 * category, campaign, traffic source, landing page or text ad that is not the
 * account's is refused, never silently widened to "every one".
 */
final class OwnedRow
{
    /**
     * Tables and id columns find() may name. The table and column are spliced
     * into the SQL, so they come from this list and nowhere else.
     */
    private const TABLES = [
        '202_aff_networks' => 'aff_network_id',
        '202_aff_campaigns' => 'aff_campaign_id',
        '202_ppc_networks' => 'ppc_network_id',
        '202_ppc_accounts' => 'ppc_account_id',
        '202_landing_pages' => 'landing_page_id',
        '202_text_ads' => 'text_ad_id',
    ];

    private function __construct()
    {
    }

    /**
     * One row the account owns, or null.
     *
     * Through Connection, whose bind/execute/fetch throw a QueryException on
     * failure: a failed lookup stops the operation rather than reading as "not
     * yours" or, worse, as "yours" (error pattern #1). The primary is asked,
     * not a replica, because the answer decides a write.
     *
     * @return array<string, mixed>|null
     */
    public static function find(Connection $conn, string $table, string $idColumn, int $id, int $userId): ?array
    {
        if ((self::TABLES[$table] ?? null) !== $idColumn) {
            throw new \InvalidArgumentException('OwnedRow cannot look up ' . $table . '.' . $idColumn);
        }
        $stmt = $conn->prepareWrite('SELECT * FROM `' . $table . '` WHERE `' . $idColumn . '` = ? AND user_id = ? LIMIT 1');
        $conn->bind($stmt, 'ii', [$id, $userId]);
        return $conn->fetchOne($stmt);
    }
}
