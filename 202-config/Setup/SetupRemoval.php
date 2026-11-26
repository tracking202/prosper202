<?php

declare(strict_types=1);

namespace Prosper202\Setup;

use Prosper202\Database\Connection;
use Prosper202\Http\PostedId;

/**
 * A Setup page's Remove: marks one of the account's own records deleted and
 * says whether it did, so a page redirects to ?deleted=1 only when a row was
 * marked (another account's id, or one removed already, marks none).
 */
final class SetupRemoval
{
    /**
     * kind => its statement, written out so the scans that read a write's
     * table can read it.
     *
     * @var array<string, string>
     */
    private const STATEMENTS = [
        'campaign_category' => 'UPDATE 202_aff_networks SET aff_network_deleted = 1, aff_network_time = ?'
            . ' WHERE aff_network_id = ? AND user_id = ? AND aff_network_deleted = 0',
        'campaign' => 'UPDATE 202_aff_campaigns SET aff_campaign_deleted = 1, aff_campaign_time = ?'
            . ' WHERE aff_campaign_id = ? AND user_id = ? AND aff_campaign_deleted = 0',
        'landing_page' => 'UPDATE 202_landing_pages SET landing_page_deleted = 1, landing_page_time = ?'
            . ' WHERE landing_page_id = ? AND user_id = ? AND landing_page_deleted = 0',
        'text_ad' => 'UPDATE 202_text_ads SET text_ad_deleted = 1, text_ad_time = ?'
            . ' WHERE text_ad_id = ? AND user_id = ? AND text_ad_deleted = 0',
        'traffic_source' => 'UPDATE 202_ppc_networks SET ppc_network_deleted = 1, ppc_network_time = ?'
            . ' WHERE ppc_network_id = ? AND user_id = ? AND ppc_network_deleted = 0',
        'traffic_source_account' => 'UPDATE 202_ppc_accounts SET ppc_account_deleted = 1, ppc_account_time = ?'
            . ' WHERE ppc_account_id = ? AND user_id = ? AND ppc_account_deleted = 0',
    ];

    /**
     * True when the record named by $rawId was the account's, not removed
     * already, and is marked removed now. An id that is not one (`12abc`,
     * `0`, a list) names nothing: compared as text, `'12abc'` matched record
     * 12. A failed statement throws (QueryException), never false.
     */
    public static function mark(Connection $conn, string $kind, mixed $rawId, int $userId, int $time): bool
    {
        if (!isset(self::STATEMENTS[$kind])) {
            throw new \InvalidArgumentException('Unknown setup record kind: ' . $kind);
        }
        $id = PostedId::read($rawId);
        if ($id === null || $userId < 1) {
            return false;
        }
        $stmt = $conn->prepareWrite(self::STATEMENTS[$kind]);
        $conn->bind($stmt, 'iii', [$time, $id, $userId]);

        return $conn->executeUpdate($stmt) === 1;
    }
}
