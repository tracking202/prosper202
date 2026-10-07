<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /reports/groups is the Overview's Group Overview: traffic grouped by up
 * to four dimensions, each group with its totals, the clicks a level has no
 * value for kept as its `none` child so a group is the sum of its children,
 * and money summed exactly.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6001 and 6002's rows.
 *
 * @group integration
 */
final class GroupReportIntegrationTest extends TestCase
{
    private const USER = 6001;
    private const OTHER = 6002;

    private static ?\mysqli $db = null;

    /** @var array<string, int> */
    private static array $ids = [];

    /** @var list<int> keyword ids to remove */
    private static array $keywords = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
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
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
        }
        if (self::$keywords !== []) {
            self::$db->query('DELETE FROM 202_keywords WHERE keyword_id IN (' . implode(',', self::$keywords) . ')');
            self::$keywords = [];
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    private static function keyword(string $text): int
    {
        $id = self::q('INSERT INTO 202_keywords SET keyword = \'' . self::$db->real_escape_string($text) . '\'');
        self::$keywords[] = $id;

        return $id;
    }

    /** One 202_dataengine row: one click. */
    private static function click(int $user, int $campaign, int $keyword, string $income, string $cost = '0', int $lead = 0): void
    {
        static $n = 0;
        $n++;
        self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = " . (600100 + $n) . ', click_time = ' . (time() - 3600)
            . ", aff_campaign_id = $campaign, ppc_account_id = 0, landing_page_id = 0, keyword_id = $keyword, ip_id = 0,"
            . ' click_referer_site_url_id = 0, device_id = 0, click_filtered = 0, click_bot = 0,'
            . " click_lead = $lead, clicks = 1, click_out = 1, leads = $lead, payout = 0, income = $income, cost = $cost");
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        $u = self::USER;
        $a = self::q("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = 0, aff_campaign_name = 'Alpha', aff_campaign_url = 'https://a.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $b = self::q("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = 0, aff_campaign_name = 'Beta', aff_campaign_url = 'https://b.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $theirs = self::q('INSERT INTO 202_aff_campaigns SET user_id = ' . self::OTHER . ", aff_network_id = 0, aff_campaign_name = 'Theirs', aff_campaign_url = 'https://t.example', aff_campaign_payout = 1, aff_campaign_foreign_payout = 0, aff_campaign_time = 0");
        $shoes = self::keyword('gr shoes');
        $hats = self::keyword("gr hats\u{202E}");
        self::$ids = compact('a', 'b', 'theirs', 'shoes', 'hats');

        self::click($u, $a, $shoes, '1.10000');
        self::click($u, $a, $shoes, '2.20000', '0.50000', 1);
        self::click($u, $a, $hats, '0.30000');
        self::click($u, $a, 0, '5.00000', '1.00000');   // no keyword
        self::click($u, $b, $hats, '0.10000');
        self::click(self::OTHER, $theirs, $shoes, '100.00000');
    }

    private function groups(array $params): array
    {
        return (new ReportsController(self::$db, self::USER))->groups($params);
    }

    public function testGroupsNestWithExactTotalsAndTheNoneChildLast(): void
    {
        $i = self::$ids;
        $r = $this->groups(['by' => 'campaign,keyword']);
        self::assertSame(['campaign', 'keyword'], $r['by']);
        self::assertSame(['Alpha', 'Beta'], array_column($r['data'], 'name'), 'campaigns by name; another account\'s is not here');

        [$alpha, $beta] = $r['data'];
        self::assertSame($i['a'], $alpha['id']);
        self::assertSame([4, 8.6, 1.5, 7.1], [$alpha['total_clicks'], $alpha['total_income'], $alpha['total_cost'], $alpha['total_net']]);
        self::assertSame([$i['hats'], $i['shoes'], null], array_column($alpha['children'], 'id'), 'keywords by name, then the clicks with none');
        [$hats, $shoes, $none] = $alpha['children'];
        // 1.1 + 2.2 as floats is 3.3000000000000003; summed exactly it is 3.3.
        self::assertSame([2, 3.3, 1], [$shoes['total_clicks'], $shoes['total_income'], $shoes['total_leads']], '1.1 + 2.2 summed exactly');
        self::assertSame(1.65, $shoes['epc']);
        self::assertSame(50.0, $shoes['conv_rate']);
        self::assertSame([1, 5.0, null], [$none['total_clicks'], $none['total_income'], $none['name']]);
        self::assertStringNotContainsString("\u{202E}", (string) $hats['name'], 'a keyword the visitor wrote is cleaned');
        self::assertArrayNotHasKey('children', $hats, 'the innermost level has no children');

        $sum = 0;
        foreach ($alpha['children'] as $child) {
            $sum += $child['total_clicks'];
        }
        self::assertSame($alpha['total_clicks'], $sum, 'a group is the sum of its children');
        self::assertSame([5, 8.7], [$r['totals']['total_clicks'], $r['totals']['total_income']]);
        self::assertSame(1, $beta['total_clicks']);
    }

    public function testOneLevelAgreesWithTheBreakdown(): void
    {
        $controller = new ReportsController(self::$db, self::USER);
        $groups = $controller->groups(['by' => 'campaign']);
        $breakdown = array_column($controller->breakdown(['breakdown' => 'campaign'])['data'], null, 'id');
        foreach ($groups['data'] as $g) {
            $b = $breakdown[$g['id']];
            foreach (['total_clicks', 'total_leads', 'total_income', 'total_cost', 'total_net'] as $metric) {
                self::assertEqualsWithDelta((float) $b[$metric], (float) $g[$metric], 0.000001, "{$g['name']} $metric");
            }
        }
    }

    public function testSortFiltersAndRefusals(): void
    {
        $byClicks = $this->groups(['by' => 'keyword', 'sort' => 'total_clicks']);
        self::assertSame([self::$ids['shoes'], self::$ids['hats'], null], array_column($byClicks['data'], 'id'), 'most clicks first; none still last');

        $filtered = $this->groups(['by' => 'campaign,keyword', 'keyword' => 'gr shoes']);
        self::assertSame(['Alpha'], array_column($filtered['data'], 'name'));
        self::assertSame(2, $filtered['totals']['total_clicks']);

        foreach ([
            [[], 'by'],
            [['by' => 'campaign,nope'], 'by'],
            [['by' => 'campaign,keyword,country,city,region'], 'by'],
            [['by' => 'campaign,campaign'], 'by'],
            [['by' => 'campaign', 'sort' => 'bogus'], 'sort'],
            [['by' => 'campaign', 'grouping' => 'x'], 'grouping'],
        ] as [$params, $field]) {
            try {
                $this->groups($params);
                self::fail(json_encode($params) . ' was answered');
            } catch (ValidationException $e) {
                self::assertArrayHasKey($field, $e->getFieldErrors(), json_encode($params));
            }
        }
    }

    /**
     * Every dimension nests under another and over another: each one's join
     * is re-aliased per level, and a rewrite that missed an alias (ref, su,
     * dm, i6) would collide or not parse.
     */
    public function testEveryDimensionNestsAtEveryLevel(): void
    {
        foreach (ReportsController::breakdownDimensions() as $dim) {
            $other = $dim === 'campaign' ? 'keyword' : 'campaign';
            foreach (["$other,$dim", "$dim,$other", "$dim,referer,ip,device_type"] as $by) {
                if (count(array_unique(explode(',', $by))) !== count(explode(',', $by))) {
                    continue;
                }
                $r = $this->groups(['by' => $by]);
                self::assertSame(5, $r['totals']['total_clicks'], "by=$by keeps every click in the totals");
            }
        }
    }

    /**
     * A click that names another account's campaign (a tracker made before
     * the API checked linked ids) is reported under no campaign: neither a
     * breakdown nor a group shows the other account's name.
     */
    public function testAnotherAccountsCampaignIsNeverNamed(): void
    {
        self::click(self::USER, self::$ids['theirs'], self::$ids['shoes'], '1.00000');
        $controller = new ReportsController(self::$db, self::USER);
        $names = array_column($controller->breakdown(['breakdown' => 'campaign'])['data'], 'name');
        self::assertNotContains('Theirs', $names, 'a breakdown named another account\'s campaign');
        $groups = $controller->groups(['by' => 'campaign']);
        self::assertNotContains('Theirs', array_column($groups['data'], 'name'));
        $none = end($groups['data']);
        self::assertSame([null, 1], [$none['id'], $none['total_clicks']], 'the click is counted, under no campaign');
        self::assertSame(6, $groups['totals']['total_clicks']);
    }

    /**
     * Every report serves its metrics as numbers: counts as integers, money
     * and ratios as numbers. Summary, breakdown, timeseries and groups served
     * MySQL's numeric strings ("total_clicks":"6") while day- and
     * week-parting served numbers, so a client had to know which was which.
     */
    public function testEveryReportServesNumbers(): void
    {
        $controller = new ReportsController(self::$db, self::USER);
        $metricRows = [
            'summary' => [$controller->summary([])['data']],
            'breakdown' => $controller->breakdown(['breakdown' => 'keyword'])['data'],
            'timeseries' => $controller->timeseries(['interval' => 'day'])['data'],
            'groups' => [...$controller->groups(['by' => 'campaign'])['data'], $controller->groups(['by' => 'campaign'])['totals']],
            'daypart' => $controller->daypart([])['data'],
            'weekpart' => $controller->weekpart([])['data'],
        ];
        $counts = ['total_clicks', 'total_click_throughs', 'total_leads'];
        $amounts = ['total_income', 'total_cost', 'total_net', 'epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa'];
        foreach ($metricRows as $report => $rows) {
            self::assertNotSame([], $rows, "$report has rows");
            foreach ($rows as $row) {
                foreach ($counts as $field) {
                    self::assertIsInt($row[$field], "$report $field");
                }
                foreach ($amounts as $field) {
                    self::assertIsFloat($row[$field], "$report $field");
                }
            }
        }
        $shoes = array_column($metricRows['breakdown'], null, 'id')[self::$ids['shoes']];
        self::assertSame(3.3, $shoes['total_income'], 'a breakdown amount is the column\'s exact sum');

        $empty = (new ReportsController(self::$db, 6099))->summary([])['data'];
        self::assertSame(0, $empty['total_clicks'], 'no traffic is 0, not null');
        self::assertSame(0.0, $empty['total_income']);
    }

    /**
     * ppc_network_id=none (the pages' 16777215) is the clicks with no
     * traffic source, which the data engine stores as a NULL network; as an
     * id it matched no row and the report answered nothing.
     */
    public function testNoTrafficSourceIsTheClicksWithoutOne(): void
    {
        self::q('UPDATE 202_dataengine SET ppc_network_id = 7 WHERE user_id = ' . self::USER);
        self::q('UPDATE 202_dataengine SET ppc_network_id = NULL WHERE user_id = ' . self::USER . ' AND keyword_id = 0');
        $controller = new ReportsController(self::$db, self::USER);
        foreach (['none', '16777215'] as $value) {
            $summary = $controller->summary(['ppc_network_id' => $value])['data'];
            self::assertSame([1, 5.0], [$summary['total_clicks'], $summary['total_income']], "ppc_network_id=$value");
        }
        self::assertSame(4, $controller->summary(['ppc_network_id' => '7'])['data']['total_clicks']);
    }

    public function testTooManyGroupsIsRefusedNotCut(): void
    {
        $u = self::USER;
        $first = self::q("INSERT INTO 202_keywords (keyword) VALUES ('gr many 0')" . implode('', array_map(static fn (int $k): string => ", ('gr many $k')", range(1, ReportsController::GROUPS_MAX_ROWS))));
        $ids = range($first, $first + ReportsController::GROUPS_MAX_ROWS);
        array_push(self::$keywords, ...$ids);
        $rows = array_map(static fn (int $k): string => "($u, " . (610000 + $k) . ', ' . (time() - 3600) . ", $k, 1, 1, 0, 0)", $ids);
        self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, keyword_id, clicks, click_out, income, cost) VALUES ' . implode(', ', $rows));
        try {
            $this->groups(['by' => 'keyword']);
            self::fail('more than GROUPS_MAX_ROWS groups were answered');
        } catch (ValidationException $e) {
            self::assertStringContainsString('More than ' . ReportsController::GROUPS_MAX_ROWS, $e->getFieldErrors()['by'] ?? '');
        }
    }
}
