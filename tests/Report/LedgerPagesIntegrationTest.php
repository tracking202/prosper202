<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Spend\SpendLedger;

/**
 * The report pages cost clicks from the spend ledger as the REST reports do
 * (LedgerClicks, LedgerCost), on a real server (CLAUDE.md #9): Overview's
 * tables and chart, Analyze's reports, Breakdown, Day and Week Parting,
 * Custom Variables and Group Overview, pre-aggregated or not.
 *
 * - With no ledger row of the account in the window, every page issues the
 *   same statements, to the byte, and draws the same figures as with no
 *   ledger at all.
 * - With ledger rows, every page's total is the REST summary's total_cost
 *   for the window, and the page has the "Spend data reaches" note.
 *
 * Seeded as tests/Spend/LedgerCostIntegrationTest seeds (users 6501, 6502).
 * Boots the app as the report pages do (connect.php), so it needs a
 * 202-config.php and a scratch database (P202_TEST_DB_*).
 *
 * @group integration
 */
final class LedgerPagesIntegrationTest extends TestCase
{
    private const int USER = 6501;
    private const int OTHER = 6502;
    private const int NETWORK = 6501;
    private const int CAMPAIGN = 650001;
    private const string ZONE = 'America/New_York';

    /** The grouped Analyze reports, every one through runGroupedReport(). */
    private const array GROUPED = [
        'keyword', 'textad', 'referer', 'ip', 'country', 'region', 'city', 'isp', 'landingpage', 'device',
        'browser', 'platform',
    ];

    private static ?\mysqli $db = null;
    private static int $hour = 0;

