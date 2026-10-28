<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * One figure, one answer: GET /reports/summary, /reports/breakdown and
 * /reports/groups answer every metric of the same rows identically, to the
 * digit and as the same JSON type. The groups report divided its sums in
 * PHP's floats, so one lead in three click-throughs was a conv_rate of
 * 33.33333333333333 there and 33.3333 in the summary (measured live), and
 * an EPC of 1.3333333333333333 against 1.333333333.
 *
 * The rows are built for the cases where float division and the
 * database's DECIMAL division part: a repeating quotient (truncated, then
 * rounded half away from zero to the column's decimals), a negative ROI and
 * a negative EPC, and the denominators that are 0 (a group with no clicks,
 * one with no leads, no click-throughs, no cost, and NULL columns). Then a
 * seeded random set, nested three deep, is held level by level to the
 * breakdown and the summary filtered to each group's path.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6011 and 6012's rows.
 *
 * @group integration
 */
final class ReportRatiosAgreeIntegrationTest extends TestCase
{
    private const CRAFTED = 6011;
    private const RANDOM = 6012;

    /** The filter that narrows a report to one group of each dimension. */
    private const FILTERS = [
        'campaign' => 'aff_campaign_id',
        'ppc_network' => 'ppc_network_id',
        'country' => 'country_id',
    ];

    private const METRICS = [
        'total_clicks', 'total_click_throughs', 'total_leads', 'total_income', 'total_cost', 'total_net',
        'epc', 'avg_cpc', 'conv_rate', 'roi', 'cpa',
    ];

    private static ?\mysqli $db = null;

    /** @var array<string, int> crafted campaign name => id */
    private static array $campaigns = [];

    /** @var list<int> */
    private static array $countries = [];

