<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlEngagementRepository;
use Prosper202\Ltv\MysqlPersonalizationRepository;
use Prosper202\Ltv\MysqlRecommendationRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * Next-offer recommendation and ABM engagement semantics with the shared
 * fake connection.
 */
final class RecommendationTest extends TestCase
{
    private const NOW = 1700000000;

    /**
     * Wire a read fake with a conversion history, transition rows, and
     * campaign baselines (buyers / avg first-order value).
     *
     * @param list<array<string, mixed>> $transitions
     * @param list<array<string, mixed>> $baselines
     */
    private function readFake(array $transitions, array $baselines, int $totalBuyers = 100): FakeMysqliConnection
    {
        $read = new FakeMysqliConnection();
        // Customer converted on campaign 10 (latest) and 5. Needles are
        // unique per query (first matching needle wins in the fake).
        $read->whenQueryContainsReturnRows('ORDER BY last_at DESC', [
            ['campaign_id' => 10, 'last_at' => 1700000200],
            ['campaign_id' => 5, 'last_at' => 1700000100],
        ]);
        $read->whenQueryContainsReturnRows('FROM 202_offer_transitions', $transitions);
        $read->whenQueryContainsReturnRows('AS total_buyers', [['total_buyers' => $totalBuyers]]);
        $read->whenQueryContainsReturnRows('AS avg_value', $baselines);

        return $read;
    }

    /** @return array<string, mixed> */
    private function transitionRow(int $to, string $name, int $transitions, int $adjacent, int $fromCustomers, ?int $lastSeen = null): array
    {
        return [
            'from_campaign_id' => 10, 'to_campaign_id' => $to,
            'transition_count' => $transitions, 'adjacent_count' => $adjacent,
            'from_customers' => $fromCustomers, 'last_seen_at' => $lastSeen ?? self::NOW,
            'name' => $name, 'url' => 'https://example.com/' . $to,
        ];
    }

    public function testNextOfferCorrectsForPopularityWithLift(): void
    {
        // Campaign 22 has more raw transitions (10 of 100) but 80% of ALL
        // buyers convert on it anyway — no real association. Campaign 23 has
        // fewer transitions (6 of 100) but 6x its base rate. Raw-count
        // ranking picks 22; lift-corrected confidence must pick 23.
        $read = $this->readFake(
            [
                $this->transitionRow(22, 'Popular Anyway', 10, 10, 100),
                $this->transitionRow(23, 'Real Follow-on', 6, 6, 100),
            ],
            [
                ['campaign_id' => 22, 'buyers' => 80, 'avg_value' => null],
                ['campaign_id' => 23, 'buyers' => 6, 'avg_value' => null],
            ]
        );

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, self::NOW);

        self::assertNotNull($offer);
        self::assertSame(23, $offer['campaign_id'], 'lift must beat raw popularity');
        self::assertSame('transition', $offer['why']['basis']);
        self::assertSame('propensity', $offer['why']['ranked_by'], 'no revenue data -> propensity ranking');
        self::assertSame(6, $offer['why']['direct_transitions']);
        self::assertSame([10], $offer['why']['based_on_campaigns']);

