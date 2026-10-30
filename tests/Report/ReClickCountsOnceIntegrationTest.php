<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Database\Connection;
use Tracking202\Report\RotatorBreakdown;

/**
 * The report pages that count 202_clicks rows themselves count a re-clicked
 * click once, under its latest visit: Overview › Rotator Breakdown and the
 * legacy API's reports.
 *
 * A redirector re-click gives the click another 202_clicks and
 * 202_clicks_spy row at the re-click's time, with the route and campaign it
 * was sent to and the first visit's traffic source and cost, and rewrites
 * its one 202_clicks_rotator row, and records the latest visit
 * (MysqlClickRepository::recordReClick()). Click X is a click on landing page 3 and campaign A through rule A,
 * re-clicked onto page 2 and campaign B through rule B; it converted (the
 * ledger marks every row). Click Y is an ordinary click on page 1 and
 * campaign A.
 *
 * @group integration
 */
final class ReClickCountsOnceIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990871;
    private const X = 99087101;
    private const Y = 99087102;

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
        $u = self::USER;
        $tables = ['202_clicks', '202_clicks_spy', '202_clicks_rotator', '202_clicks_advance', '202_clicks_record',
            '202_click_latest_visits'];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id IN (" . self::X . ', ' . self::Y . ')');
        }
        self::q('DELETE ru FROM 202_rotator_rules ru INNER JOIN 202_rotators ro ON ro.id = ru.rotator_id'
            . " WHERE ro.user_id = $u");
        foreach (['202_rotators', '202_aff_campaigns', '202_landing_pages', '202_users'] as $table) {
            self::q("DELETE FROM $table WHERE user_id = $u");
        }
        self::q("DELETE FROM 202_keywords WHERE keyword = 'reclick once kw'");
    }

    private static function seed(): void
    {
        $u = self::USER;
        self::user($u);
        $i = &self::$ids;
        $i['rot'] = self::insert("INSERT INTO 202_rotators SET public_id = 0, user_id = $u, name = 'reclick rotator'");
        foreach (['ruleA' => 'reclick A', 'ruleB' => 'reclick B'] as $key => $name) {
            $i[$key] = self::insert("INSERT INTO 202_rotator_rules SET rotator_id = {$i['rot']}, rule_name = "
                . self::str($name) . ', status = 1');
        }
        foreach (['campA' => 'reclick campaign A', 'campB' => 'reclick campaign B'] as $key => $name) {
            $i[$key] = self::insert("INSERT INTO 202_aff_campaigns SET user_id = $u, aff_network_id = 1,"
                . ' aff_campaign_name = ' . self::str($name) . ", aff_campaign_url = 'http://x',"
                . ' aff_campaign_payout = 1, aff_campaign_time = 1, aff_campaign_foreign_payout = 1');
        }
        $i['kw'] = self::insert("INSERT INTO 202_keywords SET keyword = 'reclick once kw'");
        foreach (['lp1' => 'reclick page 1', 'lp2' => 'reclick page 2', 'lp3' => 'reclick page 3'] as $key => $name) {
            $i[$key] = self::insert("INSERT INTO 202_landing_pages SET user_id = $u, aff_campaign_id = {$i['campA']},"
                . ' landing_page_nickname = ' . self::str($name) . ", landing_page_url = 'http://lp',"
                . ' landing_page_time = 1');
        }

        self::$now = time() - 3600;
        $visit = static function (int $clickId, array $row) use ($u): void {
            $row += [
                'click_id' => $clickId, 'user_id' => $u, 'aff_campaign_id' => 0, 'landing_page_id' => 0,
                'ppc_account_id' => 3, 'click_cpc' => 0.25, 'click_payout' => 0, 'click_lead' => 0,
                'click_filtered' => 0, 'click_bot' => 0, 'click_alp' => 0, 'click_time' => self::$now,
                'rotator_id' => 0, 'rule_id' => 0,
            ];
            self::row('202_clicks', $row);
            unset($row['rotator_id'], $row['rule_id']);
            self::row('202_clicks_spy', $row);
        };
        $route = static function (int $clickId, int $ruleId, int $redirectId) use ($i): void {
            self::row('202_clicks_rotator', [
                'click_id' => $clickId, 'rotator_id' => $i['rot'], 'rule_id' => $ruleId,
                'rule_redirect_id' => $redirectId,
            ]);
        };
        // X: a click through rule A, then its re-click through rule B, 600 s
        // later; the ledger set its lead on both rows.
        $lead = ['click_lead' => 1, 'click_payout' => 10, 'rotator_id' => $i['rot']];
        $visit(self::X, ['aff_campaign_id' => $i['campA'], 'landing_page_id' => $i['lp3'], 'rule_id' => 501] + $lead);
        $visit(self::X, ['aff_campaign_id' => $i['campB'], 'landing_page_id' => $i['lp2'], 'rule_id' => 502,
            'click_time' => self::$now + 600] + $lead);
        $route(self::X, $i['ruleB'], 502);
        LatestVisit::refresh(new Connection(self::$db), self::X);
        $visit(self::Y, ['aff_campaign_id' => $i['campA'], 'landing_page_id' => $i['lp1'],
            'click_time' => self::$now + 60, 'rotator_id' => $i['rot'], 'rule_id' => 501]);
        $route(self::Y, $i['ruleA'], 501);
        foreach ([self::X, self::Y] as $id) {
            self::row('202_clicks_advance', [
                'click_id' => $id, 'keyword_id' => $i['kw'], 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
                'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0,
            ]);
            self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => 0, 'click_out' => 1]);
        }
    }

    /** @return array{clicks: int, leads: int, income: float, cost: float} */
    private static function figures(int $clicks, int $leads, float $income, float $cost): array
    {
        return ['clicks' => $clicks, 'leads' => $leads, 'income' => $income, 'cost' => $cost];
    }

    public function testTheRotatorBreakdownCountsTheClickOnceUnderTheRouteItHolds(): void
    {
        $rotators = (new RotatorBreakdown(self::$db))->rotators(self::USER, self::$now - 60, self::$now + 3000, 'all');
        self::assertCount(1, $rotators);
        $rules = [];
        foreach ($rotators[0]['rules'] as $rule) {
            $rules[(string) $rule['name']] = $rule['figures'];
        }

        self::assertSame(self::figures(1, 0, 0.0, 0.25), $rules['reclick A'], 'Y');
        self::assertSame(self::figures(1, 1, 10.0, 0.25), $rules['reclick B'], 'X, once');
        self::assertSame(self::figures(2, 1, 10.0, 0.5), $rotators[0]['totals']);
    }

    public function testTheRotatorBreakdownCountsNothingInAWindowOfOnlyTheEarlierVisit(): void
    {
        $rotators = (new RotatorBreakdown(self::$db))->rotators(self::USER, self::$now - 60, self::$now + 30, 'all');

        self::assertSame(self::figures(0, 0, 0.0, 0.0), $rotators[0]['totals'], 'X is its latest visit\'s');
    }

    public function testTheLegacyReportsCountTheClickOnce(): void
    {
        $keywords = $this->legacy('keywords', 'keyword_id', 'keyword');
        self::assertSame([['reclick once kw', 2, 1]], array_map(
            static fn (array $r): array => [$r['keyword'], (int) $r['clicks'], (int) $r['leads']],
            $keywords['keywords']
        ), 'X and Y, each once');

        $pages = $this->legacy('landing_pages', 'landing_page_id', 'landing_page');
        $byPage = [];
        foreach ($pages['landing_pages'] as $r) {
            $byPage[$r['landing_page']] = [(int) $r['clicks'], (int) $r['leads']];
        }
        ksort($byPage);
        self::assertSame(
            ['reclick page 1' => [1, 0], 'reclick page 2' => [1, 1]],
            $byPage,
            'Y on page 1, X on page 2 only, page 3 not at all'
        );
        self::assertSame(2, (int) $pages['totals']['clicks']);
    }

    /** @return array<string, mixed> the report, from a process of its own that inherits P202_TEST_DB_* */
    private function legacy(string $type, string $id, string $name): array
    {
        $args = [$type, $id, $name, (string) self::USER, (string) (self::$now - 60), (string) (self::$now + 3000)];
        $out = (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr '
            . escapeshellarg(__DIR__ . '/fixtures/legacy-report-runner.php') . ' '
            . implode(' ', array_map('escapeshellarg', $args)) . ' 2>&1');
        $report = json_decode($out, true);
        self::assertIsArray($report, $out);
        self::assertArrayNotHasKey('error', $report, $out);

        return $report;
    }
}
