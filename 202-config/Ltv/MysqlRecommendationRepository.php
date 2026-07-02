<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;

/**
 * Deterministic next-offer recommendation, driven by the account's
 * offer-transition counts ("customers who converted on A later converted on
 * B") which ltv_maintenance rebuilds from the revenue ledger.
 *
 * Selection: top transition target from the customer's most recent converted
 * campaign, excluding campaigns the customer already converted on; falls back
 * to the account's overall top-converting campaign under the same exclusion;
 * null when nothing qualifies. No randomness, no black box — every suggestion
 * is explainable from the transition table.
 */
final class MysqlRecommendationRepository
{
    public function __construct(private Connection $conn)
    {
    }

    /**
     * @return array{campaign_id: int, name: string, url: string}|null
     */
    public function nextOffer(int $userId, int $customerId): ?array
    {
        $converted = $this->convertedCampaigns($userId, $customerId);

        // 1. Transition from the most recent converted campaign.
        if ($converted !== []) {
            $lastCampaignId = $converted[0]; // convertedCampaigns() orders latest-first
            $candidate = $this->topTransitionTarget($userId, $lastCampaignId, $converted);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        // 2. Fallback: the account's top-converting campaign the customer
        //    hasn't bought yet.
        return $this->topAccountCampaign($userId, $converted);
    }

    /**
     * Rebuild the transition counts for one account from the ledger. Called
     * by ltv_maintenance; DELETE + re-INSERT inside a transaction so readers
     * never see a half-built table for the account.
     */
    public function rebuildTransitions(int $userId, int $now): int
    {
        return $this->conn->transaction(function () use ($userId, $now): int {
            $del = $this->conn->prepareWrite('DELETE FROM 202_offer_transitions WHERE user_id = ?');
            $this->conn->bind($del, 'i', [$userId]);
            $this->conn->executeUpdate($del);

            // Ordered pairs of DISTINCT campaigns per customer: for every
            // customer, each campaign conversion that happened before a
            // conversion on a different campaign contributes one (from, to)
            // pair. Campaign comes from the conversion the ledger event points
            // at; order events only (positive money, conversion/import source).
            $ins = $this->conn->prepareWrite(
                "INSERT INTO 202_offer_transitions (user_id, from_campaign_id, to_campaign_id, transition_count, updated_at)
                 SELECT ?, a.campaign_id, b.campaign_id, COUNT(DISTINCT a.customer_id), ?
                 FROM (
                     SELECT re.customer_id, cl.campaign_id, MIN(re.occurred_at) AS first_at
                     FROM 202_revenue_events re
                     JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id
                     WHERE re.user_id = ? AND re.source IN ('conversion','import')
                       AND re.event_type IN ('purchase','one_time') AND re.amount >= 0
                     GROUP BY re.customer_id, cl.campaign_id
                 ) a
                 JOIN (
                     SELECT re.customer_id, cl.campaign_id, MIN(re.occurred_at) AS first_at
                     FROM 202_revenue_events re
                     JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id
                     WHERE re.user_id = ? AND re.source IN ('conversion','import')
                       AND re.event_type IN ('purchase','one_time') AND re.amount >= 0
                     GROUP BY re.customer_id, cl.campaign_id
                 ) b ON b.customer_id = a.customer_id
                    AND b.campaign_id <> a.campaign_id
                    AND b.first_at > a.first_at
                 GROUP BY a.campaign_id, b.campaign_id"
            );
            $this->conn->bind($ins, 'iiii', [$userId, $now, $userId, $userId]);
            $this->conn->execute($ins);
            try {
                $inserted = (int) $ins->affected_rows;
            } catch (\Error) {
                $inserted = 0;
            }
            $ins->close();

            return $inserted;
        });
    }

    /**
     * Campaign ids this customer converted on, most recent first.
     *
     * @return list<int>
     */
    private function convertedCampaigns(int $userId, int $customerId): array
    {
        $stmt = $this->conn->prepareRead(
            "SELECT cl.campaign_id, MAX(re.occurred_at) AS last_at
             FROM 202_revenue_events re
             JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id
             WHERE re.user_id = ? AND re.customer_id = ?
               AND re.source IN ('conversion','import')
               AND re.event_type IN ('purchase','one_time') AND re.amount >= 0
             GROUP BY cl.campaign_id
             ORDER BY last_at DESC"
        );
        $this->conn->bind($stmt, 'ii', [$userId, $customerId]);

        $campaigns = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $campaigns[] = (int) $row['campaign_id'];
        }

        return $campaigns;
    }

    /**
     * @param list<int> $exclude
     * @return array{campaign_id: int, name: string, url: string}|null
     */
    private function topTransitionTarget(int $userId, int $fromCampaignId, array $exclude): ?array
    {
        [$notIn, $types, $binds] = $this->excludeClause($exclude, 'ot.to_campaign_id');

        $stmt = $this->conn->prepareRead(
            "SELECT ot.to_campaign_id AS campaign_id, ac.aff_campaign_name AS name, ac.aff_campaign_url AS url
             FROM 202_offer_transitions ot
             JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = ot.to_campaign_id
             WHERE ot.user_id = ? AND ot.from_campaign_id = ? {$notIn}
             ORDER BY ot.transition_count DESC, ot.to_campaign_id ASC
             LIMIT 1"
        );
        $this->conn->bind($stmt, 'ii' . $types, array_merge([$userId, $fromCampaignId], $binds));
        $row = $this->conn->fetchOne($stmt);

        return $this->hydrate($row);
    }

    /**
     * @param list<int> $exclude
     * @return array{campaign_id: int, name: string, url: string}|null
     */
    private function topAccountCampaign(int $userId, array $exclude): ?array
    {
        [$notIn, $types, $binds] = $this->excludeClause($exclude, 'cl.campaign_id');

        $stmt = $this->conn->prepareRead(
            "SELECT cl.campaign_id, ac.aff_campaign_name AS name, ac.aff_campaign_url AS url
             FROM 202_conversion_logs cl
             JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = cl.campaign_id
             WHERE cl.user_id = ? AND cl.deleted = 0 {$notIn}
             GROUP BY cl.campaign_id, ac.aff_campaign_name, ac.aff_campaign_url
             ORDER BY COUNT(*) DESC, cl.campaign_id ASC
             LIMIT 1"
        );
        $this->conn->bind($stmt, 'i' . $types, array_merge([$userId], $binds));
        $row = $this->conn->fetchOne($stmt);

        return $this->hydrate($row);
    }

    /**
     * @param list<int> $exclude
     * @return array{0: string, 1: string, 2: list<int>}
     */
    private function excludeClause(array $exclude, string $column): array
    {
        if ($exclude === []) {
            return ['', '', []];
        }
        $placeholders = implode(', ', array_fill(0, count($exclude), '?'));

        return [
            " AND {$column} NOT IN ({$placeholders})",
            str_repeat('i', count($exclude)),
            array_values($exclude),
        ];
    }

    /**
     * @param array<string, mixed>|null $row
     * @return array{campaign_id: int, name: string, url: string}|null
     */
    private function hydrate(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $url = trim((string) ($row['url'] ?? ''));
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            // Only owner-configured http(s) campaign URLs may reach a landing
            // page href (defense in depth alongside the client-side check).
            $url = '';
        }

        return [
            'campaign_id' => (int) $row['campaign_id'],
            'name' => trim((string) ($row['name'] ?? '')),
            'url' => $url,
        ];
    }
}
