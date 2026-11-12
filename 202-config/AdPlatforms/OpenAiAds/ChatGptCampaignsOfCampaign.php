<?php

declare(strict_types=1);

namespace Prosper202\AdPlatforms\OpenAiAds;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * Which ChatGPT Ads campaigns sent a Prosper202 campaign its clicks since a
 * time: the `campaign_id` variable the ChatGPT Ads tracking template puts on
 * each click ({campaign_id}), on clicks whose traffic source account is
 * linked to an ad connection. A money-loss alert names them, so the campaign
 * losing money can be paused where it runs.
 *
 * Every join is tied to the click's account (CLAUDE.md #27): the source
 * account and its traffic source are the account's own, and the variable is
 * one of that source's. The lookup rows (variable sets, custom variables)
 * are shared and name a variable by id only, so the tie is through the
 * source's variable definitions.
 */
final class ChatGptCampaignsOfCampaign
{
    public const MAX = 3;
    private const PARAMETER = 'campaign_id';
    private const CAMPAIGN_ID = '/\A[a-z]+_[A-Za-z0-9-]{1,64}\z/D';

    /**
     * @return list<array{connection_id: int, campaign_id: string, clicks: int}> most clicks first
     */
    public static function since(Connection $conn, int $userId, int $affCampaignId, int $since): array
    {
        $stmt = $conn->prepareRead(
            'SELECT ac.connection_id, cv.variable AS campaign_ref, COUNT(DISTINCT c.click_id) AS clicks'
            . ' FROM 202_clicks c'
            . ' JOIN 202_ad_connections ac ON ac.ppc_account_id = c.ppc_account_id AND ac.user_id = c.user_id'
            . "   AND ac.platform = 'openai_ads' AND ac.deleted_at IS NULL"
            . ' JOIN 202_ppc_accounts pa ON pa.ppc_account_id = c.ppc_account_id AND pa.user_id = c.user_id'
            . ' JOIN 202_ppc_networks pn ON pn.ppc_network_id = pa.ppc_network_id AND pn.user_id = c.user_id'
            . ' JOIN 202_clicks_variable v ON v.click_id = c.click_id'
            . ' JOIN 202_variable_sets2 vs ON vs.variable_set_id = v.variable_set_id'
            . ' JOIN 202_custom_variables cv ON cv.custom_variable_id = vs.variables'
            . ' JOIN 202_ppc_network_variables pv ON pv.ppc_variable_id = cv.ppc_variable_id AND pv.ppc_network_id = pn.ppc_network_id'
            . '   AND pv.parameter = ?'
            . ' WHERE c.user_id = ? AND c.aff_campaign_id = ? AND c.click_time >= ? AND ' . LatestVisit::isLatest('c')
            . ' GROUP BY ac.connection_id, cv.variable ORDER BY clicks DESC, ac.connection_id, cv.variable LIMIT 20'
        );
        $conn->bind($stmt, 'siii', [self::PARAMETER, $userId, $affCampaignId, $since]);
        $found = [];
        foreach ($conn->fetchAll($stmt) as $row) {
            $ref = (string) $row['campaign_ref'];
            // A value a visitor typed, or a template that did not fill the macro, names no campaign.
            if (preg_match(self::CAMPAIGN_ID, $ref) !== 1) {
                continue;
            }
            $found[] = ['connection_id' => (int) $row['connection_id'], 'campaign_id' => $ref, 'clicks' => (int) $row['clicks']];
            if (count($found) === self::MAX) {
                break;
            }
        }

        return $found;
    }
}
