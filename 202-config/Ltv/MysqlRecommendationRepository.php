<?php

declare(strict_types=1);

namespace Prosper202\Ltv;

use Prosper202\Database\Connection;

/**
 * Deterministic next-offer recommendation over the account's offer-transition
 * statistics ("customers who converted on A later converted on B"), which
 * ltv_maintenance rebuilds from the revenue ledger.
 *
 * Scoring model (v2 — no randomness, no black box; every suggestion carries
 * its inputs in a `why` block):
 *
 *   score(B) = Σ over the customer's last SOURCE_CAMPAIGNS converted
 *              campaigns A_i, weighted by recency w_i:
 *                w_i × wilsonLB(k_i, n_i) × min(lift_i, LIFT_CAP) × decay_i
 *
 *   k_i    — adjacency-weighted transitions A_i→B: immediate next-campaign
 *            conversions count fully, eventual ones at EVENTUAL_WEIGHT.
 *   n_i    — customers who converted on A_i at all, so k/n is transition
 *            CONFIDENCE, not raw volume.
 *   wilson — 95% Wilson lower bound shrinks small samples toward zero: one
 *            lucky customer cannot beat a consistent pattern (this replaces
 *            a brittle hard support threshold).
 *   lift   — confidence divided by B's base conversion rate across all
 *            converting customers, capped: "everyone buys B anyway" stops
 *            masquerading as a transition signal.
 *   decay  — 0.5^(age/1y) on the pair's most recent occurrence, so a funnel
 *            that stopped working fades instead of ruling forever.
 *
 * Candidates are ranked by expected value (score × B's average first-order
 * revenue) when revenue data exists, by score alone otherwise; ties break on
 * campaign_id. Fallback for customers the model cannot place stays the
 * account's top-converting campaign, minus what they already bought.
 */
final class MysqlRecommendationRepository
{
    /** How many of the customer's most recent campaigns feed the blend. */
    private const SOURCE_CAMPAIGNS = 3;

    /** Recency weights for those sources, latest first. */
    private const SOURCE_WEIGHTS = [1.0, 0.6, 0.36];

    /** An eventual (non-adjacent) transition counts this much of a direct one. */
    private const EVENTUAL_WEIGHT = 0.5;

    /** Popularity correction is capped so rare-base-rate noise can't explode. */
    private const LIFT_CAP = 3.0;

    /** Transition staleness half-life, seconds (1 year). */
    private const DECAY_HALF_LIFE = 31536000;

    public function __construct(private Connection $conn)
    {
    }

