<?php

declare(strict_types=1);

namespace Prosper202\Report;

use Prosper202\Database\Connection;

/**
 * The daily email's figures (202-cronjobs/daily-email.php): one account's
 * campaigns, today's busiest by net and the same campaigns over another
 * window, from its own clicks.
 *
 * Whose clicks: the account the email is addressed to. The cron read every
 * account's clicks and mailed them to user 1, each under its campaign's
 * name, whoever's campaign that was -- the Overview the same user reads
 * counts only its own (DataScope).
 *
 * A campaign is named only when it is the click's own account's (CLAUDE.md
 * #27): a click naming another account's campaign -- nothing stopped a
 * tracker naming one before 229df10 -- is counted under campaign 0 with the
 * clicks that name none, in both windows alike, so the comparison is of the
 * same rows.
 */
final class DailyEmailCampaigns
{
    /** A click's campaign: its own account's, or 0. */
    private const CAMPAIGN = 'COALESCE(ca.aff_campaign_id, 0)';

    private const FIGURES = 'COUNT(*) AS clicks,
            SUM(cr.click_out) AS click_throughs,
            (SUM(cr.click_out)/COUNT(*))*100 AS ctr,
            SUM(c.click_lead) AS leads,
            (SUM(c.click_lead)/COUNT(*))*100 as su_ratio,
            SUM(c.click_payout*c.click_lead) AS income,
            SUM(c.click_cpc) AS cost,
            (SUM(c.click_payout*c.click_lead)-SUM(c.click_cpc)) AS net,
            ((SUM(c.click_payout*c.click_lead)-SUM(c.click_cpc))/SUM(c.click_cpc)*100 ) as roi';

    private const FROM = 'FROM 202_clicks AS c
            LEFT JOIN 202_clicks_record AS cr USING (click_id)
            LEFT JOIN 202_aff_campaigns AS ca ON (ca.aff_campaign_id = c.aff_campaign_id AND ca.user_id = c.user_id)';

    private function __construct()
    {
    }

    /**
     * The account's campaigns in [from, to], busiest by net first.
     *
     * @return list<array<string, mixed>> aff_campaign_id (int, 0 for none), aff_campaign_name, then the figures
     */
    public static function top(Connection $conn, int $userId, int $from, int $to, int $limit): array
    {
        $stmt = $conn->prepareRead('SELECT ' . self::CAMPAIGN . ' AS aff_campaign_id, MAX(ca.aff_campaign_name) AS aff_campaign_name, '
            . self::FIGURES . ' ' . self::FROM
            . ' WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ?'
            . ' GROUP BY ' . self::CAMPAIGN . ' ORDER BY net DESC LIMIT ' . max(1, $limit));
        $conn->bind($stmt, 'iii', [$userId, $from, $to]);

        return self::keyed($conn->fetchAll($stmt));
    }

    /**
     * The given campaigns' figures in [from, to], for the account.
     *
     * @param list<int> $campaignIds as top() names them (0 for none)
     * @return list<array<string, mixed>>
     */
    public static function forCampaigns(Connection $conn, int $userId, array $campaignIds, int $from, int $to): array
    {
        if ($campaignIds === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $campaignIds)));
        $stmt = $conn->prepareRead('SELECT ' . self::CAMPAIGN . ' AS aff_campaign_id, MAX(ca.aff_campaign_name) AS aff_campaign_name, '
            . self::FIGURES . ' ' . self::FROM
            . ' WHERE c.user_id = ? AND c.click_time >= ? AND c.click_time <= ?'
            . ' AND ' . self::CAMPAIGN . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')'
            . ' GROUP BY ' . self::CAMPAIGN);
        $conn->bind($stmt, str_repeat('i', 3 + count($ids)), array_merge([$userId, $from, $to], $ids));

        return self::keyed($conn->fetchAll($stmt));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private static function keyed(array $rows): array
    {
        return array_map(
            static fn (array $row): array => ['aff_campaign_id' => (int) $row['aff_campaign_id']] + $row,
            $rows
        );
    }
}
