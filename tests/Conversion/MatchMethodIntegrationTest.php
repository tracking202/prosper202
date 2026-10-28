<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\Ledger\ClickBreakdown;
use Prosper202\Conversion\Ledger\MatchMethod;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\ConversionTables;
use Prosper202\Update\SubidBatch;

/**
 * How each conversion's click was found (match_method), on a real server:
 * what every writer path stores, and what the reads make of it — the list
 * and its filter, a click's breakdown, GET /conversions/match-methods and
 * Group Overview's Match method level.
 *
 * The endpoints decide the method from their own lookup (a subid, a
 * cookie, the visitor's address), which only a request through them shows:
 * MatchMethodEndpointsInstanceTest drives them over HTTP. Here every
 * writer they and the other paths call is shown to store the method it is
 * handed, and the paths that need no request (the API's create, Update
 * Subids, a revenue upload, the value carried in from before the ledger)
 * are run whole. GoalRowsCarryTheirEventsMatchMethodTest has the goals.
 *
 * Skips unless P202_TEST_DB_HOST (and friends) name a scratch database.
 *
 * @group integration
 */
final class MatchMethodIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        require_once dirname(__DIR__, 2) . '/202-config/static-endpoint-helpers.php';
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_click_latest_visits', '202_aff_campaigns',
            '202_attribution_pending', '202_conversion_uploads', '202_dataengine', '202_cpa_trackers', '202_users_pref'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $this->repo = new MysqlConversionRepository(new Connection(self::$db));
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function campaign(int $id, string $mode = 'replace', int $user = 1): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=$user, aff_network_id=1, aff_campaign_name='Camp $id',
            aff_campaign_url='http://x', aff_campaign_payout=10, aff_campaign_time=1, aff_campaign_foreign_payout=10, payout_mode='$mode'");
    }

    private function click(int $id, int $campaign, string $payout = '0.00', int $lead = 0, int $user = 1): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=$user, aff_campaign_id=$campaign, click_payout=$payout, click_cpc=0,"
                . " click_lead=$lead, click_time=1700000000");
        }
        self::fixture("INSERT INTO 202_dataengine SET click_id=$id, user_id=$user, click_time=1700000000, aff_campaign_id=$campaign,
            ppc_account_id=0, landing_page_id=0, clicks=1, leads=$lead, payout=0, income=$payout, cost=0.25");
    }

    /** @param array<string, mixed> $data */
    private function record(int $clickId, MatchMethod $method, array $data = [], int $user = 1): int
    {
        $r = $this->repo->record($user, ['click_id' => $clickId] + $data + ['source' => 'postback'], $method);
        self::assertFalse($r['duplicate'], 'fixture rows are new');

        return (int) $r['convId'];
    }

    /** @return list<string> the click's rows' methods, oldest first */
    private static function methods(int $clickId): array
    {
        return array_column(self::$db->query("SELECT match_method FROM 202_conversion_logs WHERE click_id = $clickId ORDER BY conv_id")
            ->fetch_all(MYSQLI_ASSOC), 'match_method');
    }

    public function testTheWriterStoresTheMethodAndARetryByAnotherPathKeepsTheFirst(): void
    {
        $this->campaign(1);
        $this->click(100, 1);
        $this->record(100, MatchMethod::SAME_IP, ['payout' => '5', 'transaction_id' => 'T-1']);

        $retry = $this->repo->record(1, ['click_id' => 100, 'payout' => '5', 'transaction_id' => 'T-1', 'source' => 'postback'], MatchMethod::CLICK_ID);
        self::assertTrue($retry['duplicate']);
        self::assertSame(['same_ip'], self::methods(100), 'a retry records nothing, its method included: the row says how the sale was first matched');

        $this->record(100, MatchMethod::COOKIE, ['payout' => '7', 'transaction_id' => 'T-2']);
        self::assertSame(['same_ip', 'cookie'], self::methods(100));
    }

    /**
     * A click converted before the ledger holds its value only in its cache;
     * its next conversion carries the value in as a legacy_baseline row,
     * which stands for writes nothing recorded the method of.
     */
    public function testTheValueCarriedInFromBeforeTheLedgerIsUnknown(): void
    {
        $this->campaign(1, 'accumulate');
        $this->click(100, 1, '20.00', 1);
        $this->record(100, MatchMethod::COOKIE, ['payout' => '3', 'transaction_id' => 'NEW-1']);

        $rows = self::$db->query('SELECT source, match_method FROM 202_conversion_logs WHERE click_id = 100 ORDER BY conv_id')->fetch_all(MYSQLI_ASSOC);
        self::assertSame([['source' => 'legacy_baseline', 'match_method' => 'unknown'], ['source' => 'postback', 'match_method' => 'cookie']], $rows);
    }

    public function testUpdateSubidsARevenueUploadAndTheApiNameTheClick(): void
    {
        $this->campaign(1);
        $this->click(100, 1);
        $this->click(101, 1);
        $this->click(102, 1);

        $marked = (new SubidBatch(new Connection(self::$db), $this->repo))->mark(1, ['100']);
        self::assertSame(1, $marked['marked']);
        self::assertSame(['click_id'], self::methods(100), 'Update Subids: the list named the click');

        $h = fopen('php://memory', 'r+');
        fwrite($h, "subid,amount\n101,4.00\n");
        rewind($h);
        $uploaded = (new RevenueUploadImporter(new Connection(self::$db), $this->repo))->import(1, 'r.csv', $h, 0, 1);
        self::assertSame(1, $uploaded['recorded']);
        self::assertSame(['click_id'], self::methods(101), 'a revenue upload: the line\'s subid named the click');

        $created = (new ConversionsController(self::$db, 1))->create(['click_id' => 102, 'payout' => 2, 'transaction_id' => 'API-1']);
        self::assertSame('click_id', $created['data']['match_method'], 'POST /conversions: the body named the click, and the answer says so');
        self::assertSame(['click_id'], self::methods(102));
    }

    /**
     * The endpoints' writers store the method the endpoint hands them: the
     * per-campaign pixel's cookie or address (px.php), ClickBank's and the
     * per-campaign postback's click id (cb202.php, pb.php), and the global
     * ones' parameter, cookie or address (gpx.php, upx.php, gpb.php).
     */
    public function testTheEndpointsWritersStoreTheMethodTheEndpointFoundTheClickBy(): void
    {
        $this->campaign(1, 'accumulate');
        foreach ([100, 101, 102, 103] as $click) {
            $this->click($click, 1);
        }
        $legacy = static fn (int $click, MatchMethod $method): array => p202RecordLegacyConversion(self::$db, $click, 1, $method, [
            'user_id' => 1, 'transaction_id' => 'L-' . $click, 'source' => 'legacy_pixel',
        ]);
        self::assertTrue($legacy(100, MatchMethod::COOKIE)['recorded']);
        self::assertTrue($legacy(101, MatchMethod::SAME_IP)['recorded']);
        self::assertTrue($legacy(102, MatchMethod::CLICK_ID)['recorded']);
        $global = p202RecordConversion(self::$db, [
            'click_id' => 103, 'user_id' => 1, 'conv_time' => 1700000100, 'ip' => '', 'pixel_type' => 1, 'user_agent' => '',
            'once_per_click' => true,
        ], MatchMethod::SAME_IP, '', false, '');
        self::assertGreaterThan(0, $global['conv_id']);

        self::assertSame(['cookie'], self::methods(100));
        self::assertSame(['same_ip'], self::methods(101));
        self::assertSame(['click_id'], self::methods(102));
        self::assertSame(['same_ip'], self::methods(103));
    }

    public function testTheListFiltersByMethodAndRefusesOneItDoesNotKnow(): void
    {
        $this->campaign(1);
        $this->click(100, 1);
        $this->click(101, 1);
        $guess = $this->record(100, MatchMethod::SAME_IP, ['payout' => '5', 'transaction_id' => 'G']);
        $this->record(101, MatchMethod::CLICK_ID, ['payout' => '5', 'transaction_id' => 'C']);

        $api = new ConversionsController(self::$db, 1);
        $listed = $api->list(['match_method' => 'same_ip']);
        self::assertSame([$guess], array_map(static fn (array $r): int => (int) $r['conv_id'], $listed['data']));
        self::assertSame('same_ip', $listed['data'][0]['match_method']);
        self::assertSame(1, $listed['pagination']['total']);
        self::assertSame('same_ip', $api->get($guess)['data']['match_method']);

        foreach (['ip', 'SAME_IP', ''] as $bad) {
            try {
                $api->list(['match_method' => $bad]);
                self::fail("match_method=$bad was not refused");
            } catch (ValidationException $e) {
                self::assertStringContainsString('click_id, cookie, visitor_id, same_ip, unknown', (string) json_encode($e->getFieldErrors()));
            }
        }
    }

    public function testTheClickBreakdownSaysHowEachRowsClickWasFound(): void
    {
        $this->campaign(1, 'accumulate');
        $this->click(100, 1);
        $this->record(100, MatchMethod::COOKIE, ['payout' => '5', 'transaction_id' => 'A']);
        $this->record(100, MatchMethod::VISITOR_ID, ['payout' => '2', 'transaction_id' => 'B']);

        $rows = (new ClickBreakdown(new Connection(self::$db)))->forClick(100, 1)['rows'];
        self::assertSame([['cookie', 'Cookie'], ['visitor_id', 'Visitor id']], array_map(
            static fn (array $r): array => [$r['match_method'], $r['match_method_label']],
            $rows
        ));

        self::$db->query("UPDATE 202_conversion_logs SET match_method = 'guess' WHERE click_id = 100 AND transaction_id = 'B'");
        try {
            (new ClickBreakdown(new Connection(self::$db)))->forClick(100, 1);
            self::fail('a method nothing writes was read as one');
        } catch (\Prosper202\Conversion\Ledger\LedgerIntegrityException $e) {
            self::assertStringContainsString("has match_method 'guess'", $e->getMessage());
        }
    }

    /**
     * Per campaign and method: the live conversions that are not reversals,
     * the ones that count toward their click's value, and what the counting
     * rows add up to, a reversal netted under its own request's method.
     */
    public function testTheBreakdownCountsEachCampaignsConversionsByMethod(): void
    {
        $this->campaign(1, 'replace');
        $this->campaign(2, 'accumulate');
        $this->campaign(3, 'replace', 2);
        // Campaign 1, click 100: a click-id sale replaced by a cookie sale.
        $this->click(100, 1);
        $this->record(100, MatchMethod::CLICK_ID, ['payout' => '5', 'transaction_id' => 'A1', 'conv_time' => 1700000100]);
        $this->record(100, MatchMethod::COOKIE, ['payout' => '10', 'transaction_id' => 'A2', 'conv_time' => 1700000200]);
        // Click 101: a guessed sale, reversed by a postback that named the click.
        $this->click(101, 1);
        $this->record(101, MatchMethod::SAME_IP, ['payout' => '7', 'transaction_id' => 'B1', 'conv_time' => 1700000300]);
        $this->record(101, MatchMethod::CLICK_ID, ['transaction_id' => 'B1', 'reversal' => true, 'conv_time' => 1700000400]);
        // Campaign 2: a pre-ledger lead carried in, and a visitor-id event; a deleted one.
        $this->click(200, 2, '20.00', 1);
        $this->record(200, MatchMethod::VISITOR_ID, ['payout' => '3', 'transaction_id' => 'C1', 'conv_time' => 1700000500]);
        $deleted = $this->record(200, MatchMethod::SAME_IP, ['payout' => '4', 'transaction_id' => 'C2', 'conv_time' => 1700000600]);
        $this->repo->softDelete($deleted, 1);
        // Another account's.
        $this->click(300, 3, '0.00', 0, 2);
        $this->record(300, MatchMethod::SAME_IP, ['payout' => '9', 'transaction_id' => 'D1', 'conv_time' => 1700000700], 2);

        $api = new ConversionsController(self::$db, 1);
        $answer = $api->matchMethods([]);
        $rows = array_map(static fn (array $r): string => sprintf(
            '%d %s %s: %d conversions, %d counted, %s',
            $r['campaign_id'],
            (string) $r['campaign_name'],
            $r['match_method'],
            $r['conversions'],
            $r['counted'],
            number_format($r['income'], 2, '.', '')
        ), $answer['data']);
        self::assertSame([
            '1 Camp 1 click_id: 1 conversions, 0 counted, -7.00',
            '1 Camp 1 cookie: 1 conversions, 1 counted, 10.00',
            '1 Camp 1 same_ip: 1 conversions, 1 counted, 7.00',
            '2 Camp 2 visitor_id: 1 conversions, 1 counted, 3.00',
            '2 Camp 2 unknown: 1 conversions, 1 counted, 20.00',
        ], $rows, 'the replaced sale counts nothing, the reversal nets under its own method, the deleted row and the other account\'s are not there');
        self::assertSame(MatchMethod::values(), array_column($answer['totals'], 'match_method'), 'every method has a total');
        self::assertSame([1, 1, 1, 1, 1], array_column($answer['totals'], 'conversions'));
        self::assertSame([-7.0, 10.0, 3.0, 7.0, 20.0], array_column($answer['totals'], 'income'));
        self::assertSame(MatchMethod::values(), $answer['match_methods']);

        self::assertSame([2, 2], array_column($api->matchMethods(['campaign_id' => '2'])['data'], 'campaign_id'), 'campaign_id narrows to the campaign');
        $window = $api->matchMethods(['time_from' => '1700000250', 'time_to' => '1700000450']);
        self::assertSame([['click_id', -7.0], ['same_ip', 7.0]], array_map(
            static fn (array $r): array => [$r['match_method'], $r['income']],
            $window['data']
        ), 'the window is the conversions\' time');
        self::assertCount(5, $api->matchMethods(['period' => 'alltime'])['data']);

        foreach ([['campaign_id' => '0'], ['period' => 'last7d'], ['match_method' => 'cookie']] as $bad) {
            try {
                $api->matchMethods($bad);
                self::fail('refused nothing for ' . json_encode($bad));
            } catch (ValidationException) {
                self::assertTrue(true);
            }
        }
    }

    public function testGroupOverviewsMatchMethodLevelSplitsACampaignWithoutChangingIt(): void
    {
        require_once dirname(__DIR__, 2) . '/202-config/ReportSummaryForm.class.php';
        $this->campaign(1, 'accumulate');
        $this->click(100, 1);
        $this->record(100, MatchMethod::CLICK_ID, ['payout' => '5', 'transaction_id' => 'A']);
        $this->record(100, MatchMethod::SAME_IP, ['payout' => '2', 'transaction_id' => 'B']);
        $this->click(101, 1);
        $this->record(101, MatchMethod::COOKIE, ['payout' => '4', 'transaction_id' => 'C']);
        $this->click(102, 1, '8.00', 1); // a pre-ledger lead, no row yet
        $this->click(103, 1);            // not converted
        self::$db->query('UPDATE 202_dataengine d JOIN 202_clicks c ON c.click_id = d.click_id
            SET d.leads = c.click_lead, d.income = IF(c.click_lead = 1, c.click_payout, 0), d.cost = 0.25');

        $plain = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN]);
        $byMethod = $this->groupOverview([\ReportBasicForm::DETAIL_LEVEL_CAMPAIGN, \ReportBasicForm::DETAIL_LEVEL_MATCH_METHOD]);
        self::assertSame(self::children($plain['tree']), self::children($byMethod['tree']), 'the level splits a campaign and leaves its figures as they were');

        $campaign = $byMethod['tree']->getChildArrayBySort()[0];
        self::assertSame([
            'Click id' => ['clicks' => 0, 'leads' => 0, 'income' => '5.00', 'cost' => '0.00'],
            'Cookie' => ['clicks' => 1, 'leads' => 1, 'income' => '4.00', 'cost' => '0.25'],
            'Same IP' => ['clicks' => 1, 'leads' => 1, 'income' => '2.00', 'cost' => '0.25'],
            'Unknown' => ['clicks' => 1, 'leads' => 1, 'income' => '8.00', 'cost' => '0.25'],
            '[Not converted]' => ['clicks' => 1, 'leads' => 0, 'income' => '0.00', 'cost' => '0.25'],
        ], self::children($campaign), 'each counted row under its method; a click\'s click and lead on its latest counted row; a pre-ledger lead\'s value is unknown');
        self::assertContains(\ReportBasicForm::DETAIL_LEVEL_MATCH_METHOD, \ReportSummaryForm::getDetailArray());
        self::assertSame('Match method', \ReportBasicForm::translateDetailLevelById(\ReportBasicForm::DETAIL_LEVEL_MATCH_METHOD));
    }

    /**
     * Code deployed before the 1.9.79 upgrade: the pixels and postbacks do
     * not ask whether the database is upgraded, so a conversion is written
     * without the column rather than lost, and once the upgrade adds it the
     * row reads unknown.
     */
    public function testAConversionRecordedBeforeTheUpgradeAddedTheColumnIsKeptAndReadsUnknown(): void
    {
        $this->campaign(1, 'accumulate');
        $this->click(100, 1);
        $log = tempnam(sys_get_temp_dir(), 'p202mm');
        $was = ini_set('error_log', (string) $log);
        self::$db->query('ALTER TABLE 202_conversion_logs DROP COLUMN match_method');
        try {
            $this->record(100, MatchMethod::SAME_IP, ['payout' => '5', 'transaction_id' => 'EARLY']);
        } finally {
            ini_set('error_log', (string) $was);
            $reconciler = new SchemaReconciler(static fn (string $sql) => self::$db->query($sql));
            self::assertTrue($reconciler->reconcile(ConversionTables::conversionLogs()), implode('; ', $reconciler->getErrors()));
        }
        self::assertSame(['unknown'], self::methods(100), 'the column the upgrade adds gives the row its default');
        self::assertStringContainsString('the 1.9.79 upgrade has not added match_method yet', (string) file_get_contents((string) $log));
        unlink((string) $log);
    }

    /** @param list<int> $details */
    private function groupOverview(array $details): array
    {
        if (!class_exists('DB', false)) {
            eval('class DB { public static $conn; public static function getInstance() { return new self(); } public function getConnection() { return self::$conn; } }');
        }
        \DB::$conn = self::$db;
        $_SESSION['publisher'] = true;
        $_SESSION['user_own_id'] = 1;
        $prefs = [];
        foreach (['aff_campaign_id', 'aff_network_id', 'browser_id', 'country_id', 'device_id', 'ip', 'isp_id', 'keyword', 'landing_page_id',
            'platform_id', 'ppc_account_id', 'ppc_network_id', 'referer', 'region_id', 'show', 'subid', 'text_ad_id'] as $k) {
            $prefs['user_pref_' . $k] = '';
        }
        $form = new \ReportSummaryForm();
        $form->setDetails($details);
        $form->setDetailsSort([\ReportBasicForm::SORT_NAME]);
        $form->setDisplayType([\ReportBasicForm::DISPLAY_TYPE_TABLE]);
        $form->setStartTime(1600000000);
        $form->setEndTime(1800000000);
        $result = self::$db->query($form->getQuery('1', $prefs));
        foreach ($result->fetch_all(MYSQLI_ASSOC) as $row) {
            $form->addReportData($row);
        }

        return ['tree' => $form->getReportData()];
    }

    /** @return array<string, array{clicks: int, leads: int, income: string, cost: string}> */
    private static function children(object $group): array
    {
        $out = [];
        foreach ($group->getChildArrayBySort() as $child) {
            $out[(string) $child->getTitle()] = [
                'clicks' => (int) $child->getClicks(),
                'leads' => (int) $child->getLeads(),
                'income' => number_format((float) $child->getIncome(), 2, '.', ''),
                'cost' => number_format((float) $child->getCost(), 2, '.', ''),
            ];
        }
        ksort($out);

        return $out;
    }
}
