<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlEngagementRepository;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlRecommendationRepository;

/**
 * The LTV reads that count 202_clicks rows count a re-clicked click once,
 * under its latest visit: the acquisition breakdowns and their spend, the
 * engagement views, and the browsed-campaign recommendation.
 *
 * A redirector re-click gives the click another 202_clicks row at its own
 * time, with the campaign and landing page it was sent to and the first
 * visit's traffic source and cost. Customer C1 (of company Reclick Co) was
 * acquired by click X, campaign A then campaign B, and X is stamped with C1.
 * Customer C2 was acquired by click V, the same way; C2's stamped click W
 * was re-clicked onto the campaign it was on, B.
 *
 * @group integration
 */
final class ReClickCountsOnceIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990872;
    private const X = 99087201;
    private const V = 99087202;
    private const W = 99087203;

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $t = 0;

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
            self::seed();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
    }

    private static function cleanUp(): void
    {
        $u = self::USER;
        foreach (['202_clicks', '202_clicks_spy', '202_clicks_tracking'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN (" . self::X . ', ' . self::V . ', ' . self::W . ')');
        }
        $owned = [
            '202_customers', '202_aff_campaigns', '202_landing_pages', '202_ppc_accounts', '202_engagement_events',
            '202_users',
        ];
        foreach ($owned as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
    }

    private static function seed(): void
    {
        $u = self::USER;
        self::user($u);
        $i = &self::$ids;
        foreach (['A', 'B'] as $c) {
            $i["camp$c"] = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = 1,"
                . " aff_campaign_name = 'reclick $c', aff_campaign_url = 'https://example.com/$c',"
                . ' aff_campaign_payout = 1, aff_campaign_time = 1, aff_campaign_foreign_payout = 1');
            $i["lp$c"] = self::insert("INSERT INTO 202_landing_pages SET user_id = $u,"
                . " aff_campaign_id = {$i["camp$c"]}, landing_page_nickname = 'reclick page $c',"
                . " landing_page_url = 'https://example.com/lp$c', landing_page_time = 1");
        }
        $i['ppc'] = self::insert("INSERT INTO 202_ppc_accounts SET user_id = $u, ppc_network_id = 1,"
            . " ppc_account_name = 'reclick source', ppc_account_time = 1");

        self::$t = time() - 3600;
        $visit = static function (int $clickId, string $campaign, int $time): void {
            $row = [
                'click_id' => $clickId, 'user_id' => self::USER, 'aff_campaign_id' => self::$ids["camp$campaign"],
                'landing_page_id' => self::$ids["lp$campaign"], 'ppc_account_id' => self::$ids['ppc'],
                'click_cpc' => 0.4,
                'click_payout' => 0, 'click_lead' => 0, 'click_filtered' => 0, 'click_bot' => 0, 'click_alp' => 0,
                'click_time' => $time,
            ];
            self::row('202_clicks', $row);
            self::row('202_clicks_spy', $row);
        };
        $visit(self::X, 'A', self::$t);
        $visit(self::X, 'B', self::$t + 600);
        $visit(self::V, 'A', self::$t);
        $visit(self::V, 'B', self::$t + 600);
        $visit(self::W, 'B', self::$t + 1000);
        $visit(self::W, 'B', self::$t + 1100);

        $customer = static fn (string $ref, int $firstClick, ?string $company): int => self::insert(
            "INSERT INTO 202_customers SET user_id = $u, primary_ref = " . self::str($ref)
            . ', first_click_id = ' . $firstClick . ', company = ' . ($company === null ? 'NULL' : self::str($company))
            . ', total_revenue = 100, order_count = 1, first_seen_time = ' . self::$t
            . ', last_activity_time = ' . self::$t . ', created_at = ' . self::$t . ', updated_at = ' . self::$t
        );
        $i['c1'] = $customer('reclick-c1', self::X, 'Reclick Co');
        $i['c2'] = $customer('reclick-c2', self::V, null);
        foreach ([self::X => $i['c1'], self::W => $i['c2']] as $click => $customerId) {
            self::row('202_clicks_tracking', [
                'click_id' => $click, 'c1_id' => 0, 'c2_id' => 0, 'c3_id' => 0, 'c4_id' => 0,
                'customer_id' => $customerId,
            ]);
        }
    }

    private static function conn(): Connection
    {
        return new Connection(self::requireScratch());
    }

    public function testTheAcquisitionBreakdownCountsEachCustomerOnceUnderTheLatestVisit(): void
    {
        $ltv = new MysqlLtvRepository(self::conn());

        $byCampaign = array_map(
            static fn (array $r): array => [
                (int) $r['id'], (int) $r['customers'], (float) $r['total_revenue'], round((float) $r['spend'], 5),
            ],
            $ltv->breakdown(new LtvQuery(self::USER), 'campaign', 50, 0)
        );
        self::assertSame([[self::$ids['campB'], 2, 200.0, 1.2]], $byCampaign, 'X, V and W each once, on B');

        $bySource = array_map(
            static fn (array $r): array => [(int) $r['id'], (int) $r['customers'], round((float) $r['spend'], 5)],
            $ltv->breakdown(new LtvQuery(self::USER), 'ppc_account', 50, 0)
        );
        self::assertSame([[self::$ids['ppc'], 2, 1.2]], $bySource);

        $byPage = array_map(
            static fn (array $r): array => [(int) $r['id'], (int) $r['customers']],
            $ltv->breakdown(new LtvQuery(self::USER), 'landing_page', 50, 0)
        );
        self::assertSame([[self::$ids['lpB'], 2]], $byPage);
    }

    public function testTheEngagementViewsCountTheClickOnce(): void
    {
        $engagement = new MysqlEngagementRepository(self::conn());
        $c1 = self::$ids['c1'];

        $browsed = array_map(
            static fn (array $r): array => [
                (int) $r['campaign_id'], (string) $r['landing_page'], (int) $r['clicks'], (int) $r['last_seen'],
            ],
            $engagement->customerEngagement(self::USER, $c1)
        );
        self::assertSame([[self::$ids['campB'], 'reclick page B', 1, self::$t + 600]], $browsed);

        self::assertSame(1, $engagement->customerEngagementAggregates(self::USER, $c1)['engagements']);

        $accounts = $engagement->abmBreakdown(self::USER);
        self::assertSame([['Reclick Co', 1, 1, 'reclick B']], array_map(
            static fn (array $r): array => [
                (string) $r['company'], (int) $r['contacts'], (int) $r['engagements'], (string) $r['top_campaign_name'],
            ],
            $accounts
        ));

        $contacts = $engagement->abmCompanyDetail(self::USER, 'Reclick Co');
        self::assertSame(
            [[$c1, 1]],
            array_map(static fn (array $r): array => [(int) $r['customer_id'], (int) $r['engagements']], $contacts)
        );
    }

    public function testTheBrowsedCampaignCountsEachClickOnceUnderItsLatestVisit(): void
    {
        $recommendations = new MysqlRecommendationRepository(self::conn());
        $interest = new \ReflectionMethod($recommendations, 'engagedCampaignInterest');

        $offer = $interest->invoke($recommendations, self::USER, self::$ids['c2'], []);
        self::assertSame(
            [self::$ids['campB'], 2, self::$t + 1100],
            [$offer['campaign_id'], $offer['why']['clicks'], $offer['why']['last_engaged_at']],
            'V and W, each once'
        );

        $withoutB = $interest->invoke($recommendations, self::USER, self::$ids['c2'], [self::$ids['campB']]);
        self::assertNull($withoutB, 'V\'s first visit is not a click on A');
    }
}
