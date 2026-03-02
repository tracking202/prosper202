<?php

declare(strict_types=1);

namespace Prosper202\Tracker;

use Prosper202\Database\Connection;

final class MysqlTrackerRepository implements TrackerRepositoryInterface
{
    public function __construct(private Connection $conn)
    {
    }

    public function findByPublicId(string $publicId): ?array
    {
        $sql = "SELECT 202_trackers.user_id,
                       202_trackers.aff_campaign_id,
                       text_ad_id,
                       ppc_account_id,
                       click_cpc,
                       click_cpa,
                       click_cloaking,
                       aff_campaign_rotate,
                       aff_campaign_url,
                       aff_campaign_url_2,
                       aff_campaign_url_3,
                       aff_campaign_url_4,
                       aff_campaign_url_5,
                       aff_campaign_payout,
                       aff_campaign_cloaking,
                       2cv.ppc_variable_ids,
                       2cv.parameters,
                       user_timezone,
                       user_keyword_searched_or_bidded,
                       user_pref_referer_data,
                       user_pref_dynamic_bid,
                       maxmind_isp
                FROM 202_trackers
                LEFT JOIN 202_users_pref USING (user_id)
                LEFT JOIN 202_users USING (user_id)
                LEFT JOIN 202_aff_campaigns USING (aff_campaign_id)
                LEFT JOIN 202_ppc_accounts USING (ppc_account_id)
                LEFT JOIN (SELECT ppc_network_id,
                                  GROUP_CONCAT(ppc_variable_id) AS ppc_variable_ids,
                                  GROUP_CONCAT(parameter) AS parameters
                           FROM 202_ppc_network_variables
                           GROUP BY ppc_network_id) AS 2cv USING (ppc_network_id)
                WHERE tracker_id_public = ?";

        $stmt = $this->conn->prepareRead($sql);
        $this->conn->bind($stmt, 's', [$publicId]);

        return $this->conn->fetchOne($stmt);
    }
}
