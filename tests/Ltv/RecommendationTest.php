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
    public function testNextOfferPicksTopTransitionExcludingConvertedCampaigns(): void
    {
        $read = new FakeMysqliConnection();
        // Customer converted on campaign 10 (latest) and 5.
        $read->whenQueryContainsReturnRows('GROUP BY cl.campaign_id', [
            ['campaign_id' => 10, 'last_at' => 1700000200],
            ['campaign_id' => 5, 'last_at' => 1700000100],
        ]);
        // Top transition from campaign 10 -> campaign 22.
        $read->whenQueryContainsReturnRows('FROM 202_offer_transitions', [
            ['campaign_id' => 22, 'name' => 'Offer B', 'url' => 'https://example.com/offer-b'],
        ]);

        $repo = new MysqlRecommendationRepository(new Connection(new FakeMysqliConnection(), $read));
        $offer = $repo->nextOffer(7, 501);

        self::assertNotNull($offer);
        self::assertSame(22, $offer['campaign_id']);
        self::assertSame('Offer B', $offer['name']);
        self::assertSame('https://example.com/offer-b', $offer['url']);

        // The transition lookup must exclude campaigns already converted on.
        $lookups = $read->statementsContaining('FROM 202_offer_transitions');
        self::assertCount(1, $lookups);
        self::assertStringContainsString('NOT IN', $lookups[0]->sql);
        self::assertContains(10, $lookups[0]->boundValues);
        self::assertContains(5, $lookups[0]->boundValues);
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
        $offer = $repo->nextOffer(7, 501);

        self::assertNotNull($offer);
        self::assertSame(33, $offer['campaign_id']);
        self::assertSame('Top Seller', $offer['name']);
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
