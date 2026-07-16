<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;

/**
 * End-to-end coverage for the report pipeline: seeds 202_dataengine and runs
 * every report type through DataEngine::getReportData() against a real MySQL
 * connection, asserting the trailing "Totals for report" row aggregates the
 * seeded clicks correctly. The unit tests pin SQL strings and pure logic;
 * this is the only test that proves each report's generated SQL actually
 * executes and sums correctly.
 *
 * Tagged @group integration so it is excluded from the default/CI run
 * (phpunit.xml excludes that group). It self-skips unless a DB with the
 * 202_dataengine schema is reachable. Run locally with:
 *   vendor/bin/phpunit --group integration tests/DataEngine/ReportIntegrationTest.php
 *
 * @group integration
 */
final class ReportIntegrationTest extends TestCase
{
    /** Sentinel user id, high enough not to collide with real accounts. */
    private const TEST_USER_ID = 990001;

    private static ?\mysqli $db = null;
    private static int $from = 0;
    private static int $to = 0;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);

        // Connection credentials come from env so the test is portable and
        // self-skips where no DB is provisioned (CI excludes @group
        // integration anyway). Set them as GLOBALS *before* the bootstrap
        // runs: connect2.php is required here inside a method, so the
        // $dbname/$dbuser/... assignments in 202-config.php would be
        // function-local and the DB singleton (built at include time from
        // `global $dbname`) would connect with no database selected.
        $dbName = getenv('P202_TEST_DB_NAME') ?: '';
        if ($dbName === '') {
            self::markTestSkipped('Set P202_TEST_DB_NAME (and _HOST/_USER/_PASS) to run the report integration test.');
        }
        $GLOBALS['dbname'] = $dbName;
        $GLOBALS['dbhost'] = getenv('P202_TEST_DB_HOST') ?: '127.0.0.1';
        $GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
        $GLOBALS['dbuser'] = getenv('P202_TEST_DB_USER') ?: 'root';
        $GLOBALS['dbpass'] = getenv('P202_TEST_DB_PASS') ?: '';
        $GLOBALS['mchost'] = '';

        // Bootstrap the app globals (DB class, _mysqli_query, dollar_format,
        // systemHash, ...). Wrap in output buffering + error suppression so a
        // bootstrap notice cannot trip PHPUnit's strict-output mode.
        // connect.php is the bootstrap the report pages use; it loads the
        // function libraries (dollar_format, systemHash, _mysqli_query, ...)
        // the engine depends on. (connect2.php is a separate static-endpoint
        // bootstrap with its own copies and must not be mixed in.)
        $prev = error_reporting(0);
        ob_start();
        require_once $root . '/202-config/connect.php';
        require_once $root . '/202-config/class-dataengine.php';
        ob_get_clean();
        error_reporting($prev);

        // connect.php/202-config.php create $db, $database and other app
        // globals — but running the require inside this method made them
        // method-locals. Promote them to real globals so the engine's
        // `global $db` lookups (e.g. _mysqli_query) resolve.
        foreach (get_defined_vars() as $name => $value) {
            if ($name !== 'name' && $name !== 'value') {
                $GLOBALS[$name] = $value;
            }
        }

        if (!class_exists('DB')) {
            self::markTestSkipped('App bootstrap unavailable (no 202-config.php).');
        }

        try {
            self::$db = \DB::getInstance()->getConnection();
        } catch (\Throwable) {
            self::$db = null;
        }
        if (!self::$db instanceof \mysqli || self::$db->connect_errno) {
            self::markTestSkipped('No database connection available.');
        }

        $check = @self::$db->query("SHOW TABLES LIKE '202_dataengine'");
        if (!$check || $check->num_rows === 0) {
            self::markTestSkipped('202_dataengine schema not installed in ' . $dbName . '.');
        }

        self::$to = time() + 3600;
        self::$from = time() - 3600;
        self::seedFixture();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db instanceof \mysqli) {
            self::$db->query('DELETE FROM 202_dataengine WHERE user_id = ' . self::TEST_USER_ID);
            self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . self::TEST_USER_ID);
        }
        self::$db = null;
    }

    /**
     * Seed a deterministic fixture: three clicks for the sentinel user.
     * Totals: clicks=3, click_out=2, leads=2, income=30, cost=6.
     *
     * Each click carries a *different* id for every lookup dimension, and none of those
     * ids exist in the lookup tables. The reports LEFT JOIN those tables, so all three
     * rows collapse into a single "[no X]" group — while there are three distinct
     * foreign keys. That gap is deliberate: it is the shape that made the pagination
     * count disagree with the rows returned (see
     * testFoundRowsMatchesTheRowsActuallyReturned), and it is what real data looks like
     * whenever 202_dataengine references a lookup row that was never written.
     */
    private static function seedFixture(): void
    {
        $uid = self::TEST_USER_ID;
        $t = time();
        self::$db->query('DELETE FROM 202_dataengine WHERE user_id = ' . $uid);
        self::$db->query('DELETE FROM 202_users_pref WHERE user_id = ' . $uid);
        self::$db->query(
            "INSERT INTO 202_users_pref SET user_id = $uid, user_pref_show = 'all', user_pref_limit = 50"
        );

        $rows = [
            // click_id, click_out, leads, click_lead, income, cost
            [9900001, 1, 1, 1, '10.00000', '2.00000'],
            [9900002, 1, 1, 1, '20.00000', '2.00000'],
            [9900003, 0, 0, 0, '0.00000',  '2.00000'],
        ];
        // Dimension ids are unique per row and resolve to nothing.
        $dimBase = 970000;
        foreach ($rows as $i => [$clickId, $clickOut, $leads, $clickLead, $income, $cost]) {
            $d = $dimBase + $i;
            $ok = self::$db->query(
                "INSERT INTO 202_dataengine
                   (user_id, click_id, click_time, ppc_account_id, landing_page_id,
                    keyword_id, text_ad_id, ip_id, country_id, region_id, city_id,
                    isp_id, device_id, browser_id, platform_id, click_referer_site_url_id,
                    click_lead, clicks, click_out, leads, payout, income, cost)
                 VALUES
                   ($uid, $clickId, $t, 0, $d,
                    $d, $d, $d, $d, $d, $d,
                    $d, $d, $d, $d, $d,
                    $clickLead, 1, $clickOut, $leads, 0.00, $income, $cost)"
            );
            if (!$ok) {
                self::markTestSkipped('Could not seed 202_dataengine: ' . self::$db->error);
            }
        }
    }

    /**
     * Establish the session state getReportData/getFilters read, scoped to
     * the sentinel user, before each test.
     */
    protected function setUp(): void
    {
        $_SESSION['user_id'] = self::TEST_USER_ID;
        $_SESSION['user_own_id'] = self::TEST_USER_ID;
        $_SESSION['publisher'] = false;
        $_POST = [];
    }

    /** Every single-dimension grouped report type. */
    public static function groupedReportProvider(): array
    {
        return array_map(
            static fn(string $t) => [$t],
            ['keyword', 'textad', 'referer', 'ip', 'country', 'region',
             'city', 'isp', 'landingpage', 'device', 'browser', 'platform']
        );
    }

    /**
     * @dataProvider groupedReportProvider
     */
    public function testGroupedReportAggregatesSeededClicks(string $reportType): void
    {
        $de = new \DataEngine();
        $data = $de->getReportData($reportType, self::$from, self::$to, false);

        self::assertIsArray($data, "$reportType must return an array");
        self::assertNotEmpty($data, "$reportType must return at least the totals row");

        $totals = end($data);
        self::assertArrayHasKey('total_clicks', $totals, "$reportType missing totals row");
        self::assertSame('3', $totals['total_clicks'], "$reportType total clicks");
        self::assertSame('2', $totals['total_click_out'], "$reportType total click-throughs");
        self::assertSame('2', $totals['total_leads'], "$reportType total leads");

        // foundRows (pagination count) must be reachable without error.
        self::assertIsInt($de->foundRows());
    }

    /**
     * The pagination count must equal the number of rows the report actually returns.
     *
     * This is the regression guard for the count/rows mismatch. Reports GROUP BY the
     * joined name (region_name, text_ad_name, …), but the count used to run a separate
     * COUNT(DISTINCT <foreign key>) against 202_dataengine with none of the report's
     * joins. The fixture seeds three distinct, unresolvable dimension ids per report, so
     * the old count returned 3 where the report returns a single "[no X]" group — an
     * inflated totalRows that advertised a trailing page rendering zero rows.
     *
     * The limit is well above the group count here, so every group is on page one and
     * foundRows is directly comparable to the rows returned.
     *
     * @dataProvider groupedReportProvider
     */
    public function testFoundRowsMatchesTheRowsActuallyReturned(string $reportType): void
    {
        $de = new \DataEngine();
        $data = $de->getReportData($reportType, self::$from, self::$to, false);

        self::assertIsArray($data);
        self::assertNotEmpty($data);

        // The engine appends a trailing "Totals for report" row that is not a group.
        $dataRows = count($data) - 1;

        self::assertSame(
            $dataRows,
            $de->foundRows(),
            "$reportType: pagination count ({$de->foundRows()}) must equal the $dataRows row(s) returned"
        );
    }

    /**
     * The three seeded clicks share no lookup rows, so each report must collapse them
     * into exactly one group. Pins the fixture's intent, so a future edit that makes the
     * ids resolvable cannot quietly neuter the guard above.
     *
     * @dataProvider groupedReportProvider
     */
    public function testUnresolvableDimensionIdsCollapseIntoASingleGroup(string $reportType): void
    {
        $de = new \DataEngine();
        $data = $de->getReportData($reportType, self::$from, self::$to, false);

        self::assertCount(
            2,
            $data,
            "$reportType must return one group plus the totals row for three unresolvable ids"
        );
        self::assertSame(1, $de->foundRows(), "$reportType must count exactly one group");
    }

    public function testTimeBreakdownReportsExecute(): void
    {
        foreach (['breakdown', 'hourly', 'weekly'] as $reportType) {
            $de = new \DataEngine();
            $data = $de->getReportData($reportType, self::$from, self::$to, false);
            self::assertNotEmpty($data, "$reportType returned no rows");
            $totals = end($data);
            self::assertSame('3', $totals['total_clicks'], "$reportType total clicks");
        }
    }

    public function testLandingPageOverviewExecutes(): void
    {
        $de = new \DataEngine();
        $data = $de->getReportData('LpOverview', self::$from, self::$to, false);
        $totals = end($data);
        self::assertSame('3', $totals['total_clicks']);
        self::assertSame('2', $totals['total_leads']);
    }

    /**
     * Locks the divide-by-zero-guarded metric math end-to-end: with income 30
     * over 2 leads the totals payout is income/leads = $15.00 (not the legacy
     * order-dependent running average), and cost 6 over 3 clicks is $2.00 CPC.
     */
    public function testTotalsMetricsAreArithmeticallyCorrect(): void
    {
        $de = new \DataEngine();
        $data = $de->getReportData('country', self::$from, self::$to, false);
        $totals = end($data);

        self::assertStringContainsString('30', $totals['total_income'], 'income sum');
        self::assertStringContainsString('6', $totals['total_cost'], 'cost sum');
        self::assertStringContainsString('15', $totals['total_payout'], 'payout = income/leads');
        self::assertStringContainsString('67', $totals['total_ctr'], 'ctr = click_out/clicks');
    }
}
