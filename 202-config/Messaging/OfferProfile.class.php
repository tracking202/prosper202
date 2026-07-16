<?php
// 202-config/Messaging/OfferProfile.class.php
require_once __DIR__ . '/UrlScrubber.class.php';

final class OfferProfile
{
    private const NEAR_LIMIT_PCT = 80;

    /** device_type values assigned by connect2.php device detection. */
    private const DEVICE_TYPE_DESKTOP = 1;
    private const DEVICE_TYPE_MOBILE = 2;
    private const DEVICE_TYPE_TABLET = 3;

    /** Pure: assemble one offer entry from a raw joined DB row. */
    public static function buildOfferRow(array $r): array
    {
        $url = UrlScrubber::scrub((string) ($r['aff_campaign_url'] ?? ''));
        $clicks = (float) ($r['clicks'] ?? 0);
        $income = (float) ($r['income'] ?? 0);
        $leads  = (float) ($r['leads'] ?? 0);
        return [
            'name'      => (string) ($r['aff_campaign_name'] ?? ''),
            'url'       => $url,
            'domain'    => self::domain($url),
            'network'   => (string) ($r['aff_network_name'] ?? ''),
            'payout'    => (float) ($r['aff_campaign_payout'] ?? 0),
            'currency'  => (string) ($r['aff_campaign_currency'] ?? ''),
            'epc'       => $clicks > 0 ? round($income / $clicks, 4) : 0.0,
            'conv_rate' => $clicks > 0 ? round($leads / $clicks, 4) : 0.0,
            'clicks_30d'=> (int) $clicks,
        ];
    }

    public static function planLimitPct(int $used, int $limit): int
    {
        if ($limit <= 0) { return 0; }
        return (int) floor($used / $limit * 100);
    }

    public static function nearPlanLimit(int $pct): bool
    {
        return $pct >= self::NEAR_LIMIT_PCT;
    }

    private static function domain(string $url): string
    {
        $h = parse_url($url, PHP_URL_HOST);
        return is_string($h) ? $h : '';
    }

