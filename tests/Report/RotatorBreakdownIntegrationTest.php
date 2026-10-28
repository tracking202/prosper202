<?php

declare(strict_types=1);

namespace Tests\Report;

use Api\V3\Controllers\ReportsController;
use PHPUnit\Framework\TestCase;
use Prosper202\DataEngine\ClickRollupSql;
use Tracking202\Report\RotatorBreakdown;

/**
 * Overview › Rotator Breakdown counts a rule's clicks by the rule that
 * matched, and counts the clicks a landing page sent through its offer
 * rotator, over the rows each rotator entry point actually leaves:
 *
 *  - rtr.php writes 202_clicks.rotator_id and, in 202_clicks.rule_id, the
 *    chosen REDIRECT's id; the rule is 202_clicks_rotator.rule_id;
 *  - offrtr.php (before it recorded the click row's rotator) wrote only
 *    202_clicks_rotator, leaving 202_clicks.rotator_id 0 — rows already
 *    written keep that, so the report has to read 202_clicks_rotator.
 *
 * The same clicks are then rolled up into 202_dataengine by the real rollup
 * (ClickRollupSql) and read back through GET /rotators/{id}/stats
 * (ReportsController::rotatorStats): the page and the API must agree, rule
 * by rule.
 *
 * @group integration
 */
