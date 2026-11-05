<?php

declare(strict_types=1);

namespace Prosper202\Rotator;

use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * What the visitor's own link carried, as the landing page recorded it on
 * their click: c1-c4, the utm values, the keyword and the referer. offrtr.php
 * is reached by the landing page's ?rpi= link, which carries none of them, so
 * its c1-c4, utm, keyword and referer rules match on these (R7FX).
 */
final class RecordedLink
{
    private const PARAMS = ['c1', 'c2', 'c3', 'c4', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'];

    /**
     * The click's link values, or null when the account has no such click.
     *
     * @return array{query: array<string, string>, keyword: string, referer: string}|null
     */
    public static function read(Connection $conn, int $clickId, int $userId): ?array
    {
        $stmt = $conn->prepareWrite(
            'SELECT tc1.c1, tc2.c2, tc3.c3, tc4.c4, kw.keyword,'
            . ' us.utm_source, um.utm_medium, uca.utm_campaign, ut.utm_term, uco.utm_content,'
            . ' su.site_url_address AS referer'
            . ' FROM ' . LatestVisit::of('?') . ' AS c'
            . ' LEFT JOIN 202_clicks_advance AS ca ON ca.click_id = c.click_id'
            . ' LEFT JOIN 202_keywords AS kw ON kw.keyword_id = ca.keyword_id'
            . ' LEFT JOIN 202_clicks_tracking AS ct ON ct.click_id = c.click_id'
            . ' LEFT JOIN 202_tracking_c1 AS tc1 ON tc1.c1_id = ct.c1_id'
            . ' LEFT JOIN 202_tracking_c2 AS tc2 ON tc2.c2_id = ct.c2_id'
            . ' LEFT JOIN 202_tracking_c3 AS tc3 ON tc3.c3_id = ct.c3_id'
            . ' LEFT JOIN 202_tracking_c4 AS tc4 ON tc4.c4_id = ct.c4_id'
            . ' LEFT JOIN 202_google AS g ON g.click_id = c.click_id'
            . ' LEFT JOIN 202_utm_source AS us ON us.utm_source_id = g.utm_source_id'
            . ' LEFT JOIN 202_utm_medium AS um ON um.utm_medium_id = g.utm_medium_id'
            . ' LEFT JOIN 202_utm_campaign AS uca ON uca.utm_campaign_id = g.utm_campaign_id'
            . ' LEFT JOIN 202_utm_term AS ut ON ut.utm_term_id = g.utm_term_id'
            . ' LEFT JOIN 202_utm_content AS uco ON uco.utm_content_id = g.utm_content_id'
            . ' LEFT JOIN 202_clicks_site AS cs ON cs.click_id = c.click_id'
            . ' LEFT JOIN 202_site_urls AS su ON su.site_url_id = cs.click_referer_site_url_id'
            . ' WHERE c.user_id = ? LIMIT 1'
        );
        $conn->bind($stmt, 'ii', [$clickId, $userId]);
        $row = $conn->fetchOne($stmt);
        if ($row === null) {
            return null;
        }

        $query = [];
        foreach (self::PARAMS as $name) {
            $query[$name] = (string) ($row[$name] ?? '');
        }

        return [
            'query' => $query,
            'keyword' => (string) ($row['keyword'] ?? ''),
            'referer' => (string) ($row['referer'] ?? ''),
        ];
    }
}