        // The transition lookup must exclude campaigns already converted on.
        $lookups = $read->statementsContaining('FROM 202_offer_transitions');
        self::assertCount(1, $lookups);
        self::assertStringContainsString('NOT IN', $lookups[0]->sql);
        self::assertContains(10, $lookups[0]->boundValues);
        self::assertContains(5, $lookups[0]->boundValues);
    }

    public function testNextOfferPrefersDirectFollowOnsOverEventualOnes(): void
    {
        // Same volume, same base rates: 22's transitions were all IMMEDIATE
        // next purchases, 23's all happened eventually, elsewhere along the
        // journey. Direct adjacency must win.
        $read = $this->readFake(
            [
                $this->transitionRow(22, 'Direct Next', 4, 4, 100),
                $this->transitionRow(23, 'Eventually', 4, 0, 100),
            ],
            [
                ['campaign_id' => 22, 'buyers' => 5, 'avg_value' => null],
                ['campaign_id' => 23, 'buyers' => 5, 'avg_value' => null],
            ]
        );

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, self::NOW);

        self::assertNotNull($offer);
        self::assertSame(22, $offer['campaign_id'], 'adjacent transitions outweigh eventual ones');
    }

    public function testNextOfferDecaysStaleTransitions(): void
    {
        // Identical statistics, but 23's last transition happened two years
        // ago — a funnel that stopped working fades instead of ruling.
        $read = $this->readFake(
            [
                $this->transitionRow(22, 'Fresh', 5, 5, 100, self::NOW),
                $this->transitionRow(23, 'Stale', 5, 5, 100, self::NOW - 2 * 31536000),
            ],
            [
                ['campaign_id' => 22, 'buyers' => 5, 'avg_value' => null],
                ['campaign_id' => 23, 'buyers' => 5, 'avg_value' => null],
            ]
        );

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, self::NOW);

        self::assertNotNull($offer);
        self::assertSame(22, $offer['campaign_id'], 'recency decay must demote stale funnels');
    }

    public function testNextOfferRanksByExpectedValueWhenRevenueKnown(): void
    {
        // 23 converts better, but 22's average first order is worth 20x —
        // with revenue data present the ranking optimizes expected value,
        // not raw propensity.
        $read = $this->readFake(
            [
                $this->transitionRow(22, 'High Ticket', 5, 5, 100),
                $this->transitionRow(23, 'Cheap Upsell', 10, 10, 100),
            ],
            [
                ['campaign_id' => 22, 'buyers' => 5, 'avg_value' => 200.0],
                ['campaign_id' => 23, 'buyers' => 10, 'avg_value' => 10.0],
            ]
        );

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, self::NOW);

        self::assertNotNull($offer);
        self::assertSame(22, $offer['campaign_id'], 'expected value must outrank raw propensity');
        self::assertSame('expected_value', $offer['why']['ranked_by']);
        self::assertSame(200.0, $offer['why']['avg_order_value']);
        self::assertGreaterThan(0, $offer['why']['expected_value']);
    }

    public function testWilsonLowerBoundBasics(): void
    {
        // Same proportion, more evidence -> higher lower bound.
        $small = MysqlRecommendationRepository::wilsonLowerBound(2.0, 10);
        $large = MysqlRecommendationRepository::wilsonLowerBound(20.0, 100);
        self::assertLessThan($large, $small);
        self::assertSame(0.0, MysqlRecommendationRepository::wilsonLowerBound(0.0, 0));
        self::assertGreaterThan(0.9, MysqlRecommendationRepository::wilsonLowerBound(1000.0, 1000));
    }

    public function testOneLuckyTransitionLosesToAConsistentPattern(): void
    {
        // A single perfect 1-of-1 transition must not outrank 6-of-20 —
        // the scorer's skeptical prior (one pseudo-failure) replaces a
        // brittle hard support threshold. Lift is equal for both (capped).
        $read = $this->readFake(
            [
                $this->transitionRow(22, 'Lucky Once', 1, 1, 1),
                $this->transitionRow(23, 'Consistent', 6, 6, 20),
            ],
            [
                ['campaign_id' => 22, 'buyers' => 5, 'avg_value' => null],
                ['campaign_id' => 23, 'buyers' => 5, 'avg_value' => null],
            ]
        );

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, self::NOW);

        self::assertNotNull($offer);
        self::assertSame(23, $offer['campaign_id'], 'a consistent pattern beats a single lucky customer');
    }

    public function testNextOfferFallsBackToAccountTopCampaign(): void
    {
        $read = new FakeMysqliConnection();
        // No conversion history for this customer, no transitions — fallback
        // returns the account's top-converting campaign.
        $read->whenQueryContainsReturnRows('FROM 202_conversion_logs cl', [
            ['campaign_id' => 33, 'name' => 'Top Seller', 'url' => 'https://example.com/top'],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, 1700000000);

        self::assertNotNull($offer);
        self::assertSame(33, $offer['campaign_id']);
        self::assertSame('Top Seller', $offer['name']);
        self::assertSame('account_top_recent', $offer['why']['basis'] ?? null);

        // The generic pick must never resurface a deleted campaign or one
        // whose conversions all predate the recency window.
        $queries = $read->statementsContaining('FROM 202_conversion_logs cl');
        self::assertCount(1, $queries);
        self::assertStringContainsString('aff_campaign_deleted = 0', $queries[0]->sql);
        self::assertStringContainsString('cl.conv_time >= ?', $queries[0]->sql);
        self::assertContains(1700000000 - 15552000, $queries[0]->boundValues, 'window start = now - 180 days');
    }

    public function testNextOfferUsesCustomerBrowsingWhenNoPurchaseHistory(): void
    {
        $read = new FakeMysqliConnection();
        // No conversions, but the customer's stamped clicks show live
        // interest in campaign 44 — that beats any account-wide pick.
        $read->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [
            ['campaign_id' => 44, 'name' => 'Browsed Offer', 'url' => 'https://example.com/browsed',
             'clicks' => 3, 'last_at' => 1699990000],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, 1700000000);

        self::assertNotNull($offer);
        self::assertSame(44, $offer['campaign_id']);
        self::assertSame('engagement', $offer['why']['basis'] ?? null);
        self::assertSame(3, $offer['why']['clicks'] ?? null);
        self::assertSame(1699990000, $offer['why']['last_engaged_at'] ?? null);

        $queries = $read->statementsContaining('FROM 202_clicks_tracking ct');
        self::assertCount(1, $queries);
        self::assertStringContainsString('aff_campaign_deleted = 0', $queries[0]->sql, 'deleted campaigns are never suggested');
        self::assertStringContainsString('first_click_id', $queries[0]->sql, 'the acquisition click also counts as interest');
    }

    public function testNextOfferStripsNonHttpUrls(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_conversion_logs cl', [
            // Owner misconfigured (or malicious import): javascript: scheme.
            ['campaign_id' => 33, 'name' => 'Sketchy', 'url' => 'javascript:alert(1)'],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501);

        self::assertNotNull($offer);
        self::assertSame('', $offer['url'], 'non-http(s) URLs must never reach a landing page');
    }

    public function testNextOfferReturnsNullWhenNothingQualifies(): void
    {
        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), new FakeMysqliConnection()));
        self::assertNull($repo->nextOffer(7, 501));
    }

    public function testParseFatiguePref(): void
    {
        self::assertSame(MysqlRecommendationRepository::DEFAULT_FATIGUE, MysqlRecommendationRepository::parseFatiguePref(''));
        self::assertSame(['shown' => 5, 'days' => 30], MysqlRecommendationRepository::parseFatiguePref('5,30'));
        self::assertSame(['shown' => 2, 'days' => 21], MysqlRecommendationRepository::parseFatiguePref('2'), 'days default when omitted');
        self::assertNull(MysqlRecommendationRepository::parseFatiguePref('0'), '0 disables fatigue');
        self::assertNull(MysqlRecommendationRepository::parseFatiguePref('0,99'));
        self::assertSame(MysqlRecommendationRepository::DEFAULT_FATIGUE, MysqlRecommendationRepository::parseFatiguePref('garbage'), 'garbage falls back to defaults');
    }

    public function testRecordImpressionUpsertsTheDecisionLog(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlRecommendationRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->recordImpression(7, 501, 44, 'lp', 'engagement', 1700000000);

        $inserts = $write->statementsContaining('INSERT INTO 202_offer_recommendations');
        self::assertCount(1, $inserts);
        self::assertStringContainsString('times_shown = times_shown + 1', $inserts[0]->sql, 'repeat exposure increments the rollup');
        self::assertSame('iiisssii', $inserts[0]->boundTypes);
        self::assertSame([7, 501, 44, 'lp', 'engagement', 'default', 1700000000, 1700000000], $inserts[0]->boundValues);
    }

    public function testFatigueSuppressesRepeatedlyShownOffersAndFallsToRunnerUp(): void
    {
        $read = new FakeMysqliConnection();
        // Decision log: campaign 44 shown 3x, first shown 25 days ago, never
        // converted — with default fatigue (3 shows / 21 days) it must be
        // abandoned. No fresh click on 44 (reset query returns nothing).
        $read->whenQueryContainsReturnRows('FROM 202_offer_recommendations', [
            ['campaign_id' => 44, 'shown' => 3, 'first_at' => 1700000000 - 25 * 86400, 'last_at' => 1700000000 - 23 * 86400],
        ]);
        // The engagement tier would pick 44 again, but it is suppressed, so
        // the account fallback (campaign 33) is what survives.
        $read->whenQueryContainsReturnRows('FROM 202_conversion_logs cl', [
            ['campaign_id' => 33, 'name' => 'Runner Up', 'url' => 'https://example.com/runner'],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, 1700000000);

        self::assertNotNull($offer);
        self::assertSame(33, $offer['campaign_id'], 'the fatigued offer is skipped for its runner-up');
        self::assertSame([44], $offer['why']['suppressed_campaigns'] ?? null, 'the skip is explained');

        // Suppression math travels as binds: threshold count + window start.
        $queries = $read->statementsContaining('FROM 202_offer_recommendations');
        self::assertSame([7, 501, 3, 1700000000 - 21 * 86400], $queries[0]->boundValues);
    }

    public function testFreshClickOnTheOfferResetsFatigue(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_offer_recommendations', [
            ['campaign_id' => 44, 'shown' => 5, 'first_at' => 1700000000 - 40 * 86400, 'last_at' => 1700000000 - 30 * 86400],
        ]);
        // The customer clicked into campaign 44 AFTER it was last shown —
        // the offer is working on them; keep recommending it.
        $read->whenQueryContainsReturnRows('AS last_click', [
            ['campaign_id' => 44, 'last_click' => 1700000000 - 86400],
        ]);
        $read->whenQueryContainsReturnRows('UNION ALL', [
            ['campaign_id' => 44, 'name' => 'Browsed Offer', 'url' => 'https://example.com/browsed',
             'clicks' => 6, 'last_at' => 1700000000 - 86400],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501, 1700000000);

        self::assertNotNull($offer);
        self::assertSame(44, $offer['campaign_id'], 'fresh engagement lifts the suppression');
        self::assertFalse(isset($offer['why']['suppressed_campaigns']), 'nothing was suppressed');
    }

    public function testAllowlistAcceptsNextOfferEntryAndPayloadCarriesIt(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('user_ltv_personalization_fields', [[
            'user_ltv_personalization_fields' => 'first_name, rec:next_offer',
        ]]);
        $repo = new MysqlPersonalizationRepository(new Connection(new FakeMysqliConnection(), $read));

        self::assertSame(['first_name', 'rec:next_offer'], $repo->allowedFields(7));
    }

    public function testAbmBreakdownQueriesGroupByCompanyAndExcludeEmpty(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('GROUP BY cu.company', [
            ['company' => 'Acme Corp', 'contacts' => 3, 'total_revenue' => 1200.0, 'mrr' => 99.0,
             'last_activity' => 1700000000, 'engagements' => 14, 'top_campaign_name' => 'Offer X'],
        ]);

        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));
        $rows = $repo->abmBreakdown(7, 90, 50, 0);

        self::assertCount(1, $rows);
        self::assertSame('Acme Corp', $rows[0]['company']);

        $queries = $read->statementsContaining('GROUP BY cu.company');
        self::assertCount(1, $queries);
        self::assertStringContainsString("cu.company <> ''", $queries[0]->sql, 'empty companies must be excluded');
        self::assertStringContainsString('merged_into_customer_id IS NULL', $queries[0]->sql);
    }

    public function testCustomerEngagementScopesByCustomerUserAndWindow(): void
    {
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('FROM 202_clicks_tracking ct', [
            ['campaign_id' => 10, 'campaign_name' => 'Offer A', 'landing_page' => 'LP 1',
             'clicks' => 6, 'last_seen' => 1700000000, 'conversions' => 1],
        ]);

        $repo = new MysqlEngagementRepository(new Connection(new FakeMysqliConnection(), $read));
        $rows = $repo->customerEngagement(7, 501, 90);

        self::assertCount(1, $rows);
        $queries = $read->statementsContaining('FROM 202_clicks_tracking ct');
        self::assertCount(1, $queries);
        self::assertSame('iiii', $queries[0]->boundTypes);
        self::assertContains(501, $queries[0]->boundValues);
        self::assertContains(7, $queries[0]->boundValues);
    }
}