    /** @var list<string> every statement the logging connection was handed */
    public static array $log = [];

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        $dbName = getenv('P202_TEST_DB_NAME') ?: '';
        if ($dbName === '' || !is_file($root . '/202-config.php')) {
            return;
        }
        $host = getenv('P202_TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('P202_TEST_DB_PORT') ?: 3306);
        $GLOBALS['dbname'] = $dbName;
        $GLOBALS['dbhost'] = $host . ':' . $port;
        $GLOBALS['dbhostro'] = $GLOBALS['dbhost'];
        $GLOBALS['dbuser'] = getenv('P202_TEST_DB_USER') ?: 'root';
        $GLOBALS['dbpass'] = getenv('P202_TEST_DB_PASS') ?: '';
        $GLOBALS['mchost'] = '';

        $prev = error_reporting(0);
        ob_start();
        require_once $root . '/202-config/connect.php';
        require_once $root . '/202-config/class-dataengine.php';
        require_once $root . '/202-config/ReportSummaryForm.class.php';
        require_once $root . '/202-config/functions-ui-overview.php';
        ob_get_clean();
        error_reporting($prev);
        foreach (get_defined_vars() as $name => $value) {
            if ($name !== 'name' && $name !== 'value') {
                $GLOBALS[$name] = $value;
            }
        }
        $app = \DB::getInstance()->getConnection();
        if (!$app instanceof \mysqli) {
            return;
        }
        $at = $app->query('SELECT DATABASE()');
        if (!$at instanceof \mysqli_result || $at->fetch_row()[0] !== $dbName) {
            self::fail('The app did not connect to the configured scratch database; no fixtures were written.');
        }

        // The app's connection, with every statement it is handed written down.
        if (!class_exists('LedgerPagesLoggingMysqli', false)) {
            eval(<<<'PHP'
                final class LedgerPagesLoggingMysqli extends mysqli
                {
                    public function query(string $query, int $result_mode = MYSQLI_STORE_RESULT): mysqli_result|bool
                    {
                        \Tests\Report\LedgerPagesIntegrationTest::$log[] = $query;

                        return parent::query($query, $result_mode);
                    }

                    public function prepare(string $query): mysqli_stmt|false
                    {
                        \Tests\Report\LedgerPagesIntegrationTest::$log[] = $query;

                        return parent::prepare($query);
                    }
                }
                PHP);
        }
        $logging = new \LedgerPagesLoggingMysqli(
            $host,
            (string) $GLOBALS['dbuser'],
            (string) $GLOBALS['dbpass'],
            $dbName,
            $port
        );
        $mode = $app->query('SELECT @@SESSION.sql_mode, @@character_set_connection')->fetch_row();
        $logging->set_charset((string) $mode[1]);
        $logging->query("SET SESSION sql_mode = '" . $logging->real_escape_string((string) $mode[0]) . "'");
        $property = new \ReflectionProperty(\DB::class, '_connection');
        $property->setValue(\DB::getInstance(), $logging);
        $GLOBALS['db'] = $logging;
        self::$db = $logging;

        $installed = (new \Prosper202\Database\SchemaInstaller($logging))->install();
        if ($installed->hasErrors()) {
            self::fail('Could not install the schema: ' . $installed->getSummary());
        }
        $now = time();
        self::$hour = $now - $now % 3600 - 2 * 86400;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('Needs a 202-config.php and a scratch database (P202_TEST_DB_*).');
        }
        self::cleanUp();
        self::seed();
        $_POST = [];
    }

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            $tables = ['202_dataengine', '202_spend_ledger', '202_users_pref', '202_users', '202_ppc_networks',
                '202_aff_campaigns'];
            foreach ($tables as $t) {
                self::q("DELETE FROM $t WHERE user_id = $u");
            }
        }
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_network_id IN ('
            . self::NETWORK . ', ' . (self::NETWORK + 1) . ')');
        self::q('DELETE FROM 202_custom_variables WHERE custom_variable_id BETWEEN 650100 AND 650199');
        self::q('DELETE FROM 202_variable_sets2 WHERE variable_set_id BETWEEN 650100 AND 650199');
    }

    private static function seed(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'lp$u', user_email = 'lp$u@example.com',"
                . " user_dash_email = '', user_pass = 'x', user_timezone = '" . self::ZONE . "',"
                . " user_time_register = 0, install_hash = '', user_hash = ''");
            self::q("INSERT INTO 202_users_pref SET user_id = $u, user_account_currency = 'USD',"
                . " user_pref_show = 'all',"
                . " user_pref_limit = 50, user_pref_breakdown = 'day'");
        }
        self::q('INSERT INTO 202_aff_campaigns SET aff_campaign_id = ' . self::CAMPAIGN . ', user_id = ' . self::USER
            . ", aff_campaign_name = 'Spring sale', aff_campaign_url = 'https://shop.example/',"
            . " aff_campaign_payout = 0,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0, aff_network_id = 0');
        foreach ([self::USER => self::NETWORK, self::OTHER => self::NETWORK + 1] as $u => $network) {
            self::q("INSERT INTO 202_ppc_networks SET ppc_network_id = $network, user_id = $u,"
                . " ppc_network_name = 'ChatGPT Ads', ppc_network_time = 0");
            self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = $network, ppc_network_id = $network,"
                . " name = 'ad_id', parameter = 'ad_id', placeholder = '{ad_id}'");
            self::q("INSERT INTO 202_ppc_network_variables SET ppc_variable_id = " . ($network + 10)
                . ", ppc_network_id = $network,"
                . " name = 'campaign_id', parameter = 'campaign_id', placeholder = '{campaign_id}'");
        }
        // As LedgerCostIntegrationTest: 650101 ad A, 650102 ad B, 650103 two
        // ad ids (ambiguous), 650104 the other account's ad A, 650105 a
        // campaign id only.
        $values = [650101 => [self::NETWORK, 'ad_A'], 650102 => [self::NETWORK, 'ad_B'],
            650103 => [self::NETWORK, 'ad_C'], 650104 => [self::NETWORK + 1, 'ad_A'],
            650105 => [self::NETWORK + 10, 'ad_A']];
        foreach ($values as $id => [$variable, $value]) {
            self::q("INSERT INTO 202_custom_variables SET custom_variable_id = $id, ppc_variable_id = $variable,"
                . " variable = '$value'");
        }
        $sets = [650101 => [650101], 650102 => [650102], 650103 => [650101, 650103], 650104 => [650104],
            650105 => [650105]];
        foreach ($sets as $set => $variables) {
            foreach ($variables as $v) {
                self::q("INSERT INTO 202_variable_sets2 SET variable_set_id = $set, variables = '$v'");
            }
        }
        $h = self::$hour;
        // click id => [user, network, time, variable set, cost]
        $clicks = [
            650001 => [self::USER, self::NETWORK, $h + 100, '650101', '0.10000'],
            650002 => [self::USER, self::NETWORK, $h + 2000, '650101', '0.10000'],
            650003 => [self::USER, self::NETWORK, $h + 3700, '650102', '0.50000'],
            650004 => [self::USER, self::NETWORK, $h + 200, '0', '0.20000'],
            650005 => [self::USER, self::NETWORK, $h + 300, '650103', '0.30000'],
            650006 => [self::USER, self::NETWORK, $h + 400, '650105', '0.40000'],
            650007 => [self::OTHER, self::NETWORK + 1, $h + 500, '650104', '0.70000'],
            650008 => [self::USER, self::NETWORK + 1, $h + 600, '650104', '0.60000'],
        ];
        foreach ($clicks as $id => [$user, $network, $time, $set, $cost]) {
            $campaign = $user === self::USER ? self::CAMPAIGN : 0;
            self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = $id, click_time = $time,"
                . " ppc_network_id = $network, ppc_account_id = 0, aff_campaign_id = $campaign, landing_page_id = 0,"
                . " keyword_id = 0, variable_set_id = '$set', clicks = 1, click_out = 1, leads = 0,"
                . " click_lead = 0, click_filtered = 0, click_bot = 0, payout = 0, income = 1, cost = $cost");
        }
    }

    private static function write(int $user, string $ad, int $hourStart, string $spend): void
    {
        $ledger = new SpendLedger(new Connection(self::$db));
        [$parsed, $errors] = $ledger->parse($user, [['platform' => 'openai_ads', 'account_ref' => 'adacct_demo',
            'campaign_ref' => 'cmp_1', 'ad_group_ref' => null, 'ad_ref' => $ad, 'hour_start' => $hourStart,
            'impressions' => 1000, 'clicks' => 12, 'spend' => $spend, 'currency' => 'USD']]);
        self::assertSame([], $errors);
        $ledger->write($user, $parsed);
    }

    /** The window the pages and the REST summary read: the seeded clicks' two hours. @return array{int, int} */
    private static function window(): array
    {
        return [self::$hour, self::$hour + 7199];
    }

    private static function signIn(string $zone = self::ZONE): void
    {
        $_SESSION = ['user_id' => self::USER, 'user_own_id' => self::USER, 'user_timezone' => $zone];
        \AUTH::set_timezone($zone);
    }

    /**
     * Every page reader this change touches: what each drew, the spend
     * ledger meta each would note, and the statements they issued.
     *
     * @return array{pages: array<string, mixed>, notes: array<string, mixed>, sql: list<string>}
     */
    private static function pages(string $zone = self::ZONE): array
    {
        self::signIn($zone);
        [$from, $to] = self::window();
        self::$log = [];
        $pages = [];
        $notes = [];
        $types = [...self::GROUPED, 'LpOverview', 'campaignOverview', 'slp_direct_link_per_ppc', 'alp_per_ppc',
            'breakdown', 'hourly', 'weekly', 'variable'];
        foreach ($types as $type) {
            $engine = new \DataEngine();
            $pages[$type] = $engine->getReportData($type, (string) $from, (string) $to, false);
            $notes[$type] = $engine->spendLedger();
        }
        // Overview as account_overview.php reads it: the chart, then the
        // tables from the request's temporary sums.
        $engine = new \DataEngine();
        $lines = [];
        foreach (['clicks', 'cost', 'cpc', 'net', 'roi'] as $metric) {
            $lines[] = ['campaign_id' => '0', 'value_type' => $metric];
        }
        $lines[] = ['campaign_id' => (string) self::CAMPAIGN, 'value_type' => 'cost'];
        $range = returnRanges(new \DateTime('@' . $from), new \DateTime('@' . $to), 'hours');
        $pages['chart'] = $engine->getChart($from, $to, $lines, 'hours', 'M d h:iA', $range);
        $engine->prepareAccountOverview($from, $to);
        foreach (['LpOverview', 'campaignOverview', 'slp_direct_link_per_ppc', 'alp_per_ppc'] as $type) {
            $pages['prepared:' . $type] = $engine->getReportData($type, (string) $from, (string) $to, false);
        }
        $notes['overview'] = $engine->spendLedger();
        unset($engine);

        // Group Overview, pre-aggregated (traffic source) and over the clicks
        // themselves (keyword), as group_overview.php runs it.
        $prefs = self::$db->query('SELECT * FROM 202_users_pref WHERE user_id = ' . self::USER)->fetch_assoc();
        foreach (['DETAIL_LEVEL_PPC_NETWORK', 'DETAIL_LEVEL_KEYWORD', 'DETAIL_LEVEL_CAMPAIGN'] as $level) {
            $form = new \ReportSummaryForm();
            $form->setDetails([constant('ReportBasicForm::' . $level)]);
            $form->setDetailsSort([\ReportBasicForm::SORT_NAME]);
            $form->setDisplayType([\ReportBasicForm::DISPLAY_TYPE_TABLE]);
            $form->setStartTime($from);
            $form->setEndTime($to);
            $rows = self::$db->query($form->getQuery((string) self::USER, $prefs));
            self::assertInstanceOf(\mysqli_result::class, $rows, self::$db->error);
            foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
                $form->addReportData($row);
            }
            $pages['group:' . $level] = (float) $form->getReportData()->getCost();
            $notes['group:' . $level] = $form->getSpendLedger();
        }

        // The overview's temporary table has a random name.
        $named = static fn (string $s): string
            => (string) preg_replace('/p202_overview_[0-9a-f]{16}/', 'p202_overview_X', $s);
        $sql = array_map($named, self::$log);

        return ['pages' => $pages, 'notes' => $notes, 'sql' => $sql];
    }

    /** A formatted totals row's cost. @param list<array<string, string>> $rows */
    private static function totalCost(array $rows): string
    {
        $last = $rows[array_key_last($rows)];

        return html_entity_decode((string) ($last['total_cost'] ?? ''));
    }

    public function testALedgerWithNothingInTheWindowLeavesEveryPageAsItWas(): void
    {
        $before = self::pages();
        self::assertNotEmpty($before['sql']);
        foreach ($before['notes'] as $page => $note) {
            self::assertNull($note, $page);
        }

        // The other account's ledger in the window, and this account's outside it.
        self::write(self::OTHER, 'ad_A', self::$hour, '9.00');
        self::write(self::USER, 'ad_A', self::$hour - 86400, '9.00');
        self::write(self::USER, 'ad_A', self::$hour + 7200, '9.00');
        $after = self::pages();

        self::assertSame($before['sql'], $after['sql'], 'every page issued the same statements, to the byte');
        self::assertSame($before['pages'], $after['pages']);
        self::assertSame($before['notes'], $after['notes']);
        foreach ($after['sql'] as $statement) {
            if (str_contains($statement, '202_spend_ledger')) {
                self::assertStringStartsWith('SELECT COUNT(*) AS n', $statement, 'only the ledger\'s count is read');
            }
        }
        // And the figures are the clicks' CPCs: 0.1 + 0.1 + 0.5 + 0.2 + 0.3 + 0.4 + 0.6.
        self::assertSame('$2.20', self::totalCost($after['pages']['campaignOverview']));
        self::assertEqualsWithDelta(2.2, $after['pages']['group:DETAIL_LEVEL_PPC_NETWORK'], 1e-9);
    }

    public function testEveryPageTotalsTheRestSummarysLedgerCost(): void
    {
        // Ad A's hour: its two clicks cost 1.50 each. Ad Z: unallocated.
        self::write(self::USER, 'ad_A', self::$hour, '3.00');
        self::write(self::USER, 'ad_Z', self::$hour, '2.25');
        self::write(self::OTHER, 'ad_A', self::$hour, '7.00');
        [$from, $to] = self::window();
        $summary = (new ReportsController(self::$db, self::USER))
            ->summary(['time_from' => (string) $from, 'time_to' => (string) $to]);
        $rest = $summary['data']['total_cost'];
        self::assertEqualsWithDelta(5.0, $rest, 1e-9);
        $money = dollar_format($rest);
        self::assertSame('$5.00', $money);

        $all = self::pages();
        $pages = $all['pages'];
        $totalled = [...self::GROUPED, 'LpOverview', 'campaignOverview', 'breakdown', 'hourly', 'weekly',
            'prepared:LpOverview', 'prepared:campaignOverview'];
        foreach ($totalled as $page) {
            self::assertSame($money, self::totalCost($pages[$page]), $page);
        }
        foreach (['slp_direct_link_per_ppc', 'prepared:slp_direct_link_per_ppc'] as $page) {
            self::assertSame($money, html_entity_decode($pages[$page][self::CAMPAIGN]['total_cost']), $page);
        }
        // Custom Variables counts the clicks with a variable set on the
        // account's own traffic source: 5.0 less the click with none (0.2)
        // and the one naming the other account's source (0.6).
        self::assertSame('$4.20', self::totalCost($pages['variable']));
        // The chart's series are the SQL's sums: cost, and Net and ROI from it.
        $series = array_column($pages['chart']['series'], 'data', 'name');
        self::assertEqualsWithDelta($rest, array_sum($series['Cost (all)']), 1e-9);
        self::assertEqualsWithDelta($rest, array_sum($series['Cost (Spring sale)']), 1e-9);
        self::assertEqualsWithDelta(7 - $rest, array_sum($series['Net (all)']), 1e-9);
        $groups = ['group:DETAIL_LEVEL_PPC_NETWORK', 'group:DETAIL_LEVEL_KEYWORD', 'group:DETAIL_LEVEL_CAMPAIGN'];
        foreach ($groups as $page) {
            self::assertEqualsWithDelta($rest, $pages[$page], 1e-9, $page);
        }

        // Each page notes how far the spend reaches, as the REST summary does.
        foreach ($all['notes'] as $page => $note) {
            self::assertIsArray($note, $page);
            self::assertSame($summary['spend_ledger']['note'], $note['note'], $page);
            self::assertEqualsWithDelta(2.25, $note['unallocated']['total_cost'], 1e-9, $page);
        }
        $html = p202_spend_ledger_note($all['notes']['overview']);
        self::assertStringContainsString('Spend data reaches', $html);
        self::assertStringContainsString('$2.25 of spend in this window is on no click here', $html);
        self::assertStringContainsString('p202-flash__body', $html);
        self::assertStringNotContainsString('$2.25', p202_spend_ledger_note($all['notes']['overview'], true), 'masked');
        self::assertSame('', p202_spend_ledger_note(null));

        // A zone off the hour reads the clicks themselves (ReportAggregation::byHour()).
        $kolkata = self::pages('Asia/Kolkata')['pages'];
        foreach (['breakdown', 'hourly', 'weekly'] as $page) {
            self::assertSame($money, self::totalCost($kolkata[$page]), "$page in Asia/Kolkata");
        }
    }
}
