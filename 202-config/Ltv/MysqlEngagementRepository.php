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
     * Record a manually instrumented engagement event ("pricing_viewed",
     * "demo_requested", ...). Event names are normalized to a strict slug —
     * an invalid name throws, it is never silently mangled into something
     * else (CLAUDE.md #4).
     *
     * @return int engagement_id
     */
    public function recordEvent(
        int $userId,
        int $customerId,
        string $eventName,
        string $source = 'api',
        ?int $clickId = null,
        ?int $occurredAt = null,
        ?float $eventValue = null
    ): int {
        $eventName = self::normalizeEventName($eventName);
        if (!in_array($source, ['api', 'site'], true)) {
            throw new \RuntimeException('event source must be api or site');
        }
        if ($eventValue !== null) {
            // Depth metrics: seconds on page, scroll/video percentages.
            // Client-supplied, so clamp defensively to the column's range.
            $eventValue = max(0.0, min(999999999.999, $eventValue));
        }
        $now = time();
        $occurredAt = $occurredAt !== null && $occurredAt > 0 ? $occurredAt : $now;

        $stmt = $this->conn->prepareWrite(
            'INSERT INTO 202_engagement_events
                (user_id, customer_id, event_name, source, click_id, event_value, occurred_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $this->conn->bind($stmt, 'iissidii', [
            $userId,
            $customerId,
            $eventName,
            $source,
            $clickId !== null && $clickId > 0 ? $clickId : null,
            $eventValue,
            $occurredAt,
            $now,
        ]);
        $eventId = $this->conn->executeInsert($stmt);

        // Engagement recency: manual events count as activity too.
        $touch = $this->conn->prepareWrite(
            'UPDATE 202_customers SET last_activity_time = GREATEST(last_activity_time, ?), updated_at = ?
             WHERE customer_id = ? AND user_id = ?'
        );
        $this->conn->bind($touch, 'iiii', [$occurredAt, $now, $customerId, $userId]);
        $this->conn->executeUpdate($touch);

        return $eventId;
    }

    /**
     * Normalize an event name to a strict lowercase slug (a-z, 0-9, _ . -),
     * 1-64 chars. Throws on anything that does not survive normalization
     * intact enough to be meaningful.
     */
    public static function normalizeEventName(string $eventName): string
    {
        $normalized = strtolower(trim($eventName));
        $normalized = (string) preg_replace('/\s+/', '_', $normalized);
        if ($normalized === '' || strlen($normalized) > 64
            || preg_match('/^[a-z0-9_.\-]+$/', $normalized) !== 1) {
            throw new \RuntimeException(
                'Invalid event name; use 1-64 chars of a-z, 0-9, underscore, dot or dash'
            );
        }

        return $normalized;
    }

    /**
     * A customer's recent manually instrumented events, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function customerEvents(int $userId, int $customerId, int $days = 90, int $limit = 50): array
    {
        $since = time() - max(1, $days) * 86400;

        $stmt = $this->conn->prepareRead(
            'SELECT event_name, source, event_value, occurred_at, click_id
             FROM 202_engagement_events
             WHERE user_id = ? AND customer_id = ? AND occurred_at >= ?
             ORDER BY occurred_at DESC, engagement_id DESC
             LIMIT ?'
        );
        $this->conn->bind($stmt, 'iiii', [$userId, $customerId, $since, $limit]);

        return $this->conn->fetchAll($stmt);
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
     * contacts, engagement volume/recency in the window (tracked-link clicks
     * PLUS manually instrumented events), revenue, MRR, each account's
     * most-browsed campaign, and its most frequent custom event.
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
                    COALESCE(eng.clicks, 0) + COALESCE(ev.events, 0) AS engagements,
                    COALESCE(ev.events, 0) AS custom_events,
                    eng.top_campaign_name,
                    ev.top_event_name,
                    COALESCE(ev.avg_time_on_page, 0) AS avg_time_on_page,
                    COALESCE(ev.avg_scroll_depth, 0) AS avg_scroll_depth,
                    COALESCE(ev.avg_video_pct, 0) AS avg_video_pct
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
             LEFT JOIN (
                 SELECT cu3.company AS company, COUNT(*) AS events,
                        SUBSTRING_INDEX(GROUP_CONCAT(ee.event_name ORDER BY ee.occurred_at DESC SEPARATOR ','), ',', 1) AS top_event_name,
                        AVG(CASE WHEN ee.event_name = 'time_on_page' THEN ee.event_value END) AS avg_time_on_page,
                        AVG(CASE WHEN ee.event_name = 'scroll_depth' THEN ee.event_value END) AS avg_scroll_depth,
                        AVG(CASE WHEN ee.event_name = 'video_viewed' THEN ee.event_value END) AS avg_video_pct
                 FROM 202_customers cu3
                 JOIN 202_engagement_events ee ON ee.customer_id = cu3.customer_id
                    AND ee.user_id = cu3.user_id AND ee.occurred_at >= ?
                 WHERE cu3.user_id = ? AND cu3.company IS NOT NULL AND cu3.company <> ''
                   AND cu3.merged_into_customer_id IS NULL
                 GROUP BY cu3.company
             ) ev ON ev.company = cu.company
             WHERE cu.user_id = ? AND cu.company IS NOT NULL AND cu.company <> ''
               AND cu.merged_into_customer_id IS NULL
             GROUP BY cu.company, eng.clicks, eng.top_campaign_name, ev.events, ev.top_event_name,
                      ev.avg_time_on_page, ev.avg_scroll_depth, ev.avg_video_pct
             ORDER BY engagements DESC, total_revenue DESC
             LIMIT ? OFFSET ?"
        );
        $this->conn->bind($stmt, 'iiiiiii', [$since, $userId, $since, $userId, $userId, $limit, $offset]);

        $rows = $this->conn->fetchAll($stmt);
        $now = time();
        foreach ($rows as &$row) {
            $row['engagement_score'] = self::engagementScore($row, $now);
        }
        unset($row);

        return $rows;
    }

    /**
     * Deterministic engagement score, 0-100, computed from a window's
     * aggregates. Explainable by construction:
     *   volume:  up to 40 pts — 4 pts per engagement (clicks+events) per contact
     *   time:    up to 20 pts — avg visible time on page vs a 5-minute ceiling
     *   scroll:  up to 15 pts — avg scroll depth percentage
     *   video:   up to 15 pts — avg video completion percentage
     *   recency: 10 pts within 7 days, 5 within 30, else 0
     *
     * @param array<string, mixed> $aggregates keys: engagements, contacts
     *        (default 1), avg_time_on_page, avg_scroll_depth, avg_video_pct,
     *        last_activity
     */
    public static function engagementScore(array $aggregates, ?int $now = null): int
    {
        $now = $now ?? time();
        $contacts = max(1, (int) ($aggregates['contacts'] ?? 1));
        $engagements = (float) ($aggregates['engagements'] ?? 0);
        $avgTime = (float) ($aggregates['avg_time_on_page'] ?? 0);
        $avgScroll = (float) ($aggregates['avg_scroll_depth'] ?? 0);
        $avgVideo = (float) ($aggregates['avg_video_pct'] ?? 0);
        $lastActivity = (int) ($aggregates['last_activity'] ?? 0);

        $score = min(40.0, ($engagements / $contacts) * 4.0)
            + min(20.0, ($avgTime / 300.0) * 20.0)
            + min(15.0, ($avgScroll / 100.0) * 15.0)
            + min(15.0, ($avgVideo / 100.0) * 15.0);

        $age = $now - $lastActivity;
        if ($lastActivity > 0 && $age <= 7 * 86400) {
            $score += 10.0;
        } elseif ($lastActivity > 0 && $age <= 30 * 86400) {
            $score += 5.0;
        }

        return (int) round(min(100.0, max(0.0, $score)));
    }

    /**
     * One customer's depth aggregates for scoring: engagement volume plus
     * averages of the auto-instrumented metrics in the window.
     *
     * @return array<string, mixed>
     */
    public function customerEngagementAggregates(int $userId, int $customerId, int $days = 90): array
    {
        $since = time() - max(1, $days) * 86400;

        $clickStmt = $this->conn->prepareRead(
            'SELECT COUNT(*) AS clicks, MAX(c.click_time) AS last_click
             FROM 202_clicks_tracking ct
             JOIN 202_clicks c ON c.click_id = ct.click_id
             WHERE ct.customer_id = ? AND c.user_id = ? AND c.click_time >= ?'
        );
        $this->conn->bind($clickStmt, 'iii', [$customerId, $userId, $since]);
        $clicks = $this->conn->fetchOne($clickStmt) ?? [];

        $eventStmt = $this->conn->prepareRead(
            "SELECT COUNT(*) AS events, MAX(occurred_at) AS last_event,
                    AVG(CASE WHEN event_name = 'time_on_page' THEN event_value END) AS avg_time_on_page,
                    AVG(CASE WHEN event_name = 'scroll_depth' THEN event_value END) AS avg_scroll_depth,
                    AVG(CASE WHEN event_name = 'video_viewed' THEN event_value END) AS avg_video_pct
             FROM 202_engagement_events
             WHERE user_id = ? AND customer_id = ? AND occurred_at >= ?"
        );
        $this->conn->bind($eventStmt, 'iii', [$userId, $customerId, $since]);
        $events = $this->conn->fetchOne($eventStmt) ?? [];

        return [
            'contacts' => 1,
            'engagements' => (int) ($clicks['clicks'] ?? 0) + (int) ($events['events'] ?? 0),
            'avg_time_on_page' => (float) ($events['avg_time_on_page'] ?? 0),
            'avg_scroll_depth' => (float) ($events['avg_scroll_depth'] ?? 0),
            'avg_video_pct' => (float) ($events['avg_video_pct'] ?? 0),
            'last_activity' => max((int) ($clicks['last_click'] ?? 0), (int) ($events['last_event'] ?? 0)),
        ];
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
