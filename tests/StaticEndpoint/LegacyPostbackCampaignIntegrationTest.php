<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;

/**
 * pb.php's campaign check on a real server (p202RecordLegacyConversion()'s
 * `campaign_id`): the per-campaign postback converts a click one of whose
 * visits was on the campaign it names, a campaign of the click's own account,
 * and the conversion credits the click's latest visit.
 *
 * The visitor bought offer A, a redirector re-click then sent them to offer
 * B, and A's network posts back: the sale is A's, and refusing it dropped it
 * (the check read the latest visit only). A campaign no visit was on, or one
 * of another account's that a visit names (a tracker could name one before
 * 229df10), converts nothing.
 *
 * @group integration
 */
final class LegacyPostbackCampaignIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990113;
    private const OTHER = 990114;
    private const CLICK = 99011301;
    private const FIRST = 1_790_000_000;
    private const LATEST = self::FIRST + 3600;
    private const CLICK_TABLES = ['202_clicks', '202_clicks_spy', '202_clicks_rotator', '202_click_latest_visits',
        '202_conversion_logs'];
    private const PENDING = 'DELETE FROM 202_attribution_pending WHERE conv_id IN'
        . ' (SELECT conv_id FROM 202_conversion_logs WHERE click_id = ' . self::CLICK . ')';

    /** @var array<string, int> */
    private static array $campaign = [];

    public static function setUpBeforeClass(): void
    {
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {}'
                . ' public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
        $campaigns = [
            'A' => [self::USER, '3.00'], 'B' => [self::USER, '7.00'], 'C' => [self::USER, '5.00'],
            'theirs' => [self::OTHER, '9.00'],
        ];
        foreach ($campaigns as $name => [$user, $payout]) {
            self::$campaign[$name] = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $user,"
                . " aff_network_id = 0, aff_campaign_name = '$name', aff_campaign_url = 'https://$name.example',"
                . " aff_campaign_payout = $payout,"
                . " aff_campaign_foreign_payout = $payout, aff_campaign_time = 0, payout_mode = 'replace'");
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q(self::PENDING);
        foreach (self::CLICK_TABLES as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
    }

    private static function cleanUp(): void
    {
        self::$db->query(self::PENDING);
        foreach ([...self::CLICK_TABLES, '202_dataengine'] as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id = " . self::CLICK);
        }
        foreach (['202_aff_campaigns', '202_revenue_events', '202_dirty_hours'] as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id IN (" . self::USER . ',' . self::OTHER . ')');
        }
    }

    /** The click's first visit on $first and its re-click on $latest, as the writer stores them. */
    private static function reClicked(string $first, string $latest): void
    {
        $visits = [[$first, self::FIRST, 0, '3.00'], [$latest, self::LATEST, 5, '7.00']];
        foreach ($visits as [$campaign, $time, $rotator, $payout]) {
            $columns = 'click_id = ' . self::CLICK . ', user_id = ' . self::USER
                . ', aff_campaign_id = ' . self::$campaign[$campaign] . ', landing_page_id = 0, ppc_account_id = 0,'
                . " click_cpc = 0.25, click_payout = $payout, click_lead = 0, click_time = $time";
            self::q("INSERT INTO 202_clicks SET $columns, rotator_id = $rotator, rule_id = " . ($rotator > 0 ? 9 : 0));
            self::q("INSERT INTO 202_clicks_spy SET $columns");
        }
        self::q('INSERT INTO 202_clicks_rotator SET click_id = ' . self::CLICK
            . ', rotator_id = 5, rule_id = 3, rule_redirect_id = 9');
        LatestVisit::refresh(new Connection(self::$db), self::CLICK);
    }

    /** @return array{recorded: bool, duplicate: bool, conv_id: int, reason: string} */
    private static function postback(string $campaign, string $transaction): array
    {
        return p202RecordLegacyConversion(self::$db, self::CLICK, 2, [
            'campaign_id' => self::$campaign[$campaign], 'transaction_id' => $transaction, 'source' => 'legacy_pixel',
        ]);
    }

    /** @return list<array{string, string}> campaign id and payout of each conversion */
    private static function conversions(): array
    {
        return self::$db->query('SELECT campaign_id, click_payout FROM 202_conversion_logs'
            . ' WHERE click_id = ' . self::CLICK
            . ' ORDER BY conv_id')->fetch_all(MYSQLI_NUM);
    }

    public function testAPostbackForAnEarlierVisitsCampaignConvertsAndCreditsTheLatestVisit(): void
    {
        self::reClicked('A', 'B');

        $outcome = self::postback('A', 'sale-on-A');

        self::assertTrue($outcome['recorded'], (string) json_encode($outcome));
        self::assertSame(
            [[(string) self::$campaign['B'], '7.00000']],
            self::conversions(),
            'the latest visit\'s campaign and payout'
        );
    }

    public function testAPostbackForTheLatestVisitsCampaignConverts(): void
    {
        self::reClicked('A', 'B');

        self::assertTrue(self::postback('B', 'sale-on-B')['recorded']);
        self::assertSame([[(string) self::$campaign['B'], '7.00000']], self::conversions());
    }

    public function testAPostbackForACampaignNoVisitWasOnConvertsNothing(): void
    {
        self::reClicked('A', 'B');

        self::assertSame('campaign_mismatch', self::postback('C', 'sale-on-C')['reason']);
        self::assertSame([], self::conversions());
    }

    /** @return iterable<string, array{string, string}> */
    public static function visitsNamingAnotherAccountsCampaign(): iterable
    {
        yield 'the first visit' => ['theirs', 'B'];
        yield 'the latest visit' => ['A', 'theirs'];
    }

    /** @dataProvider visitsNamingAnotherAccountsCampaign */
    public function testAVisitNamingAnotherAccountsCampaignConvertsNothingForIt(string $first, string $latest): void
    {
        self::reClicked($first, $latest);

        self::assertSame('campaign_mismatch', self::postback('theirs', 'sale-on-theirs')['reason']);
        self::assertSame([], self::conversions());
    }
}