final class RotatorBreakdownIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 5711;
    private const OTHER = 5712;

    /** @var array<string, int> */
    private static array $ids = [];

    private static int $now = 0;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::cleanUp();
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
        self::requireScratch();
    }

    private static function cleanUp(): void
    {
        foreach ([self::USER, self::OTHER] as $u) {
            self::$db->query("DELETE cr FROM 202_clicks_rotator cr INNER JOIN 202_clicks c ON c.click_id = cr.click_id WHERE c.user_id = $u");
            self::$db->query("DELETE FROM 202_clicks WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_dataengine WHERE user_id = $u");
            self::$db->query("DELETE ru FROM 202_rotator_rules ru INNER JOIN 202_rotators ro ON ro.id = ru.rotator_id WHERE ro.user_id = $u");
            self::$db->query("DELETE FROM 202_rotators WHERE user_id = $u");
            self::$db->query("DELETE FROM 202_users WHERE user_id = $u");
        }
    }

    private static function seed(): void
    {
        self::user(self::USER);
        self::user(self::OTHER);
        $i = &self::$ids;
        $i['rot'] = self::insert('INSERT INTO 202_rotators SET public_id = 0, user_id = ' . self::USER . ", name = 'rb rotator'");
        $i['other'] = self::insert('INSERT INTO 202_rotators SET public_id = 0, user_id = ' . self::OTHER . ", name = 'rb other'");
        $i['ruleA'] = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = {$i['rot']}, rule_name = 'rb rule A', status = 1");
        $i['ruleB'] = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = {$i['rot']}, rule_name = 'rb rule B', status = 1");
        $i['ruleEmpty'] = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = {$i['rot']}, rule_name = 'rb rule unused', status = 1");
        $deleted = $i['ruleEmpty'] + 1000;
        $i['deleted'] = $deleted;

        self::$now = time() - 600;
        $n = 0;
        // $c: the 202_clicks row as its writer left it; $cr: the
        // 202_clicks_rotator row, or null for a click that met no rotator.
        $click = static function (array $c, ?array $cr) use (&$n): void {
            $n++;
            $id = 571100 + $n;
            self::row('202_clicks', $c + [
                'click_id' => $id, 'user_id' => self::USER, 'aff_campaign_id' => 0, 'landing_page_id' => 0,
                'ppc_account_id' => 0, 'click_cpc' => 0.5, 'click_payout' => 0, 'click_lead' => 0,
                'click_filtered' => 0, 'click_bot' => 0, 'click_alp' => 0, 'click_time' => self::$now,
                'rotator_id' => 0, 'rule_id' => 0,
            ]);
            if ($cr !== null) {
                self::row('202_clicks_rotator', ['click_id' => $id] + $cr + ['rule_redirect_id' => 0]);
            }
        };
        $rot = $i['rot'];
        // rtr.php through rule A: 202_clicks.rule_id is the redirect chosen,
        // here once an id equal to rule B's (a merge) and once another (a split).
        $click(['rotator_id' => $rot, 'rule_id' => $i['ruleB'], 'click_lead' => 1, 'click_payout' => 10],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleA'], 'rule_redirect_id' => $i['ruleB']]);
        $click(['rotator_id' => $rot, 'rule_id' => $i['ruleB'] + 50000],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleA'], 'rule_redirect_id' => $i['ruleB'] + 50000]);
        // rtr.php through rule B, and its default.
        $click(['rotator_id' => $rot, 'rule_id' => $i['ruleB'] + 50001, 'click_lead' => 1, 'click_payout' => 4],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleB'], 'rule_redirect_id' => $i['ruleB'] + 50001]);
        $click(['rotator_id' => $rot, 'rule_id' => 0], ['rotator_id' => $rot, 'rule_id' => 0]);
        // offrtr.php before this fix: the click row never names the rotator.
        $click(['landing_page_id' => 7, 'click_lead' => 1, 'click_payout' => 6],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleA']]);
        $click(['landing_page_id' => 7], ['rotator_id' => $rot, 'rule_id' => 0]);
        // A rule deleted since, and a filtered click through rule B.
        $click(['rotator_id' => $rot, 'rule_id' => 1], ['rotator_id' => $rot, 'rule_id' => $deleted]);
        $click(['rotator_id' => $rot, 'rule_id' => $i['ruleB'] + 50001, 'click_filtered' => 1],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleB']]);
        // Outside the window; another account's clicks, through its own
        // rotator and through this one; a click that met no rotator.
        $click(['rotator_id' => $rot, 'click_time' => self::$now - 86400 * 400],
            ['rotator_id' => $rot, 'rule_id' => $i['ruleA']]);
        $click(['user_id' => self::OTHER, 'rotator_id' => $i['other']], ['rotator_id' => $i['other'], 'rule_id' => 0]);
        $click(['user_id' => self::OTHER, 'rotator_id' => $rot], ['rotator_id' => $rot, 'rule_id' => $i['ruleA']]);
        $click([], null);

        // The real rollup, as rtr.php and the dataengine job run it.
        self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.user_id IN (' . self::USER . ', ' . self::OTHER . ')'));
    }

    /** @return array<string, array{int, int, float, float}> label => clicks, leads, income, cost */
    private function page(string $show = 'all'): array
    {
        $rotators = (new RotatorBreakdown(self::$db))->rotators(self::USER, self::$now - 3600, self::$now + 3600, $show);
        self::assertSame(['rb rotator'], array_column($rotators, 'name'), "only the account's own rotators");
        $r = $rotators[0];
        $f = static fn (array $x): array => [$x['clicks'], $x['leads'], round($x['income'], 5), round($x['cost'], 5)];
        $out = ['totals' => $f($r['totals']), 'default' => $f($r['default'])];
        foreach ($r['rules'] as $rule) {
            $out[$rule['deleted'] ? 'deleted:' . $rule['id'] : (string) $rule['name']] = $f($rule['figures']);
        }

        return $out;
    }

    public function testRulesAreCountedByTheRuleThatMatchedAndOfferRotatorClicksAreIn(): void
    {
        self::assertSame([
            'totals' => [8, 3, 20.0, 4.0],
            'default' => [2, 0, 0.0, 1.0],
            'rb rule A' => [3, 2, 16.0, 1.5],
            'rb rule B' => [2, 1, 4.0, 1.0],
            'rb rule unused' => [0, 0, 0.0, 0.0],
            'deleted:' . self::$ids['deleted'] => [1, 0, 0.0, 0.5],
        ], $this->page());
    }

    public function testTheShowPreferenceNarrowsEveryRow(): void
    {
        $real = $this->page('real');
        self::assertSame([7, 3, 20.0, 3.5], $real['totals']);
        self::assertSame([1, 1, 4.0, 0.5], $real['rb rule B']);
    }

    public function testThePageAndTheApiAgreeRuleByRule(): void
    {
        $api = (new ReportsController(self::$db, self::USER))->rotatorStats(self::$ids['rot'], [
            'time_from' => (string) (self::$now - 3600),
            'time_to' => (string) (self::$now + 3600),
        ])['data'];
        $f = static fn (array $m): array => [
            $m['total_clicks'], $m['total_leads'], round($m['total_income'], 5), round($m['total_cost'], 5),
        ];
        $fromApi = ['totals' => $f($api['totals']), 'default' => $f($api['default'])];
        foreach ($api['rules'] as $rule) {
            $fromApi[$rule['deleted'] ? 'deleted:' . $rule['rule_id'] : (string) $rule['rule_name']] = $f($rule);
        }

        self::assertSame($fromApi, $this->page());
    }
}
