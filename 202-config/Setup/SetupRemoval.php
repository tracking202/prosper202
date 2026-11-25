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
    /** @var array<string, array{0: string, 1: string}> kind => [table, column prefix] */
    private const KINDS = [
        'campaign_category' => ['202_aff_networks', 'aff_network'],
        'campaign' => ['202_aff_campaigns', 'aff_campaign'],
        'landing_page' => ['202_landing_pages', 'landing_page'],
        'text_ad' => ['202_text_ads', 'text_ad'],
        'traffic_source' => ['202_ppc_networks', 'ppc_network'],
        'traffic_source_account' => ['202_ppc_accounts', 'ppc_account'],
    ];

    /**
     * True when the record named by $rawId was the account's, not removed
     * already, and is marked removed now. An id that is not one (`12abc`,
     * `0`, a list) names nothing: compared as text, `'12abc'` matched record
     * 12. A failed statement throws (QueryException), never false.
     */
    public static function mark(Connection $conn, string $kind, mixed $rawId, int $userId, int $time): bool
    {
        if (!isset(self::KINDS[$kind])) {
            throw new \InvalidArgumentException('Unknown setup record kind: ' . $kind);
        }
        $id = PostedId::read($rawId);
        if ($id === null || $userId < 1) {
            return false;
        }
        [$table, $prefix] = self::KINDS[$kind];
        $stmt = $conn->prepareWrite(sprintf(
            'UPDATE %s SET %s_deleted = 1, %s_time = ? WHERE %s_id = ? AND user_id = ? AND %s_deleted = 0',
            $table,
            $prefix,
            $prefix,
            $prefix,
            $prefix
        ));
        $conn->bind($stmt, 'iii', [$time, $id, $userId]);

        return $conn->executeUpdate($stmt) === 1;
    }
}
