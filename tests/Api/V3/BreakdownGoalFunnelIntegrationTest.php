<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ReportsController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * GET /reports/breakdown?goals=: each row's goal funnel, run as SQL on a
 * real server (CLAUDE.md #9). Campaign 316's clicks from two traffic sources
 * reach sign-up, trial and first payment; the seed plants what must not
 * count: a superseded outcome, another account's outcome naming one of the
 * account's clicks, a second outcome of one goal on one click, another
 * campaign's click, and a payment reached without the steps before it.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes users 6201 and 6202's rows.
 *
 * @group integration
 */
final class BreakdownGoalFunnelIntegrationTest extends TestCase
{
    private const USER = 6201;
    private const OTHER = 6202;
    private const CAMPAIGN = 316;

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

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            $tables = ['202_goal_outcomes', '202_purlink_goal_subjects', '202_goals',
                '202_ppc_networks', '202_dataengine', '202_users'];
            foreach ($tables as $table) {
                self::$db->query("DELETE FROM $table WHERE user_id = $u");
            }
        }
        self::$ids = [];
    }

    private static function insertId(string $sql): int
    {
        self::q($sql);

        return (int) self::$db->insert_id;
    }

    private static function seed(): void
    {
        self::cleanUp();
        foreach ([self::USER, self::OTHER] as $u) {
            self::q("INSERT INTO 202_users SET user_id = $u, user_name = 'gf$u',"
                . " user_email = 'gf$u@example.com', user_dash_email = '', user_pass = 'x',"
                . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = ''");
        }
        $now = time() - 3600;
        foreach (['search' => self::USER, 'social' => self::USER, 'theirs' => self::OTHER] as $name => $user) {
            self::$ids["net_$name"] = self::insertId("INSERT INTO 202_ppc_networks SET user_id = $user,"
                . " ppc_network_name = 'gf $name', ppc_network_time = $now");
        }
        $goal = static fn (int $user, string $name, string $archived = 'NULL'): int => self::insertId(
            "INSERT INTO 202_goals SET user_id = $user, scope = 'campaign', scope_id = " . self::CAMPAIGN
            . ", name = '$name', current_version = 1, archived_at = $archived, created_at = $now, updated_at = $now"
        );
        self::$ids['signup'] = $goal(self::USER, 'Sign-up');
        self::$ids['trial'] = $goal(self::USER, 'Trial');
        self::$ids['payment'] = $goal(self::USER, 'First payment');
        self::$ids['old'] = $goal(self::USER, 'Old trial', (string) $now);
        self::$ids['their_goal'] = $goal(self::OTHER, 'Sign-up');

        $click = static function (
            string $key,
            int $network,
            int $campaign = self::CAMPAIGN,
            int $user = self::USER
        ) use ($now): int {
            $keys = array_keys(self::$ids);
            $clicks = array_filter($keys, static fn (string $k): bool => str_starts_with($k, 'click_'));
            $id = 620100 + count($clicks);
            self::$ids["click_$key"] = $id;
            self::q("INSERT INTO 202_dataengine SET user_id = $user, click_id = $id, click_time = $now,"
                . " aff_campaign_id = $campaign, ppc_account_id = 0, ppc_network_id = $network, clicks = 1,"
                . ' keyword_id = 0, landing_page_id = 0, ip_id = 0, click_referer_site_url_id = 0, device_id = 0,'
                . ' c1_id = 0, utm_source_id = 0, rotator_id = 0, rule_id = 0,'
                . ' click_out = 1, leads = 0, click_lead = 0, click_filtered = 0, click_bot = 0,'
                . ' payout = 0, income = 0, cost = 0');

            return $id;
        };
        $outcome = static function (
            string $subjectType,
            int $subject,
            int $goalId,
            int $n = 1,
            string $superseded = 'NULL',
            int $user = self::USER
        ) use ($now): void {
            self::q("INSERT INTO 202_goal_outcomes SET user_id = $user, subject_type = '$subjectType',"
                . " subject_id = $subject, goal_id = $goalId, goal_version = 1, n = $n,"
                . " event_id = 'e$subject-$goalId-$n',"
                . " reached_at = $now, value_source = 'none', superseded_at = $superseded, created_at = $now");
        };
        [$s, $t, $p] = [self::$ids['signup'], self::$ids['trial'], self::$ids['payment']];
        $search = self::$ids['net_search'];
        $social = self::$ids['net_social'];

        // Search: five clicks. Three sign up, two of those start a trial, one
        // of those pays; a fifth pays without either step before it.
        $a = $click('a', $search);
        $outcome('click', $a, $s);
        $outcome('click', $a, $s, 2);
        $outcome('click', $a, $t);
        $outcome('click', $a, $p);
        $b = $click('b', $search);
        $outcome('click', $b, $s);
        $outcome('click', $b, $t);
        $c = $click('c', $search);
        $outcome('click', $c, $s);
        $click('d', $search);
        $e = $click('e', $search);
        $outcome('click', $e, $p);

        // Social: two clicks. One signs up through its PurLink subject; the
        // other's sign-up was superseded, and another account's outcome
        // names it.
        $f = $click('f', $social);
        $purlink = self::insertId('INSERT INTO 202_purlink_goal_subjects SET user_id = ' . self::USER
            . ", click_id = $f, landing_page_id = 1, campaign_id = " . self::CAMPAIGN);
        $outcome('purlink', $purlink, $s);
        $g = $click('g', $social);
        $outcome('click', $g, $s, 1, (string) $now);
        $outcome('click', $g, $t, 1, 'NULL', self::OTHER);
        $outcome('click', $g, self::$ids['their_goal'], 1, 'NULL', self::OTHER);

        // No traffic source: in the totals, in no row.
        $h = $click('h', 0);
        $outcome('click', $h, $s);

        // Another campaign's click, through every step: filtered out.
        $i = $click('i', $search, 317);
        $outcome('click', $i, $s);
        $outcome('click', $i, $t);
        $outcome('click', $i, $p);
    }

    /** @return array<string, mixed> */
    private function report(array $params): array
    {
        return (new ReportsController(self::$db, self::USER))->breakdown($params);
    }

    private function funnel(): string
    {
        return self::$ids['signup'] . ',' . self::$ids['trial'] . ',' . self::$ids['payment'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function rowsByName(array $answer): array
    {
        return array_column($answer['data'], null, 'name');
    }

    public function testOneCallShowsTheFunnelByTrafficSource(): void
    {
        $answer = $this->report([
            'breakdown' => 'ppc_network',
            'aff_campaign_id' => (string) self::CAMPAIGN,
            'goals' => $this->funnel(),
        ]);
        $rows = self::rowsByName($answer);
        [$s, $t, $p] = [self::$ids['signup'], self::$ids['trial'], self::$ids['payment']];
        $step = static fn (int $goal, int $reached, int $continued, float $rate, float $stepRate, int $drop): array => [
            'goal_id' => $goal, 'reached' => $reached, 'continued' => $continued,
            'rate' => $rate, 'step_rate' => $stepRate, 'drop_off' => $drop,
        ];
        $named = static fn (int $goal, string $name): array => [
            'goal_id' => $goal, 'name' => $name, 'scope' => 'campaign',
            'scope_id' => self::CAMPAIGN, 'archived' => false,
        ];

        self::assertSame(['gf search', 'gf social'], array_keys($rows));
        self::assertSame(5, $rows['gf search']['total_clicks']);
        self::assertSame([
            $step($s, 3, 3, 60.0, 60.0, 2),
            $step($t, 2, 2, 40.0, 66.6667, 1),
            $step($p, 2, 1, 40.0, 50.0, 1),
        ], $rows['gf search']['goals']);

        self::assertSame(2, $rows['gf social']['total_clicks']);
        self::assertSame([
            $step($s, 1, 1, 50.0, 50.0, 1),
            $step($t, 0, 0, 0.0, 0.0, 1),
            $step($p, 0, 0, 0.0, 0.0, 0),
        ], $rows['gf social']['goals'], 'the PurLink sign-up counts; the superseded one and the other account\'s not');

        self::assertSame(
            [
                $named($s, 'Sign-up'),
                $named($t, 'Trial'),
                $named($p, 'First payment'),
            ],
            $answer['goals']
        );
        self::assertSame(8, $answer['goal_totals']['total_clicks'], 'the click with no traffic source is counted');
        self::assertSame([5, 2, 2], array_column($answer['goal_totals']['goals'], 'reached'));
        self::assertSame([5, 2, 1], array_column($answer['goal_totals']['goals'], 'continued'));
        self::assertSame(62.5, $answer['goal_totals']['goals'][0]['rate']);
    }

    public function testTheFunnelLeavesTheRowsMetricsAsTheyWere(): void
    {
        $base = ['breakdown' => 'ppc_network', 'aff_campaign_id' => (string) self::CAMPAIGN];
        $plain = $this->report($base);
        $withGoals = $this->report($base + ['goals' => $this->funnel()]);

        self::assertArrayNotHasKey('goals', $plain);
        self::assertArrayNotHasKey('goal_totals', $plain);
        foreach ($plain['data'] as $row) {
            self::assertArrayNotHasKey('goals', $row);
        }
        $stripped = array_map(static function (array $row): array {
            unset($row['goals']);

            return $row;
        }, $withGoals['data']);
        self::assertSame($plain['data'], $stripped, 'outcomes never repeat a click row');
        self::assertArrayNotHasKey('goals', $this->report($base + ['goals' => ''])['data'][0]);
    }

    public function testAnArchivedGoalIsAStepAndSaysSo(): void
    {
        $answer = $this->report(['breakdown' => 'ppc_network', 'goals' => (string) self::$ids['old']]);

        self::assertTrue($answer['goals'][0]['archived']);
        self::assertSame([0], array_column($answer['goal_totals']['goals'], 'reached'));
    }

    /** @return iterable<string, array{0: string|array<int, string>, 1: string}> */
    public static function malformedGoals(): iterable
    {
        yield 'not a number' => ['abc', '"abc" is not a goal id'];
        yield 'trailing junk' => ['12abc', '"12abc" is not a goal id'];
        yield 'zero' => ['0', '"0" is not a goal id'];
        yield 'empty step' => ['1,,2', '"" is not a goal id'];
        yield 'a list' => [['1'], 'Takes one value'];
        yield 'eleven' => [implode(',', range(1, 11)), 'At most 10 goals'];
    }

    /** @dataProvider malformedGoals */
    public function testAMalformedGoalsIsRefusedNamingIt(string|array $goals, string $says): void
    {
        $this->assertRefused($goals, $says);
    }

    public function testAGoalNamedTwiceIsRefused(): void
    {
        $this->assertRefused(self::$ids['signup'] . ',' . self::$ids['signup'], 'is named twice');
    }

    public function testAnotherAccountsGoalIsRefusedAsUnknown(): void
    {
        $theirs = self::$ids['their_goal'];
        $this->assertRefused(self::$ids['signup'] . ',' . $theirs, 'Not one of this account\'s goals: ' . $theirs);
        $this->assertRefused('4294967295', 'Not one of this account\'s goals: 4294967295');
    }

    private function assertRefused(string|array $goals, string $says): void
    {
        try {
            $this->report(['breakdown' => 'ppc_network', 'goals' => $goals]);
            self::fail('goals=' . json_encode($goals) . ' was accepted');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->getCode());
            self::assertArrayHasKey('goals', $e->getFieldErrors());
            self::assertStringContainsString($says, $e->getFieldErrors()['goals']);
        }
    }
}