    /**
     * @return array{campaign_id: int, name: string, url: string, why?: array<string, mixed>}|null
     */
    public function nextOffer(int $userId, int $customerId, ?int $now = null): ?array
    {
        $now = $now ?? time();
        $converted = $this->convertedCampaigns($userId, $customerId);

        // 1. Blend transition evidence from the last few converted campaigns.
        if ($converted !== []) {
            $candidate = $this->bestTransitionTarget($userId, $converted, $now);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        // 2. Fallback: the account's top-converting campaign the customer
        //    hasn't bought yet.
        return $this->topAccountCampaign($userId, $converted);
    }

    /**
     * Rebuild the transition statistics for one account from the ledger.
     * Called by ltv_maintenance; DELETE + re-INSERT inside a transaction so
     * readers never see a half-built table for the account.
     */
    public function rebuildTransitions(int $userId, int $now): int
    {
        return $this->conn->transaction(function () use ($userId, $now): int {
            $del = $this->conn->prepareWrite('DELETE FROM 202_offer_transitions WHERE user_id = ?');
            $this->conn->bind($del, 'i', [$userId]);
            $this->conn->executeUpdate($del);

            // firsts = each customer's first conversion per campaign (order
            // events only: positive money, conversion/import source, and
            // never soft-deleted conversions). A pair (A→B) exists when B's
            // first conversion follows A's; it is ADJACENT when no other
            // campaign's first conversion sits between the two.
            $ins = $this->conn->prepareWrite(
                "INSERT INTO 202_offer_transitions
                    (user_id, from_campaign_id, to_campaign_id,
                     transition_count, adjacent_count, from_customers, last_seen_at, updated_at)
                 WITH firsts AS (
                     SELECT re.customer_id, cl.campaign_id, MIN(re.occurred_at) AS first_at
                     FROM 202_revenue_events re
                     JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id AND cl.deleted = 0
                     WHERE re.user_id = ? AND re.source IN ('conversion','import')
                       AND re.event_type IN ('purchase','one_time') AND re.amount >= 0
                     GROUP BY re.customer_id, cl.campaign_id
                 )
                 SELECT ?, a.campaign_id, b.campaign_id,
                        COUNT(DISTINCT a.customer_id),
                        COUNT(DISTINCT CASE WHEN NOT EXISTS (
                            SELECT 1 FROM firsts m
                            WHERE m.customer_id = a.customer_id
                              AND m.campaign_id <> a.campaign_id AND m.campaign_id <> b.campaign_id
                              AND m.first_at > a.first_at AND m.first_at < b.first_at
                        ) THEN a.customer_id END),
                        t.from_total,
                        MAX(b.first_at),
                        ?
                 FROM firsts a
                 JOIN firsts b ON b.customer_id = a.customer_id
                    AND b.campaign_id <> a.campaign_id
                    AND b.first_at > a.first_at
                 JOIN (
                     SELECT campaign_id, COUNT(*) AS from_total FROM firsts GROUP BY campaign_id
                 ) t ON t.campaign_id = a.campaign_id
                 GROUP BY a.campaign_id, b.campaign_id, t.from_total"
            );
            $this->conn->bind($ins, 'iii', [$userId, $userId, $now]);
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
             JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id AND cl.deleted = 0
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
     * Score every transition target reachable from the customer's recent
     * campaigns and return the best, with its full scoring breakdown.
     *
     * @param list<int> $converted All converted campaigns, latest first.
     * @return array{campaign_id: int, name: string, url: string, why: array<string, mixed>}|null
     */
    private function bestTransitionTarget(int $userId, array $converted, int $now): ?array
    {
        $sources = array_slice($converted, 0, self::SOURCE_CAMPAIGNS);
        $sourceWeights = [];
        foreach ($sources as $i => $campaignId) {
            $sourceWeights[$campaignId] = self::SOURCE_WEIGHTS[$i];
        }

        [$notIn, $notInTypes, $notInBinds] = $this->excludeClause($converted, 'ot.to_campaign_id');
        $sourcePlaceholders = implode(', ', array_fill(0, count($sources), '?'));

        $stmt = $this->conn->prepareRead(
            "SELECT ot.from_campaign_id, ot.to_campaign_id, ot.transition_count, ot.adjacent_count,
                    ot.from_customers, ot.last_seen_at,
                    ac.aff_campaign_name AS name, ac.aff_campaign_url AS url
             FROM 202_offer_transitions ot
             JOIN 202_aff_campaigns ac ON ac.aff_campaign_id = ot.to_campaign_id
             WHERE ot.user_id = ? AND ot.from_campaign_id IN ({$sourcePlaceholders}) {$notIn}"
        );
        $this->conn->bind(
            $stmt,
            'i' . str_repeat('i', count($sources)) . $notInTypes,
            array_merge([$userId], $sources, $notInBinds)
        );
        $rows = $this->conn->fetchAll($stmt);
        if ($rows === []) {
            return null;
        }

        $candidateIds = array_values(array_unique(array_map(
            static fn (array $r): int => (int) $r['to_campaign_id'],
            $rows
        )));
        [$baseRates, $avgValues] = $this->campaignBaselines($userId, $candidateIds);

        // Aggregate per candidate.
        $candidates = [];
        foreach ($rows as $row) {
            $to = (int) $row['to_campaign_id'];
            $from = (int) $row['from_campaign_id'];
            $n = max(1, (int) $row['from_customers']);
            $adjacent = (int) $row['adjacent_count'];
            $eventual = max(0, (int) $row['transition_count'] - $adjacent);
            $k = min((float) $n, $adjacent + self::EVENTUAL_WEIGHT * $eventual);

            // Scored with one pseudo-failure added (skeptical prior): a
            // perfect 1-of-1 would otherwise outrank a consistent 6-of-20
            // on the raw Wilson bound — one lucky customer is not a funnel.
            $confidence = self::wilsonLowerBound($k, $n + 1);
            $base = $baseRates[$to] ?? 0.0;
            $lift = $base > 0 ? min(($k / $n) / $base, self::LIFT_CAP) : 1.0;
            $ageSeconds = max(0, $now - (int) $row['last_seen_at']);
            $decay = 0.5 ** ($ageSeconds / self::DECAY_HALF_LIFE);

            $contribution = ($sourceWeights[$from] ?? 0.0) * $confidence * $lift * $decay;

            if (!isset($candidates[$to])) {
                $candidates[$to] = [
                    'campaign_id' => $to,
                    'name' => (string) ($row['name'] ?? ''),
                    'url' => (string) ($row['url'] ?? ''),
                    'score' => 0.0,
                    'direct' => 0,
                    'eventual' => 0,
                    'based_on' => [],
                ];
            }
            $candidates[$to]['score'] += $contribution;
            $candidates[$to]['direct'] += $adjacent;
            $candidates[$to]['eventual'] += $eventual;
            $candidates[$to]['based_on'][] = $from;
        }

        // Rank by expected value when any candidate carries revenue data,
        // otherwise by propensity alone; campaign_id breaks ties so the
        // result is stable run to run.
        $hasValue = false;
        foreach ($candidates as $to => &$candidate) {
            $avgValue = $avgValues[$to] ?? 0.0;
            $candidate['avg_value'] = $avgValue;
            $candidate['expected_value'] = $candidate['score'] * $avgValue;
            $hasValue = $hasValue || $avgValue > 0;
        }
        unset($candidate);

        $ranked = array_values($candidates);
        usort($ranked, static function (array $x, array $y) use ($hasValue): int {
            $primary = $hasValue
                ? ($y['expected_value'] <=> $x['expected_value'])
                : ($y['score'] <=> $x['score']);

            return $primary !== 0 ? $primary : ($x['campaign_id'] <=> $y['campaign_id']);
        });

        $best = $ranked[0];
        if ($best['score'] <= 0.0) {
            return null;
        }

        return $this->hydrate([
            'campaign_id' => $best['campaign_id'],
            'name' => $best['name'],
            'url' => $best['url'],
        ], [
            'basis' => 'transition',
            'ranked_by' => $hasValue ? 'expected_value' : 'propensity',
            'score' => round($best['score'], 4),
            'direct_transitions' => $best['direct'],
            'eventual_transitions' => $best['eventual'],
            'based_on_campaigns' => array_values(array_unique($best['based_on'])),
            'avg_order_value' => round((float) $best['avg_value'], 2),
            'expected_value' => round((float) $best['expected_value'], 4),
        ]);
    }

    /**
     * Base conversion rate and average first-order revenue for the candidate
     * campaigns, in one scoped query: base rate corrects for "everyone buys
     * B anyway" popularity, average revenue turns propensity into expected
     * value.
     *
     * @param list<int> $campaignIds
     * @return array{0: array<int, float>, 1: array<int, float>} [baseRates, avgValues]
     */
    private function campaignBaselines(int $userId, array $campaignIds): array
    {
        if ($campaignIds === []) {
            return [[], []];
        }
        $placeholders = implode(', ', array_fill(0, count($campaignIds), '?'));

        $totalStmt = $this->conn->prepareRead(
            "SELECT COUNT(DISTINCT re.customer_id) AS total_buyers
             FROM 202_revenue_events re
             JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id AND cl.deleted = 0
             WHERE re.user_id = ? AND re.source IN ('conversion','import')
               AND re.event_type IN ('purchase','one_time') AND re.amount >= 0"
        );
        $this->conn->bind($totalStmt, 'i', [$userId]);
        $totalBuyers = (int) (($this->conn->fetchOne($totalStmt)['total_buyers'] ?? 0));

        $stmt = $this->conn->prepareRead(
            "SELECT cl.campaign_id,
                    COUNT(DISTINCT re.customer_id) AS buyers,
                    AVG(CASE WHEN re.amount > 0 THEN re.amount END) AS avg_value
             FROM 202_revenue_events re
             JOIN 202_conversion_logs cl ON cl.conv_id = re.conv_id AND cl.deleted = 0
             WHERE re.user_id = ? AND re.source IN ('conversion','import')
               AND re.event_type IN ('purchase','one_time') AND re.amount >= 0
               AND cl.campaign_id IN ({$placeholders})
             GROUP BY cl.campaign_id"
        );
        $this->conn->bind($stmt, 'i' . str_repeat('i', count($campaignIds)), array_merge([$userId], $campaignIds));

        $baseRates = [];
        $avgValues = [];
        foreach ($this->conn->fetchAll($stmt) as $row) {
            $campaignId = (int) $row['campaign_id'];
            $baseRates[$campaignId] = $totalBuyers > 0 ? ((int) $row['buyers']) / $totalBuyers : 0.0;
            $avgValues[$campaignId] = $row['avg_value'] !== null ? (float) $row['avg_value'] : 0.0;
        }

        return [$baseRates, $avgValues];
    }

    /**
     * 95% Wilson score lower bound on a proportion of k successes in n
     * trials: shrinks small samples toward zero so one lucky transition
     * cannot outrank a consistent pattern. Deterministic and standard.
     */
    public static function wilsonLowerBound(float $k, int $n): float
    {
        if ($n <= 0) {
            return 0.0;
        }
        $z = 1.96;
        $p = max(0.0, min(1.0, $k / $n));
        $z2 = $z * $z;
        $denominator = 1 + $z2 / $n;
        $centre = $p + $z2 / (2 * $n);
        $margin = $z * sqrt(($p * (1 - $p) + $z2 / (4 * $n)) / $n);

        return max(0.0, ($centre - $margin) / $denominator);
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

        return $this->hydrate($row, $row !== null ? ['basis' => 'account_top'] : null);
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
     * @param array<string, mixed>|null $why Scoring breakdown to attach.
     * @return array{campaign_id: int, name: string, url: string, why?: array<string, mixed>}|null
     */
    private function hydrate(?array $row, ?array $why = null): ?array
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

        $offer = [
            'campaign_id' => (int) $row['campaign_id'],
            'name' => trim((string) ($row['name'] ?? '')),
            'url' => $url,
        ];
        if ($why !== null) {
            $offer['why'] = $why;
        }

        return $offer;
    }
}
