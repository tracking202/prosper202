<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;
use Prosper202\DataEngine\GroupedReportRegistry;
use Prosper202\DataEngine\MetricsSql;

/**
 * The Analyze pages' dimensions, filters and periods, and the Overview's
 * rotator breakdown, run as SQL on a real server (CLAUDE.md #9: the joins,
 * the subqueries and the IPv6 decoding are the code under test, and only
 * MySQL can say what they return). Where an Analyze page has the same
 * report, its own query (GroupedReportRegistry, MetricsSql) is run over the
 * same rows and the figures compared.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 5601 and 5602's rows.
 *
 * @group integration
 */
final class ReportDepthIntegrationTest extends TestCase
{
    private const USER = 5601;
    private const OTHER = 5602;

    /** The strict mode a MySQL 8 server runs with, ONLY_FULL_GROUP_BY included. */
    private const STRICT = "SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,"
        . "ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'";

    private static ?\mysqli $db = null;

    /** @var array<string, int> */
    private static array $ids = [];

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
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
        // So a GROUP BY that names too little fails here as it would there.
        $db->query(self::STRICT);
        self::$db = $db;
        self::seed();
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

    private static function q(string $sql): void
    {
        if (self::$db->query($sql) === false) {
            throw new \RuntimeException(self::$db->error . ' in ' . $sql);
        }
    }

    private static function insert(string $sql): int
    {
        self::q($sql);

        return (int) self::$db->insert_id;
    }

    private static function str(string $value): string
    {
        return "'" . self::$db->real_escape_string($value) . "'";
    }