    /**
     * Full profile. $now is the upper time bound (unix); window is 30 days.
     * Each query checks its return value; on any failure the partial-safe
     * defaults are returned (never throws).
     */
    public static function compute(mysqli $db, int $userId, int $now): array
    {
        $since = $now - 30 * 86400;
        $out = [
            'clicks_30d'=>0,'conversions_30d'=>0,'income_30d'=>0.0,'cost_30d'=>0.0,'net_30d'=>0.0,
            'active_campaigns'=>0,'active_trackers'=>0,'plan_limit_pct'=>0,'near_plan_limit'=>false,
            'networks'=>[],'traffic_source_types'=>[],'top_geos'=>[],
            'device_mix'=>['mobile'=>0,'desktop'=>0,'tablet'=>0],'offers'=>[],
        ];

        // --- 30d totals from the report cube ---
        $stmt = $db->prepare(
            "SELECT COALESCE(SUM(clicks),0) c, COALESCE(SUM(leads),0) l,
                    COALESCE(SUM(income),0) inc, COALESCE(SUM(cost),0) cost
               FROM `202_dataengine`
              WHERE user_id = ? AND click_time >= ? AND click_time <= ?"
        );
        if ($stmt !== false) {
            $stmt->bind_param('iii', $userId, $since, $now);
            if ($stmt->execute()) {
                $row = $stmt->get_result()->fetch_assoc() ?: [];
                $out['clicks_30d'] = (int) ($row['c'] ?? 0);
                $out['conversions_30d'] = (int) ($row['l'] ?? 0);
                $out['income_30d'] = (float) ($row['inc'] ?? 0);
                $out['cost_30d'] = (float) ($row['cost'] ?? 0);
                $out['net_30d'] = $out['income_30d'] - $out['cost_30d'];
            }
            $stmt->close();
        }

        // --- networks ---
        $out['networks'] = self::col($db,
            "SELECT aff_network_name FROM `202_aff_networks`
              WHERE user_id = ? AND aff_network_deleted = 0", $userId);

        // --- traffic source types ---
        $out['traffic_source_types'] = self::col($db,
            "SELECT ppc_network_name FROM `202_ppc_networks`
              WHERE user_id = ? AND ppc_network_deleted = 0", $userId);

        // --- active campaigns ---
        $out['active_campaigns'] = count(self::col($db,
            "SELECT aff_campaign_id FROM `202_aff_campaigns`
              WHERE user_id = ? AND aff_campaign_deleted = 0", $userId));

        // --- active trackers (tracking links whose campaign is not deleted) ---
        $out['active_trackers'] = count(self::col($db,
            "SELECT t.tracker_id FROM `202_trackers` t
               JOIN `202_aff_campaigns` cmp ON cmp.aff_campaign_id = t.aff_campaign_id
                AND cmp.aff_campaign_deleted = 0
              WHERE t.user_id = ?", $userId));

        // --- top geos (top 5 countries by clicks, 30d) ---
        $out['top_geos'] = self::col($db,
            "SELECT c.country_code
               FROM `202_dataengine` d
               JOIN `202_locations_country` c ON c.country_id = d.country_id
              WHERE d.user_id = ? AND d.click_time >= ?
              GROUP BY c.country_code
              ORDER BY SUM(d.clicks) DESC
              LIMIT 5", $userId, $since);

        // --- device mix (clicks by device_type, 30d; 1=desktop 2=mobile 3=tablet) ---
        $stmt = $db->prepare(
            "SELECT m.device_type, COALESCE(SUM(d.clicks),0) clicks
               FROM `202_dataengine` d
               JOIN `202_device_models` m ON m.device_id = d.device_id
              WHERE d.user_id = ? AND d.click_time >= ?
              GROUP BY m.device_type"
        );
        if ($stmt !== false) {
            $stmt->bind_param('ii', $userId, $since);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $clicks = (int) $r['clicks'];
                    switch ((int) $r['device_type']) {
                        case self::DEVICE_TYPE_DESKTOP: $out['device_mix']['desktop'] = $clicks; break;
                        case self::DEVICE_TYPE_MOBILE:  $out['device_mix']['mobile'] = $clicks; break;
                        case self::DEVICE_TYPE_TABLET:  $out['device_mix']['tablet'] = $clicks; break;
                        // other types (bots) intentionally excluded from the mix
                    }
                }
            }
            $stmt->close();
        }

        // --- offers (full detail, 30d aggregates per campaign) ---
        $sql = "SELECT cmp.aff_campaign_name, cmp.aff_campaign_url, cmp.aff_campaign_payout,
                       cmp.aff_campaign_currency, net.aff_network_name,
                       COALESCE(SUM(d.clicks),0) clicks, COALESCE(SUM(d.income),0) income,
                       COALESCE(SUM(d.leads),0) leads
                  FROM `202_aff_campaigns` cmp
                  LEFT JOIN `202_aff_networks` net ON net.aff_network_id = cmp.aff_network_id
                  LEFT JOIN `202_dataengine` d
                         ON d.aff_campaign_id = cmp.aff_campaign_id AND d.click_time >= ?
                 WHERE cmp.user_id = ? AND cmp.aff_campaign_deleted = 0
                 GROUP BY cmp.aff_campaign_id";
        $stmt = $db->prepare($sql);
        if ($stmt !== false) {
            $stmt->bind_param('ii', $since, $userId);
            if ($stmt->execute()) {
                $res = $stmt->get_result();
                while ($r = $res->fetch_assoc()) {
                    $out['offers'][] = self::buildOfferRow($r);
                }
            }
            $stmt->close();
        }

        // --- LTV account-level aggregates (privacy boundary: aggregates ONLY,
        //     never per-customer rows/names/aliases — see Global Constraints) ---
        $out['ltv'] = ['total_revenue'=>0.0,'mrr'=>0.0,'arr'=>0.0,'customers'=>0,
                       'avg_ltv'=>0.0,'active_subscriptions'=>0,'uses_ltv'=>false];
        try {
            // MysqlLtvRepository::summary() returns customers, total_revenue,
            // avg_ltv, mrr, active_subscriptions; mrr() returns mrr/arr/churn.
            // Constructed the way existing LTV callers do (Connection wrapper,
            // all-time LtvQuery with no time bounds).
            $repo = new \Prosper202\Ltv\MysqlLtvRepository(new \Prosper202\Database\Connection($db));
            $summary = $repo->summary(new \Prosper202\Ltv\LtvQuery($userId));
            $mrr = $repo->mrr($userId);
            $out['ltv'] = [
                'total_revenue'        => (float) ($summary['total_revenue'] ?? 0),
                'mrr'                  => (float) ($mrr['mrr'] ?? 0),
                'arr'                  => (float) ($mrr['arr'] ?? 0),
                'customers'            => (int) ($summary['customers'] ?? 0),
                'avg_ltv'              => (float) ($summary['avg_ltv'] ?? 0),
                'active_subscriptions' => (int) ($summary['active_subscriptions'] ?? 0),
                'uses_ltv'             => ((int) ($summary['customers'] ?? 0)) > 0,
            ];
        } catch (\Throwable $e) {
            error_log('[OfferProfile] ltv aggregates unavailable: ' . $e->getMessage());
        }

        // --- LPO activation flag ---
        $stmt = $db->prepare("SELECT lpo_status, lpo_site_key FROM `202_users_pref` WHERE user_id = ? LIMIT 1");
        $out['lpo_active'] = false;
        if ($stmt !== false) {
            $stmt->bind_param('i', $userId);
            if ($stmt->execute()) {
                $r = $stmt->get_result()->fetch_assoc() ?: [];
                $out['lpo_active'] = (($r['lpo_status'] ?? '') === 'active') && (($r['lpo_site_key'] ?? '') !== '');
            }
            $stmt->close();
        }

        // plan_limit_pct / near_plan_limit: this self-hosted schema has no
        // plan/click-allowance record to compare against, so these stay at
        // their defaults (0 / false) rather than inventing a column. Logged
        // so the defaults aren't mistaken for a computed "0% of limit".
        error_log('[OfferProfile] plan_limit_pct/near_plan_limit left at defaults: no plan allowance source in schema');

        return $out;
    }

    /** @return string[] first column of a (user_id[, since]) query */
    private static function col(mysqli $db, string $sql, int $userId, ?int $since = null): array
    {
        $stmt = $db->prepare($sql);
        if ($stmt === false) { return []; }
        if ($since === null) { $stmt->bind_param('i', $userId); }
        else { $stmt->bind_param('ii', $userId, $since); }
        if (!$stmt->execute()) { $stmt->close(); return []; }
        $res = $stmt->get_result();
        $vals = [];
        while ($row = $res->fetch_row()) { $vals[] = (string) $row[0]; }
        $stmt->close();
        return $vals;
    }
}
