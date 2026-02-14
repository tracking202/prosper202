<?php

declare(strict_types=1);

namespace Api\V3\Controllers;

use Api\V3\Bootstrap;

class ClicksController
{
    private \mysqli $db;
    private int $userId;

    public function __construct()
    {
        $this->db = Bootstrap::db();
        $this->userId = Bootstrap::userId();
    }

    public function list(array $params): array
    {
        $limit = max(1, min(500, (int)($params['limit'] ?? 50)));
        $offset = max(0, (int)($params['offset'] ?? 0));

        $where = ['c.user_id = ?'];
        $binds = [$this->userId];
        $types = 'i';

        // Date range filters (unix timestamps)
        if (!empty($params['time_from'])) {
            $where[] = 'c.click_time >= ?';
            $binds[] = (int)$params['time_from'];
            $types .= 'i';
        }
        if (!empty($params['time_to'])) {
            $where[] = 'c.click_time <= ?';
            $binds[] = (int)$params['time_to'];
            $types .= 'i';
        }

        // Entity filters
        foreach (['aff_campaign_id', 'ppc_account_id', 'landing_page_id'] as $filter) {
            if (!empty($params[$filter])) {
                $where[] = "c.$filter = ?";
                $binds[] = (int)$params[$filter];
                $types .= 'i';
            }
        }

        // Lead/conversion filter
        if (isset($params['click_lead'])) {
            $where[] = 'c.click_lead = ?';
            $binds[] = (int)$params['click_lead'];
            $types .= 'i';
        }

        // Bot filter
        if (isset($params['click_bot'])) {
            $where[] = 'c.click_bot = ?';
            $binds[] = (int)$params['click_bot'];
            $types .= 'i';
        }

        $whereClause = 'WHERE ' . implode(' AND ', $where);

        // Count
        $countSql = "SELECT COUNT(*) as total FROM 202_clicks c $whereClause";
        $stmt = $this->db->prepare($countSql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        // Fetch with joins for enriched data
        $sql = "SELECT
                c.click_id, c.aff_campaign_id, c.ppc_account_id, c.landing_page_id,
                c.click_cpc, c.click_payout, c.click_lead, c.click_filtered,
                c.click_bot, c.click_alp, c.click_time, c.rotator_id, c.rule_id,
                cr.click_id_public, cr.click_cloaking, cr.click_in, cr.click_out,
                ca.keyword_id, ca.country_id, ca.platform_id, ca.browser_id, ca.device_id,
                lc.country_name, lc.country_code,
                p.platform_name, b.browser_name
            FROM 202_clicks c
            LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
            LEFT JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
            LEFT JOIN 202_locations_country lc ON ca.country_id = lc.country_id
            LEFT JOIN 202_platforms p ON ca.platform_id = p.platform_id
            LEFT JOIN 202_browsers b ON ca.browser_id = b.browser_id
            $whereClause
            ORDER BY c.click_time DESC
            LIMIT ? OFFSET ?";

        $binds[] = $limit;
        $types .= 'i';
        $binds[] = $offset;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$binds);
        $stmt->execute();
        $result = $stmt->get_result();

        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();

        return [
            'data' => $rows,
            'pagination' => ['total' => $total, 'limit' => $limit, 'offset' => $offset],
        ];
    }

    public function get(int $id): array
    {
        $sql = "SELECT
                c.*, cr.click_id_public, cr.click_cloaking, cr.click_in, cr.click_out, cr.click_reviewed,
                ca.text_ad_id, ca.keyword_id, ca.ip_id, ca.country_id, ca.region_id,
                ca.city_id, ca.platform_id, ca.browser_id, ca.device_id, ca.isp_id,
                ct.c1_id, ct.c2_id, ct.c3_id, ct.c4_id,
                lc.country_name, lc.country_code,
                lr.region_name, lci.city_name, li.isp_name,
                p.platform_name, b.browser_name
            FROM 202_clicks c
            LEFT JOIN 202_clicks_record cr ON c.click_id = cr.click_id
            LEFT JOIN 202_clicks_advance ca ON c.click_id = ca.click_id
            LEFT JOIN 202_clicks_tracking ct ON c.click_id = ct.click_id
            LEFT JOIN 202_locations_country lc ON ca.country_id = lc.country_id
            LEFT JOIN 202_locations_region lr ON ca.region_id = lr.region_id
            LEFT JOIN 202_locations_city lci ON ca.city_id = lci.city_id
            LEFT JOIN 202_locations_isp li ON ca.isp_id = li.isp_id
            LEFT JOIN 202_platforms p ON ca.platform_id = p.platform_id
            LEFT JOIN 202_browsers b ON ca.browser_id = b.browser_id
            WHERE c.click_id = ? AND c.user_id = ?
            LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $id, $this->userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new \RuntimeException('Click not found', 404);
        }

        return ['data' => $row];
    }
}
