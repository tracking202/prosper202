<?php

declare(strict_types=1);

namespace Prosper202\DataEngine;

use Prosper202\Database\Connection;

/**
 * Queues the clicks whose report rows a Setup change made wrong, for the
 * cron job's dirty-hours pass to roll up again (DataEngine::processDirtyHours(),
 * every run of 202-cronjobs/index.php).
 *
 * 202_dataengine keeps two values a click takes from Setup rather than from
 * its own rows: the traffic source (ppc_network_id, the source of the
 * click's traffic source account) and the campaign category
 * (aff_network_id, the category of the click's campaign). The readers
 * filter and group by those copies — the traffic-source filter, the API's
 * breakdowns, Group Overview — while the Overview's traffic-source table
 * looks the account's source up, so after an account moved to another
 * source the same clicks answered under two sources (measured live: the
 * breakdown put six of seven clicks under the old source, the Overview all
 * seven under the new). A re-roll now refreshes every column
 * (ClickRollupSql), so queuing the account's or the campaign's clicks is
 * what brings them to the new value.
 *
 * One queue row per change, covering every click the account (or campaign)
 * has up to now: the pass rolls the range up in one statement, as it does
 * for an Update CPC (CpcUpdate). A row already queued for the same account
 * or campaign and second is armed again rather than ignored. Every call
 * throws on failure (Connection): the caller's Setup write has landed, so
 * the caller reports it as landed (CLAUDE.md #13).
 */
final class RollupRefresh
{
    private function __construct()
    {
    }

    /** A traffic source account moved to another traffic source. */
    public static function account(Connection $conn, int $userId, int $ppcAccountId, int $now): void
    {
        self::queue($conn, $userId, $ppcAccountId, 0, $now);
    }

    /** A campaign moved to another category. */
    public static function campaign(Connection $conn, int $userId, int $campaignId, int $now): void
    {
        self::queue($conn, $userId, 0, $campaignId, $now);
    }

    private static function queue(Connection $conn, int $userId, int $ppcAccountId, int $campaignId, int $now): void
    {
        if ($userId < 1 || ($ppcAccountId < 1 && $campaignId < 1)) {
            throw new \InvalidArgumentException(
                'a rollup refresh names an account and its traffic source account or campaign'
            );
        }
        $stmt = $conn->prepareWrite(
            'INSERT INTO 202_dirty_hours'
            . ' (ppc_account_id, aff_campaign_id, user_id, click_time_from, click_time_to, landing_page_id)'
            . ' VALUES (?, ?, ?, 0, ?, 0) ON DUPLICATE KEY UPDATE processed = 0, deleted = 0'
        );
        $conn->bind($stmt, 'iiii', [$ppcAccountId, $campaignId, $userId, $now]);
        $conn->executeUpdate($stmt);
    }
}