    private static int $clickId = 620000;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
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
        self::cleanUp();
        self::seedCrafted();
        self::seedRandom();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
    }

    private static function cleanUp(): void
    {
        foreach ([self::CRAFTED, self::RANDOM] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_aff_campaigns WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_ppc_networks WHERE user_id = $u");
        }
        self::$db->query("DELETE FROM 202_locations_country WHERE country_name LIKE 'Ratio Land %'");
        self::$countries = [];
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    private static function campaign(int $user, string $name): int
    {
        return self::q("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 0,"
            . " aff_campaign_name = '$name', aff_campaign_url = 'https://ratio.example', aff_campaign_payout = 1,"
            . ' aff_campaign_foreign_payout = 0, aff_campaign_time = 0');
    }

    /**
     * One 202_dataengine row. A null figure is stored as NULL, as the
     * nullable click_out, leads, income and cost columns allow.
     *
     * @param array{int, ?int, ?int, ?string, ?string} $figures clicks, click_out, leads, income, cost
     */
    private static function row(int $user, int $campaign, ?int $ppcNetwork, int $country, array $figures): void
    {
        [$clicks, $out, $leads, $income, $cost] = $figures;
        $sql = static fn (int|string|null $v): string => $v === null ? 'NULL' : (string) $v;
        self::$clickId++;
        self::q('INSERT INTO 202_dataengine SET user_id = ' . $user . ', click_id = ' . self::$clickId
            . ', click_time = ' . (time() - 3600)
            . ", aff_campaign_id = $campaign, ppc_network_id = " . $sql($ppcNetwork) . ", country_id = $country,"
            . ' ppc_account_id = 0, landing_page_id = 0, keyword_id = 0, ip_id = 0, click_referer_site_url_id = 0,'
            . ' device_id = 0, click_filtered = 0, click_bot = 0, click_lead = ' . ($leads ?? 0) . ', payout = 0,'
            . " clicks = $clicks, click_out = {$sql($out)}, leads = {$sql($leads)},"
            . " income = {$sql($income)}, cost = {$sql($cost)}");
    }

    /**
     * Each campaign is one case, its rows split so the figures are sums.
     * The expected ratios are what the database's DECIMAL division answers.
     */
    private static function seedCrafted(): void
    {
        $u = self::CRAFTED;
        $cases = [
            'thirds'    => [[1, 1, 1, '4.00000', '0.25000'], [2, 2, 0, '0', '0.25000']],
            'sixths'    => [[2, 2, 1, '1.00000', '0.20000'], [4, 4, 0, '3.00000', '0.40000']],
            'no clicks' => [[0, 0, 1, '5.00000', '0'], [0, 0, 0, '0', '0']],
            'no leads'  => [[3, 3, 0, '0', '0.61500'], [4, 4, 0, '0', '0.61500']],
            'loss'      => [[1, 1, 1, '1.00000', '1.00000'], [2, 1, 0, '0', '2.00000']],
            'reversal'  => [[2, 2, 1, '5.00000', '0'], [2, 2, 0, '-7.50000', '0']],
            'nulls'     => [[2, null, null, null, null]],
        ];
        foreach ($cases as $name => $rows) {
            $id = self::campaign($u, "ra $name");
            self::$campaigns[$name] = $id;
            foreach ($rows as $figures) {
                self::row($u, $id, null, 0, $figures);
            }
        }
    }

    /**
     * Two traffic sources and the clicks with none (NULL and the 0 of rows
     * rolled up before the rollup wrote NULL), five campaigns, two
     * countries and none; a click's figures at random, money to five
     * decimals, some of it negative, some figures NULL.
     */
    private static function seedRandom(): void
    {
        $u = self::RANDOM;
        $source = static fn (int $n): int => self::q(
            "INSERT INTO 202_ppc_networks SET user_id = $u, ppc_network_name = 'ra source $n', ppc_network_time = 0"
        );
        $sources = [$source(1), $source(2), null, 0];
        $campaigns = array_map(static fn (int $n): int => self::campaign($u, "ra random $n"), range(1, 5));
        foreach ([1, 2] as $n) {
            self::$countries[] = self::q(
                "INSERT INTO 202_locations_country SET country_code = 'Q$n', country_name = 'Ratio Land $n'"
            );
        }
        $countries = [...self::$countries, 0];

        mt_srand(20261023);
        $money = static fn (int $lo, int $hi): string => sprintf('%.5F', mt_rand($lo * 100000, $hi * 100000) / 100000);
        for ($i = 0; $i < 600; $i++) {
            $clicks = mt_rand(0, 3);
            $out = mt_rand(0, $clicks);
            $leads = mt_rand(0, $out);
            $figures = [$clicks, $out, $leads, $leads > 0 ? $money(-20, 90) : '0', $money(0, 3)];
            if (mt_rand(0, 19) === 0) {
                $figures = [$clicks, null, null, null, null];
            }
            self::row($u, $campaigns[mt_rand(0, 4)], $sources[mt_rand(0, 3)], $countries[mt_rand(0, 2)], $figures);
        }
    }

    /** @return array<string, int|float> a report row's metrics, in one order */
    private static function metrics(array $row): array
    {
        $out = [];
        foreach (self::METRICS as $field) {
            self::assertArrayHasKey($field, $row);
            $out[$field] = $row[$field];
        }

        return $out;
    }

    public function testTheCraftedCasesAnswerTheDatabasesDivisionInEveryReport(): void
    {
        $c = new ReportsController(self::$db, self::CRAFTED);
        $groups = array_column($c->groups(['by' => 'campaign'])['data'], null, 'id');
        $breakdown = array_column($c->breakdown(['breakdown' => 'campaign'])['data'], null, 'id');
        self::assertCount(count(self::$campaigns), $groups);

        // [epc, avg_cpc, conv_rate, roi, cpa]
        $expected = [
            'thirds'    => [1.333333333, 0.166666666, 33.3333, 700.0, 0.5],
            'sixths'    => [0.666666666, 0.1, 16.6667, 566.666666667, 0.6],
            'no clicks' => [0.0, 0.0, 0.0, 0.0, 0.0],
            'no leads'  => [0.0, 0.175714285, 0.0, -100.0, 0.0],
            'loss'      => [0.333333333, 1.0, 50.0, -66.666666667, 3.0],
            'reversal'  => [-0.625, 0.0, 25.0, 0.0, 0.0],
            'nulls'     => [0.0, 0.0, 0.0, 0.0, 0.0],
        ];
        foreach (self::$campaigns as $name => $id) {
            $group = self::metrics($groups[$id]);
            self::assertSame(
                $expected[$name],
                [$group['epc'], $group['avg_cpc'], $group['conv_rate'], $group['roi'], $group['cpa']],
                "$name: epc, avg_cpc, conv_rate, roi, cpa"
            );
            self::assertSame(self::metrics($breakdown[$id]), $group, "$name: groups and breakdown");
            $summary = $c->summary(['aff_campaign_id' => (string) $id])['data'];
            self::assertSame(self::metrics($summary), $group, "$name: groups and summary");
        }
        self::assertSame(0, $groups[self::$campaigns['no clicks']]['total_clicks'], 'a group with no clicks');
        self::assertSame(0, $groups[self::$campaigns['no leads']]['total_leads'], 'a group with no leads');
        self::assertSame(-2.5, $groups[self::$campaigns['reversal']]['total_net']);
        $totals = $c->groups(['by' => 'campaign'])['totals'];
        self::assertSame(self::metrics($c->summary([])['data']), self::metrics($totals), 'totals and summary');
    }

    /**
     * Every group at every level, under either order of the levels, against
     * the breakdown and the summary filtered to its path. A group a level has
     * no value for can be filtered to only as a traffic source (`none`); the
     * others are still in their parent's figures, which are checked.
     */
    public function testEveryGroupAtEveryLevelIsTheBreakdownAndTheSummaryOfItsRows(): void
    {
        $c = new ReportsController(self::$db, self::RANDOM);
        $summary = self::metrics($c->summary([])['data']);
        self::assertGreaterThan(0, $summary['total_leads'], 'the random rows convert');
        foreach (['ppc_network,campaign,country', 'country,campaign,ppc_network', 'campaign,country'] as $by) {
            $r = $c->groups(['by' => $by]);
            self::assertSame($summary, self::metrics($r['totals']), "by=$by: totals and summary");
            $checked = $this->compareLevel($c, $r['data'], explode(',', $by), 0, []);
            self::assertGreaterThan(10, $checked, "by=$by checked its groups");
        }
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $levels
     * @param array<string, string> $filters
     * @return int the groups compared
     */
    private function compareLevel(ReportsController $c, array $nodes, array $levels, int $depth, array $filters): int
    {
        $dim = $levels[$depth];
        $param = self::FILTERS[$dim];
        $breakdown = array_column($c->breakdown(['breakdown' => $dim] + $filters)['data'], null, 'id');
        $path = $dim . ' under ' . json_encode($filters);
        $named = array_values(array_filter(array_column($nodes, 'id'), static fn ($id): bool => $id !== null));
        self::assertEqualsCanonicalizing(array_keys($breakdown), $named, "$path: the breakdown's rows are the groups");

        $checked = 0;
        foreach ($nodes as $node) {
            if ($node['id'] === null && $dim !== 'ppc_network') {
                continue;
            }
            $id = $node['id'] === null ? 'none' : (string) $node['id'];
            $here = $filters + [$param => $id];
            if ($node['id'] !== null) {
                $row = $breakdown[$node['id']];
                self::assertSame(self::metrics($row), self::metrics($node), "$path: $id, groups and breakdown");
            }
            $summary = $c->summary($here)['data'];
            self::assertSame(self::metrics($summary), self::metrics($node), "$path: $id, groups and summary");
            $checked++;
            if (isset($node['children'])) {
                $checked += $this->compareLevel($c, $node['children'], $levels, $depth + 1, $here);
            }
        }

        return $checked;
    }
}