    private static function cleanUp(): void
    {
        foreach (self::$ids as $key => $id) {
            [$table, $column] = match (true) {
                str_starts_with($key, 'kw') => ['202_keywords', 'keyword_id'],
                str_starts_with($key, 'v6') => ['202_ips_v6', 'ip_id'],
                str_starts_with($key, 'ip') => ['202_ips', 'ip_id'],
                str_starts_with($key, 'dom') => ['202_site_domains', 'site_domain_id'],
                str_starts_with($key, 'url') => ['202_site_urls', 'site_url_id'],
                str_starts_with($key, 'dm') => ['202_device_models', 'device_id'],
                str_starts_with($key, 'c1') => ['202_tracking_c1', 'c1_id'],
                str_starts_with($key, 'utm') => ['202_utm_source', 'utm_source_id'],
                str_starts_with($key, 'rule') => ['202_rotator_rules', 'id'],
                str_starts_with($key, 'rot') => ['202_rotators', 'id'],
                default => [null, null],
            };
            if ($table !== null) {
                self::$db->query("DELETE FROM $table WHERE $column = " . (int) $id);
            }
        }
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users WHERE user_id = $u");
        }
        self::$ids = [];
    }

    private static function seed(): void
    {
        self::cleanUp();
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'rd5601',"
            . " user_email = 'rd5601@example.com', user_dash_email = '', user_pass = 'x',"
            . " user_timezone = 'America/New_York', user_time_register = 0, install_hash = '', user_hash = ''");
        self::q('INSERT IGNORE INTO 202_device_types (type_id, type_name)'
            . " VALUES (1, 'Desktop'), (2, 'Mobile'), (3, 'Tablet'), (4, 'Bot')");

        $i = &self::$ids;
        $i['kwBlue'] = self::insert("INSERT INTO 202_keywords SET keyword = 'rd blue widgets'");
        $i['kwPct'] = self::insert("INSERT INTO 202_keywords SET keyword = 'rd 50% off'");
        $i['kwX'] = self::insert("INSERT INTO 202_keywords SET keyword = 'rd 50x offxsale'");

        $i['ip4'] = self::insert("INSERT INTO 202_ips SET ip_address = '203.0.113.210'");
        $packed = (string) inet_pton('2001:db8::5601');
        $i['v6'] = self::insert('INSERT INTO 202_ips_v6 SET ip_address = ' . self::str($packed));
        // MysqlLocationRepository::insertIp: the 202_ips row of an IPv6
        // address holds its 202_ips_v6 row's id.
        $i['ip6'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str((string) $i['v6']));
        // An IPv4 address that, compared as a number, reads as that id.
        $i['ipCollide'] = self::insert('INSERT INTO 202_ips SET ip_address = ' . self::str($i['v6'] . '.0.1.1'));
        // A reference whose 202_ips_v6 row is gone.
        $i['ipDangling'] = self::insert("INSERT INTO 202_ips SET ip_address = '987654321'");

        $i['domNews'] = self::insert("INSERT INTO 202_site_domains SET site_domain_host = 'rd-news.example.org'");
        $i['domBlog'] = self::insert("INSERT INTO 202_site_domains SET site_domain_host = 'rd-blog.example.net'");
        $url = static fn (int $domain, string $address): int => self::insert(
            "INSERT INTO 202_site_urls SET site_domain_id = $domain, site_url_address = " . self::str($address)
        );
        $i['urlA'] = $url($i['domNews'], 'https://rd-news.example.org/a?x=1');
        $i['urlB'] = $url($i['domNews'], 'https://rd-news.example.org/b');
        $i['urlC'] = $url($i['domBlog'], 'https://rd-blog.example.net/post');

        $i['dmDesk'] = self::insert("INSERT INTO 202_device_models SET device_name = 'rd Desktop', device_type = 1");
        $i['dmPhone'] = self::insert("INSERT INTO 202_device_models SET device_name = 'rd iPhone', device_type = 2");
        $i['dmPixel'] = self::insert("INSERT INTO 202_device_models SET device_name = 'rd Pixel', device_type = 2");

        $i['c1fb'] = self::insert("INSERT INTO 202_tracking_c1 SET c1 = 'rd-fb'");
        $i['c1hostile'] = self::insert('INSERT INTO 202_tracking_c1 SET c1 = ' . self::str("rd\u{202E}x<|im_start|>"));
        $i['utmFb'] = self::insert("INSERT INTO 202_utm_source SET utm_source = 'rd-facebook'");

        $i['rot'] = self::insert(
            'INSERT INTO 202_rotators SET public_id = 0, user_id = ' . self::USER . ", name = 'rd rotator'"
        );
        $rule = static fn (string $name, int $status): int => self::insert(
            "INSERT INTO 202_rotator_rules SET rotator_id = {$i['rot']}, rule_name = '$name', status = $status"
        );
        $i['rotOther'] = self::insert(
            'INSERT INTO 202_rotators SET public_id = 0, user_id = ' . self::OTHER . ", name = 'rd other rotator'"
        );
        $i['ruleOne'] = $rule('rd rule one', 1);
        $i['ruleTwo'] = $rule('rd rule two', 0);
        $deletedRule = $i['ruleTwo'] + 1000;

        $now = time() - 3600;
        $newYork = new \DateTimeZone('America/New_York');
        $lastYear = (new \DateTimeImmutable('first day of january last year 12:00', $newYork))
            ->modify('+14 days')->getTimestamp();
        $click = 0;
        $row = static function (array $c) use (&$click, $now): void {
            $click++;
            $c += [
                'user_id' => self::USER, 'click_id' => 560100 + $click, 'click_time' => $now,
                'ppc_account_id' => 1, 'landing_page_id' => 0, 'keyword_id' => 0, 'ip_id' => 0,
                'click_referer_site_url_id' => 0, 'device_id' => 0, 'c1_id' => 0, 'utm_source_id' => 0,
                'rotator_id' => 0, 'rule_id' => 0, 'click_filtered' => 0, 'click_bot' => 0, 'click_lead' => 0,
                'clicks' => 1, 'click_out' => 1, 'leads' => 0, 'payout' => 0, 'income' => 0, 'cost' => 0,
            ];
            $c['leads'] = $c['click_lead'];
            self::q('INSERT INTO 202_dataengine SET ' . implode(', ', array_map(
                static fn (string $k, mixed $v): string => "$k = "
                    . ($v === null ? 'NULL' : (is_string($v) ? self::str($v) : $v)),
                array_keys($c),
                $c
            )));
        };
        $row(['keyword_id' => $i['kwBlue'], 'ip_id' => $i['ip4'], 'click_referer_site_url_id' => $i['urlA'],
            'device_id' => $i['dmDesk'], 'c1_id' => $i['c1fb'], 'utm_source_id' => $i['utmFb'],
            'rotator_id' => $i['rot'], 'rule_id' => $i['ruleOne'],
            'click_lead' => 1, 'income' => 10, 'cost' => 1]);
        $row(['keyword_id' => $i['kwBlue'], 'ip_id' => $i['ip4'], 'click_referer_site_url_id' => $i['urlA'],
            'device_id' => $i['dmPhone'],
            'c1_id' => $i['c1fb'], 'rotator_id' => $i['rot'], 'rule_id' => 0, 'click_filtered' => 1, 'cost' => 1]);
        $row(['keyword_id' => $i['kwPct'], 'ip_id' => $i['ip6'], 'click_referer_site_url_id' => $i['urlB'],
            'device_id' => $i['dmPixel'],
            'c1_id' => $i['c1hostile'], 'rotator_id' => $i['rot'], 'rule_id' => $deletedRule, 'landing_page_id' => 5,
            'click_lead' => 1, 'income' => 20, 'cost' => 2]);
        $row(['keyword_id' => $i['kwX'], 'ip_id' => $i['ipCollide'], 'click_referer_site_url_id' => $i['urlC'],
            'device_id' => $i['dmDesk'],
            'click_filtered' => 1, 'click_bot' => 1]);
        $row(['keyword_id' => $i['kwBlue'], 'ip_id' => $i['ipDangling']]);
        $row(['keyword_id' => $i['kwBlue'], 'click_time' => $lastYear, 'click_lead' => 1, 'income' => 5]);
        // Another account's click on the same values: never in USER's figures.
        $row(['user_id' => self::OTHER, 'keyword_id' => $i['kwBlue'], 'ip_id' => $i['ip4'],
            'click_referer_site_url_id' => $i['urlA'], 'device_id' => $i['dmDesk'], 'rotator_id' => $i['rot'],
            'rule_id' => $i['ruleOne'], 'click_lead' => 1, 'income' => 99]);
        // The other account's rotator's default, once as rule 0 and once as
        // the NULL rule_id the column allows.
        $row(['user_id' => self::OTHER, 'rotator_id' => $i['rotOther'], 'rule_id' => 0, 'cost' => 1]);
        $row(['user_id' => self::OTHER, 'rotator_id' => $i['rotOther'], 'rule_id' => null, 'cost' => 2]);
    }

    private function reports(): ReportsController
    {
        return new ReportsController(self::$db, self::USER);
    }

    /**
     * name => [clicks, leads, income, cost] of a breakdown, every page.
     *
     * @return array<string, array{int, int, float, float}>
     */
    private function rows(string $dimension, array $params = []): array
    {
        $out = [];
        foreach ($this->reports()->breakdown(['breakdown' => $dimension, 'limit' => '500'] + $params)['data'] as $r) {
            $out[(string) ($r['name'] ?? '(null)')] = [
                (int) $r['total_clicks'], (int) $r['total_leads'], (float) $r['total_income'], (float) $r['total_cost'],
            ];
        }
        ksort($out);

        return $out;
    }

    /** @return array{int, int, float} clicks, leads, income */
    private function summary(array $params): array
    {
        $d = $this->reports()->summary($params)['data'];

        return [(int) $d['total_clicks'], (int) $d['total_leads'], (float) $d['total_income']];
    }

    public function testEachNewDimensionGroupsTheAccountsClicks(): void
    {
        $i = self::$ids;
        $ip = [
            '203.0.113.210' => [2, 1, 10.0, 2.0],
            '2001:db8::5601' => [1, 1, 20.0, 2.0],
            // The IPv4 address that reads as the v6 row's id is itself...
            "{$i['v6']}.0.1.1" => [1, 0, 0.0, 0.0],
            // ...and a reference whose v6 row is gone is unnamed, not "987654321".
            '(null)' => [1, 0, 0.0, 0.0],
        ];
        ksort($ip);
        self::assertSame($ip, $this->rows('ip'));

        self::assertSame(
            ['rd-blog.example.net' => [1, 0, 0.0, 0.0], 'rd-news.example.org' => [3, 2, 30.0, 4.0]],
            $this->rows('referer')
        );
        self::assertSame([
            'https://rd-blog.example.net/post' => [1, 0, 0.0, 0.0],
            'https://rd-news.example.org/a?x=1' => [2, 1, 10.0, 2.0],
            'https://rd-news.example.org/b' => [1, 1, 20.0, 2.0],
        ], $this->rows('referer_url'));
        self::assertSame(['Desktop' => [2, 1, 10.0, 1.0], 'Mobile' => [2, 1, 20.0, 3.0]], $this->rows('device_type'));

        $c1 = $this->reports()->breakdown(['breakdown' => 'c1'])['data'];
        self::assertSame(['rd-fb', 'rdx[removed]'], array_column($c1, 'name'), 'c1 is visitor-authored and sanitized');
        self::assertSame(['rd-facebook' => [1, 1, 10.0, 1.0]], $this->rows('utm_source'));
        self::assertSame(['rd rotator' => [3, 2, 30.0, 4.0]], $this->rows('rotator'));

        $rules = $this->reports()->breakdown(['breakdown' => 'rotator_rule'])['data'];
        self::assertCount(1, $rules, 'a rule with clicks; the default and a deleted rule are no rule');
        self::assertSame(
            [(int) $i['ruleOne'], 'rd rule one', (int) $i['rot'], '1'],
            [
                (int) $rules[0]['id'],
                $rules[0]['name'],
                (int) $rules[0]['rotator_id'],
                (string) $rules[0]['total_clicks'],
            ]
        );
    }

    /**
     * The Analyze pages group these by name over the same rows; with no two
     * stored values sharing a name, each page's figures are the API's.
     */
    public function testTheAnalyzePagesQueriesGiveTheSameFigures(): void
    {
        $pages = ['keyword' => 'keyword', 'referer' => 'referer', 'device' => 'device', 'ip' => 'ip'];
        foreach ($pages as $page => $dimension) {
            $definition = GroupedReportRegistry::definition($page, 'inet6_ntoa');
            self::assertNotNull($definition);
            $sql = 'SELECT ' . $definition->labelSelect . ',' . MetricsSql::GROUPED_SELECT
                . ' FROM 202_dataengine as 2st ' . $definition->joins
                . ' WHERE 2st.user_id = ' . self::USER
                . ' GROUP BY ' . $definition->groupBy;
            self::$db->query("SET SESSION sql_mode=''"); // the pages' queries are not ONLY_FULL_GROUP_BY-clean
            $result = self::$db->query($sql);
            self::$db->query(self::STRICT);
            self::assertNotFalse($result, self::$db->error);
            $page_rows = [];
            foreach ($result->fetch_all(MYSQLI_ASSOC) as $r) {
                $label = (string) array_values($r)[0];
                $page_rows[$label] = [(int) $r['clicks'], (int) $r['leads'], (float) $r['income'], (float) $r['cost']];
            }
            // The IP page joins 202_ips_v6 on `ip_id = ip_address COLLATE …`,
            // a number compared with text, so whether "<v6 id>.0.1.1" is the
            // v6 row depends on the server's evaluation (the plain comparison
            // is true on MariaDB 10.11; the page's join did not match there),
            // and the page names a dangling reference by the raw id. Those rows
            // are left out of the comparison here; the test above pins the
            // API's answer for them.
            $skip = $dimension === 'ip' ? ['(null)', self::$ids['v6'] . '.0.1.1', '2001:db8::5601'] : [];
            $api = $this->rows($dimension);
            self::assertNotSame([], $api, $dimension);
            foreach ($api as $name => $figures) {
                if (in_array($name, $skip, true)) {
                    continue;
                }
                self::assertArrayHasKey($name, $page_rows, "$dimension $name");
                self::assertSame($page_rows[$name], $figures, "$dimension $name");
            }
        }
    }

    public function testEachNewFilterNarrowsTheSummary(): void
    {
        $i = self::$ids;
        self::assertSame([6, 3, 35.0], $this->summary([]), 'all of the account\'s clicks and no other\'s');
        self::assertSame([1, 1, 20.0], $this->summary(['keyword' => '50%']), '50% is those characters, not "50" + any');
        self::assertSame([4, 2, 15.0], $this->summary(['keyword' => 'BLUE']), 'contains, case-insensitive');
        self::assertSame([2, 1, 10.0], $this->summary(['ip' => '203.0.113.210']));
        self::assertSame([1, 1, 20.0], $this->summary(['ip' => '2001:DB8:0:0:0:0:0:5601']), 'however it is written');
        self::assertSame([1, 0, 0.0], $this->summary(['ip' => "{$i['v6']}.0.1.1"]));
        self::assertSame([0, 0, 0.0], $this->summary(['ip' => '192.0.2.99']));
        self::assertSame([3, 2, 30.0], $this->summary(['referer' => 'RD-NEWS.example']));
        self::assertSame([2, 1, 20.0], $this->summary(['device_type' => '2']));
        self::assertSame([4, 3, 35.0], $this->summary(['show' => 'real']));
        self::assertSame([2, 0, 0.0], $this->summary(['show' => 'filtered']));
        self::assertSame([1, 0, 0.0], $this->summary(['show' => 'filtered_bot']));
        self::assertSame([3, 3, 35.0], $this->summary(['show' => 'leads']));
        self::assertSame([5, 2, 15.0], $this->summary(['method_of_promotion' => 'directlink']));
        self::assertSame([1, 1, 20.0], $this->summary(['method_of_promotion' => 'landingpage']));
        $combined = ['keyword' => 'blue', 'ip' => '203.0.113.210', 'show' => 'filtered'];
        self::assertSame([1, 0, 0.0], $this->summary($combined), 'filters combine');
    }

    public function testThePeriodsCountTheirClicks(): void
    {
        self::assertSame([6, 3, 35.0], $this->summary(['period' => 'alltime']));
        self::assertSame([1, 1, 5.0], $this->summary(['period' => 'lastyear']));
        self::assertSame([5, 2, 30.0], $this->summary(['period' => 'thisyear']));
        self::assertSame([5, 2, 30.0], $this->summary(['period' => 'last14']));
    }

    public function testRotatorStatsSplitsTheRotatorByTheRuleThatMatched(): void
    {
        $i = self::$ids;
        $data = $this->reports()->rotatorStats($i['rot'], [])['data'];
        self::assertSame(3, $data['totals']['total_clicks']);
        self::assertSame(30.0, $data['totals']['total_income']);
        self::assertSame(
            [
                [$i['ruleOne'], 'rd rule one', false, 1, 10.0],
                [$i['ruleTwo'], 'rd rule two', false, 0, 0.0],
                [$i['ruleTwo'] + 1000, null, true, 1, 20.0],
            ],
            array_map(
                static fn (array $r): array => [
                    $r['rule_id'], $r['rule_name'], $r['deleted'], $r['total_clicks'], $r['total_income'],
                ],
                $data['rules']
            )
        );
        self::assertSame(1, $data['default']['total_clicks']);
        $real = $this->reports()->rotatorStats($i['rot'], ['show' => 'real'])['data'];
        self::assertSame(
            [2, 0, 30.0],
            [$real['totals']['total_clicks'], $real['default']['total_clicks'], $real['totals']['total_income']],
            'the filtered default click is not real'
        );
        $this->expectException(\Api\V3\Exception\NotFoundException::class);
        (new ReportsController(self::$db, self::OTHER))->rotatorStats($i['rot'], []);
    }

    /**
     * A NULL rule_id is no rule, as 0 is: one default, not two groups of
     * which only the last was kept.
     */
    public function testANullRuleIsTheDefaultWithTheZeroRule(): void
    {
        $other = new ReportsController(self::$db, self::OTHER);
        $data = $other->rotatorStats(self::$ids['rotOther'], [])['data'];
        self::assertSame([2, 3.0], [$data['totals']['total_clicks'], $data['totals']['total_cost']]);
        self::assertSame([2, 3.0], [$data['default']['total_clicks'], $data['default']['total_cost']]);
        self::assertSame([], $data['rules']);
    }
}
