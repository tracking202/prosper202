<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;

/**
 * Engagement / ABM reads over the customer-stamped click stream
 * (202_clicks_tracking.customer_id, stamped at conversion time and — since
 * the personalization beacon — at pageview time for recognized customers).
 *
 * ABM views group customers by the CRM `company` field, turning per-person
 * browsing into account-level interest: which offers is each target account
 * engaging with, how often, and how recently.
 */
final class MysqlEngagementRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * What one customer has been browsing: campaigns (and their landing
     * pages) with click counts and recency, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function customerEngagement(int $userId, int $customerId, int $days = 90, int $limit = 25): array
    {
        $since = time() - max(1, $days) * 86400;

        $stmt = $this->conn->prepareRead(
            'SELECT c.aff_campaign_id AS campaign_id,
                    ac.aff_campaign_name AS campaign_name,
                    lp.landing_page_nickname AS landing_page,
                    COUNT(*) AS clicks,
                    MAX(c.click_time) AS last_seen,
                    SUM(c.click_lead) AS conversions
             FROM 202_clicks_tracking ct
             JOIN 202_clicks c ON c.click_id = ct.click_id
             LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
             LEFT JOIN 202_landing_pages lp ON lp.landing_page_id = c.landing_page_id
             WHERE ct.customer_id = ? AND c.user_id = ? AND c.click_time >= ?
             GROUP BY c.aff_campaign_id, ac.aff_campaign_name, lp.landing_page_nickname
             ORDER BY last_seen DESC
             LIMIT ?'
        );
        $this->conn->bind($stmt, 'iiii', [$customerId, $userId, $since, $limit]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * ABM account rollup: customers grouped by non-empty CRM company —
     * contacts, engagement volume/recency in the window, revenue, MRR, and
     * each account's most-browsed campaign.
     *
     * @return list<array<string, mixed>>
     */
    public function abmBreakdown(int $userId, int $days = 90, int $limit = 50, int $offset = 0): array
    {
        $since = time() - max(1, $days) * 86400;

        $stmt = $this->conn->prepareRead(
            "SELECT cu.company,
                    COUNT(DISTINCT cu.customer_id) AS contacts,
                    COALESCE(SUM(cu.total_revenue), 0) AS total_revenue,
                    COALESCE(SUM(cu.mrr), 0) AS mrr,
                    MAX(cu.last_activity_time) AS last_activity,
                    COALESCE(eng.clicks, 0) AS engagements,
                    eng.top_campaign_name
             FROM 202_customers cu
             LEFT JOIN (
                 SELECT cu2.company AS company, COUNT(*) AS clicks,
                        SUBSTRING_INDEX(GROUP_CONCAT(ac.aff_campaign_name ORDER BY c.click_time DESC SEPARATOR ','), ',', 1) AS top_campaign_name
                 FROM 202_customers cu2
                 JOIN 202_clicks_tracking ct ON ct.customer_id = cu2.customer_id
                 JOIN 202_clicks c ON c.click_id = ct.click_id AND c.click_time >= ?
                 LEFT JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = c.aff_campaign_id
                 WHERE cu2.user_id = ? AND cu2.company IS NOT NULL AND cu2.company <> ''
                   AND cu2.merged_into_customer_id IS NULL
                 GROUP BY cu2.company
             ) eng ON eng.company = cu.company
             WHERE cu.user_id = ? AND cu.company IS NOT NULL AND cu.company <> ''
               AND cu.merged_into_customer_id IS NULL
             GROUP BY cu.company, eng.clicks, eng.top_campaign_name
             ORDER BY engagements DESC, total_revenue DESC
             LIMIT ? OFFSET ?"
        );
        $this->conn->bind($stmt, 'iiiii', [$since, $userId, $userId, $limit, $offset]);

        return $this->conn->fetchAll($stmt);
    }

    /**
     * One account's contacts with their individual engagement and value.
     *
     * @return list<array<string, mixed>>
     */
    public function abmCompanyDetail(int $userId, string $company, int $days = 90): array
    {
        $since = time() - max(1, $days) * 86400;

        $stmt = $this->conn->prepareRead(
            "SELECT cu.customer_id, cu.first_name, cu.last_name, cu.email,
                    cu.order_count, cu.total_revenue, cu.mrr, cu.last_activity_time,
                    COALESCE(eng.clicks, 0) AS engagements
             FROM 202_customers cu
             LEFT JOIN (
                 SELECT ct.customer_id, COUNT(*) AS clicks
                 FROM 202_clicks_tracking ct
                 JOIN 202_clicks c ON c.click_id = ct.click_id AND c.click_time >= ?
                 GROUP BY ct.customer_id
             ) eng ON eng.customer_id = cu.customer_id
             WHERE cu.user_id = ? AND cu.company = ? AND cu.merged_into_customer_id IS NULL
             ORDER BY engagements DESC, cu.total_revenue DESC"
        );
        $this->conn->bind($stmt, 'iis', [$since, $userId, $company]);

        return $this->conn->fetchAll($stmt);
    }
}
