<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\DataEngine\ClickRollupSql;

/**
 * A re-clicked click has one report row (202_dataengine), at its latest
 * visit, whichever path rolls it up: by its id (the click path's slim
 * engine, the full engine, the conversion repository's rollUpClick()), an
 * hour rebuild (DataEngine::getSummary()) or a dirty hour
 * (DataEngine::processDirtyHours()).
 *
 * A redirector re-click gives the click another 202_clicks row, at its own
 * visit's time, and the rollup wrote a report row per visit (the key is the
 * click and the visit's time), so every report counted the click once per
 * visit: its clicks, its cost, and after a conversion its leads and income,
 * since the ledger writes click_lead and click_payout on every visit's row.
 *
 * The visits are written as MysqlClickRepository writes them: a 202_clicks
 * and 202_clicks_spy row per visit, and the click's own rows once, a
 * re-click rewriting them. The engines run in a process of their own
 * (fixtures/rollup-runner.php): both declare DataEngine.
 *
 * @group integration
 */
final class ReClickRollupIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990851;
    private const OTHER = 990852;

    private const CLICK = 99085101;
    private const SECOND_CLICK = 99085102;
    private const OTHERS_CLICK = 99085103;
    private const CLICKS = [self::CLICK, self::SECOND_CLICK, self::OTHERS_CLICK];

    /** USER's campaigns: the first visit's, the latest's, and a third. */
    private const FIRST_CAMPAIGN = 990861;
    private const LATEST_CAMPAIGN = 990862;
    private const THIRD_CAMPAIGN = 990863;
    private const OTHERS_CAMPAIGN = 990864;

    /** The first visit's traffic source account, and the redirector link's own. */
    private const FIRST_ACCOUNT = 990871;
    private const LINK_ACCOUNT = 990872;

    private const ROTATOR = 77;

    /** The first visit's hour; the latest visit is three hours later. */
    private static int $hour = 0;

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
        }
        self::$hour = (intdiv(time(), 3600) - 96) * 3600;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        $t = self::$hour;
        $campaigns = [
            [self::FIRST_CAMPAIGN, self::USER], [self::LATEST_CAMPAIGN, self::USER],
            [self::THIRD_CAMPAIGN, self::USER], [self::OTHERS_CAMPAIGN, self::OTHER],
        ];
        foreach ($campaigns as [$id, $user]) {
            self::row('202_aff_campaigns', [
                'aff_campaign_id' => $id, 'user_id' => $user, 'aff_network_id' => 0, 'aff_campaign_name' => "Offer $id",
                'aff_campaign_url' => 'https://offer.example/' . $id, 'aff_campaign_payout' => 1,
                'aff_campaign_foreign_payout' => 0, 'aff_campaign_time' => $t,
            ]);
        }
    }

    private static function cleanUp(): void
    {
        $clicks = implode(', ', self::CLICKS);
        $tables = [
            '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_clicks_tracking',
            '202_clicks_site', '202_clicks_rotator', '202_dataengine',
        ];
        foreach ($tables as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        foreach ([self::USER, self::OTHER] as $user) {
            self::q("DELETE FROM 202_aff_campaigns WHERE user_id = $user");
            self::q("DELETE FROM 202_dirty_hours WHERE user_id = $user");
        }
    }

    private static function firstVisitTime(): int
    {
        return self::$hour + 600;
    }

    private static function latestVisitTime(): int
    {
        return self::$hour + 3 * 3600 + 600;
    }

    /**
     * One visit: its 202_clicks and 202_clicks_spy rows, and the click's own
     * rows, written with its first visit and rewritten by a re-click, which
     * also gives it the route it is on.
     */
    private static function visit(
        int $click,
        int $time,
        int $campaign,
        int $account,
        string $cpc,
        string $payout,
        int $user = self::USER,
        bool $reClick = false,
        int $filtered = 0,
        int $bot = 0
    ): void {
        $row = [
            'click_id' => $click, 'user_id' => $user, 'aff_campaign_id' => $campaign, 'landing_page_id' => 0,
            'ppc_account_id' => $account, 'click_cpc' => $cpc, 'click_payout' => $payout, 'click_time' => $time,
            'click_filtered' => $filtered, 'click_bot' => $bot,
        ];
        self::row('202_clicks', $row + ['rotator_id' => $reClick ? self::ROTATOR : 0, 'rule_id' => $reClick ? 12 : 0]);
        self::row('202_clicks_spy', $row);
        self::q("INSERT INTO 202_clicks_advance SET click_id = $click, text_ad_id = 0, keyword_id = 0, ip_id = 0,"
            . ' country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0'
            . ' ON DUPLICATE KEY UPDATE ip_id = VALUES(ip_id)');
        self::q("INSERT INTO 202_clicks_tracking SET click_id = $click, c1_id = 0, c2_id = 0, c3_id = 0, c4_id = 0"
            . ' ON DUPLICATE KEY UPDATE c1_id = c1_id');
        self::q("INSERT INTO 202_clicks_record SET click_id = $click, click_id_public = $click"
            . ' ON DUPLICATE KEY UPDATE click_in = VALUES(click_in)');
        self::q("INSERT INTO 202_clicks_site SET click_id = $click"
            . ' ON DUPLICATE KEY UPDATE click_referer_site_url_id = VALUES(click_referer_site_url_id)');
        if ($reClick) {
            self::q('INSERT INTO 202_clicks_rotator SET click_id = ' . $click . ', rotator_id = ' . self::ROTATOR
                . ', rule_id = 3, rule_redirect_id = 12'
                . ' ON DUPLICATE KEY UPDATE rule_redirect_id = VALUES(rule_redirect_id)');
        }
    }

    /** A click visited at its first visit's time, then re-clicked onto another campaign at a higher payout. */
    private static function reClicked(
        int $click,
        int $user = self::USER,
        int $firstCampaign = self::FIRST_CAMPAIGN
    ): void {
        self::visit($click, self::firstVisitTime(), $firstCampaign, self::FIRST_ACCOUNT, '0.75', '1.00', $user);
        $latest = $user === self::USER ? self::LATEST_CAMPAIGN : self::OTHERS_CAMPAIGN;
        self::visit($click, self::latestVisitTime(), $latest, self::LINK_ACCOUNT, '0.10', '2.50', $user, true);
    }

    /**
     * The report rows a rollup made before 1.9.78 left for a re-clicked
     * click: one per visit, each with that visit's values (of two in one
     * second, the first read). Written here rather than by ClickRollupSql,
     * so they do not depend on the code under test.
     */
    private static function rolledUpPerVisit(int $click): void
    {
        self::q('INSERT IGNORE INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
            . ' landing_page_id, click_lead, clicks, leads, payout, income, cost)'
            . ' SELECT c.user_id, c.click_id, c.click_time, c.ppc_account_id, c.aff_campaign_id, c.landing_page_id,'
            . ' c.click_lead, 1, c.click_lead, c.click_payout, IF(c.click_lead > 0, c.click_payout, 0), c.click_cpc'
            . " FROM 202_clicks AS c WHERE c.click_id = $click");
    }

    /** @return list<array<string, string|null>> the click's report rows, oldest first */
    private static function reportRows(int $click): array
    {
        $result = self::$db->query('SELECT click_time, aff_campaign_id, ppc_account_id, clicks, leads, payout,'
            . " income, cost FROM 202_dataengine WHERE click_id = $click ORDER BY click_time");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return list<string> each report row's click_filtered and click_bot, oldest first */
    private static function flags(int $click): array
    {
        $result = self::$db->query("SELECT CONCAT(click_filtered, '|', click_bot) FROM 202_dataengine"
            . " WHERE click_id = $click ORDER BY click_time");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(), 0);
    }

    /** @return array<string, string|null> the row the click's latest visit rolls up to */
    private static function latestRow(string $leads = '0', string $payout = '2.50', string $income = '0.00000'): array
    {
        return [
            'click_time' => (string) self::latestVisitTime(), 'aff_campaign_id' => (string) self::LATEST_CAMPAIGN,
            'ppc_account_id' => (string) self::LINK_ACCOUNT, 'clicks' => '1', 'leads' => $leads,
            'payout' => $payout, 'income' => $income, 'cost' => '0.10000',
        ];
    }

    /** @return array<string, string|null> the row of the click's first visit, rolled up before the re-click */
    private static function firstRow(): array
    {
        return [
            'click_time' => (string) self::firstVisitTime(), 'aff_campaign_id' => (string) self::FIRST_CAMPAIGN,
            'ppc_account_id' => (string) self::FIRST_ACCOUNT, 'clicks' => '1', 'leads' => '0',
            'payout' => '1.00', 'income' => '0.00000', 'cost' => '0.75000',
        ];
    }

    /** @return iterable<string, array{string}> */
    public static function clickRollups(): iterable
    {
        yield 'the conversion repository\'s rollUpClick()' => ['connection'];
        yield 'the click path\'s slim engine' => ['slim'];
        yield 'the full engine' => ['full'];
    }

    private static function rollUp(string $path, int $click): void
    {
        if ($path === 'connection') {
            ClickRollupSql::rollUpClick(new Connection(self::requireScratch()), $click);

            return;
        }
        [$out, $err] = self::runEngine([$path, 'click', (string) $click]);
        self::assertSame('RETURNED true', $out, "the $path engine rolled the click up: $err");
    }

    /**
     * @param list<string> $args
     * @return array{string, string} what the runner printed, and what the engine logged
     */
    private static function runEngine(array $args, ?int $lockWait = null): array
    {
        $env = getenv();
        if ($lockWait !== null) {
            $env['P202_TEST_LOCK_WAIT'] = (string) $lockWait;
        }
        $proc = proc_open(
            [
                PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=',
                __DIR__ . '/fixtures/rollup-runner.php', ...$args,
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }

    /**
     * Rolled up by its id, at its first visit and again after the re-click:
     * one row, under the latest visit's time, campaign and payout, with the
     * cost the latest visit's row carries.
     *
     * @dataProvider clickRollups
     */
    public function testARollupByIdKeepsOneRowAtTheClicksLatestVisit(string $path): void
    {
        self::visit(self::CLICK, self::firstVisitTime(), self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '1.00');
        self::rollUp($path, self::CLICK);
        self::assertSame([self::firstRow()], self::reportRows(self::CLICK), 'the first visit\'s row');

        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::LATEST_CAMPAIGN, self::LINK_ACCOUNT, '0.10', '2.50', self::USER, true);
        self::rollUp($path, self::CLICK);

        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the click moved to its latest visit');
    }

    /**
     * The rollup statement alone, as a caller that runs no removal after it
     * gets it: an earlier visit writes no row.
     */
    public function testTheRollupStatementWritesNoRowForAnEarlierVisit(): void
    {
        self::reClicked(self::CLICK);

        self::q(ClickRollupSql::insertSelect('202_dataengine', '2c.click_id = ' . self::CLICK));

        self::assertSame([self::latestRow()], self::reportRows(self::CLICK));
    }

    /**
     * The ledger writes click_lead and click_payout on every visit's row
     * (MysqlConversionLedger), and the conversion re-rolls the click: one
     * click, one lead, the payout once.
     */
    public function testAConvertedReClickedClickCountsOnce(): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        self::q('UPDATE 202_clicks SET click_lead = 1, click_payout = 4 WHERE click_id = ' . self::CLICK);
        ClickRollupSql::rollUpClick(new Connection(self::requireScratch()), self::CLICK);

        self::assertSame([self::latestRow('1', '4.00', '4.00000')], self::reportRows(self::CLICK));
    }

    /** A click rolled up by id leaves no row at an earlier visit that a rollup before 1.9.78 wrote. @dataProvider clickRollups */
    public function testRollingTheClickRemovesTheRowOfItsEarlierVisit(string $path): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK), 'the rows from before');

        self::rollUp($path, self::CLICK);

        self::assertSame([self::latestRow()], self::reportRows(self::CLICK));
    }

    /**
     * The first visit's hour rebuilt (dej.php, the cron's rebuild windows):
     * its row is removed and not written again, and the latest visit's row,
     * in an hour not rebuilt, stays. Rebuilding the latest visit's hour
     * keeps the one row.
     */
    public function testAnHourRebuildRemovesTheEarlierVisitsRowAndDoesNotWriteItAgain(): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        $first = (string) self::$hour;
        $firstEnd = (string) (self::$hour + 3599);

        foreach (['the first rebuild', 'a second rebuild'] as $run) {
            [$out, $err] = self::runEngine(['full', 'window', $first, $firstEnd]);
            self::assertSame('RETURNED true', $out, $err);
            self::assertSame([self::latestRow()], self::reportRows(self::CLICK), $run);
        }

        $latest = (string) (self::$hour + 3 * 3600);
        [$out, $err] = self::runEngine(['full', 'window', $latest, (string) ((int) $latest + 3599)]);
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the latest visit\'s hour');
    }

    /**
     * A dirty hour names an account, and a campaign or a traffic source
     * account (processDirtyHours()): the removal is bounded by the same
     * conditions as the rollup, so the account's other campaign keeps its
     * rows until the account's hour is rolled up, and another account keeps
     * its rows then.
     */
    public function testADirtyHourRemovesOnlyTheRowsItsConditionsName(): void
    {
        self::reClicked(self::CLICK);
        self::reClicked(self::SECOND_CLICK, self::USER, self::THIRD_CAMPAIGN);
        self::reClicked(self::OTHERS_CLICK, self::OTHER, self::OTHERS_CAMPAIGN);
        foreach (self::CLICKS as $click) {
            self::rolledUpPerVisit($click);
        }

        self::dirtyHour(self::FIRST_CAMPAIGN);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the dirty hour\'s campaign');
        self::assertCount(2, self::reportRows(self::SECOND_CLICK), 'another campaign of the account');
        self::assertCount(2, self::reportRows(self::OTHERS_CLICK), 'another account');

        self::dirtyHour(0);
        self::assertCount(1, self::reportRows(self::SECOND_CLICK), 'the account\'s hour');
        self::assertCount(2, self::reportRows(self::OTHERS_CLICK), 'another account, in the account\'s hour');
    }

    /** USER's first visits' hour queued, for $campaign or all of USER's, and the cron's pass over the queue. */
    private static function dirtyHour(int $campaign): void
    {
        self::row('202_dirty_hours', [
            'user_id' => self::USER, 'ppc_account_id' => 0, 'aff_campaign_id' => $campaign,
            'click_time_from' => self::$hour, 'click_time_to' => self::$hour + 3599, 'landing_page_id' => 0,
        ]);
        [$out, $err] = self::runEngine(['full', 'dirty-hours']);
        self::assertSame('RETURNED true', $out, $err);
    }

    /**
     * A re-clicked click is filtered, or a bot, as its first visit was:
     * the redirector's duplicate filter flagged the re-click's own row
     * before 1.9.78. A click with one visit keeps its own flags, on the
     * redirector or off it. By id and by the hour's rebuild alike.
     */
    public function testAReClickedClicksFlagsAreItsFirstVisits(): void
    {
        $first = self::firstVisitTime();
        $latest = self::latestVisitTime();
        [$campaign, $account] = [self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT];
        [$reCampaign, $link] = [self::LATEST_CAMPAIGN, self::LINK_ACCOUNT];
        self::visit(self::CLICK, $first, $campaign, $account, '0.75', '1.00');
        self::visit(self::CLICK, $latest, $reCampaign, $link, '0.10', '2.50', self::USER, true, 1);
        self::visit(self::SECOND_CLICK, $first, $campaign, $account, '0.75', '1.00', self::USER, false, 1, 1);
        self::visit(self::SECOND_CLICK, $latest, $reCampaign, $link, '0.10', '2.50', self::USER, true);
        self::visit(self::OTHERS_CLICK, $latest, self::OTHERS_CAMPAIGN, $link, '0.10', '2.50', self::OTHER, true, 1);
        $conn = new Connection(self::requireScratch());
        foreach (self::CLICKS as $click) {
            ClickRollupSql::rollUpClick($conn, $click);
        }
        $expected = [self::CLICK => ['0|0'], self::SECOND_CLICK => ['1|1'], self::OTHERS_CLICK => ['1|0']];
        foreach ($expected as $click => $flags) {
            self::assertSame($flags, self::flags($click), "click $click by id");
        }

        self::q('DELETE FROM 202_dataengine WHERE click_id IN (' . implode(', ', self::CLICKS) . ')');
        $hour = self::$hour + 3 * 3600;
        [$out, $err] = self::runEngine(['full', 'window', (string) $hour, (string) ($hour + 3599)]);
        self::assertSame('RETURNED true', $out, $err);
        foreach ($expected as $click => $flags) {
            self::assertSame($flags, self::flags($click), "click $click by the hour");
        }

        self::q('UPDATE 202_clicks SET click_filtered = 1, rotator_id = 0 WHERE click_id = ' . self::CLICK
            . ' AND click_time = ' . $first);
        foreach (['202_clicks', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id = " . self::CLICK . ' AND click_time = ' . $latest);
        }
        ClickRollupSql::rollUpClick($conn, self::CLICK);
        self::assertSame(['1|0'], self::flags(self::CLICK), 'one visit off the redirector, filtered');
    }

    /**
     * The hour's rebuild reads its window's ends as bounds
     * (ClickRollupSql's window): a later visit one second after the start,
     * a first visit at the end, and a rollup row at either end are each
     * still read.
     */
    public function testAnHourRebuildReadsTheVisitsAtItsEdges(): void
    {
        $start = self::$hour;
        $end = self::$hour + 3599;
        [$campaign, $account] = [self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT];
        [$reCampaign, $link] = [self::LATEST_CAMPAIGN, self::LINK_ACCOUNT];
        self::visit(self::CLICK, $start, $campaign, $account, '0.75', '1.00');
        self::visit(self::CLICK, $start + 1, $reCampaign, $link, '0.10', '2.50', self::USER, true);
        self::visit(self::SECOND_CLICK, $end, $campaign, $account, '0.75', '1.00');
        self::visit(self::SECOND_CLICK, $end + 1, $reCampaign, $link, '0.10', '2.50', self::USER, true);
        self::visit(self::OTHERS_CLICK, $end, self::OTHERS_CAMPAIGN, $link, '0.10', '2.50', self::OTHER, true, 1);
        foreach (self::CLICKS as $click) {
            self::rolledUpPerVisit($click);
        }

        [$out, $err] = self::runEngine(['full', 'window', (string) $start, (string) $end]);
        self::assertSame('RETURNED true', $out, $err);

        $times = static fn (int $click): array => array_column(self::reportRows($click), 'click_time');
        self::assertSame([(string) ($start + 1)], $times(self::CLICK), 'a later visit a second after the start');
        self::assertSame([(string) ($end + 1)], $times(self::SECOND_CLICK), 'an earlier visit\'s row at the end');
        self::assertSame(['1|0'], self::flags(self::OTHERS_CLICK), 'a first visit at the end');
    }

    /**
     * Two visits in one second are both latest (LatestVisit::isLatest()) and
     * fold into one row by the key, which holds one of them whole.
     */
    public function testTwoVisitsInOneSecondFoldIntoOneRowOfOneVisit(): void
    {
        self::reClicked(self::CLICK);
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::THIRD_CAMPAIGN, self::FIRST_ACCOUNT, '0.40', '3.00', self::USER, true);
        self::rolledUpPerVisit(self::CLICK);
        ClickRollupSql::rollUpClick(new Connection(self::requireScratch()), self::CLICK);

        $rows = self::reportRows(self::CLICK);
        self::assertCount(1, $rows);
        $third = [
            'click_time' => (string) self::latestVisitTime(), 'aff_campaign_id' => (string) self::THIRD_CAMPAIGN,
            'ppc_account_id' => (string) self::FIRST_ACCOUNT, 'clicks' => '1', 'leads' => '0',
            'payout' => '3.00', 'income' => '0.00000', 'cost' => '0.40000',
        ];
        self::assertContains($rows[0], [self::latestRow(), $third], 'one visit\'s values, every column');
    }

    /** @return iterable<string, array{string, string}> */
    public static function failedSteps(): iterable
    {
        yield 'the rollup' => ['latest', 'rollup'];
        yield 'the removal' => ['first', 'removal of the earlier visits\' rows'];
    }

    /**
     * On the click path the visitor goes on whatever the rollup does: a
     * step that cannot have its row (held by another transaction past the
     * lock wait, tried twice) is logged by name and answered false, and a
     * failed rollup removes nothing. The full engine throws, naming the step.
     *
     * @dataProvider failedSteps
     */
    public function testAStepThatFailsIsNamedAndRemovesNothingItDidNotReplace(string $held, string $step): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        $time = $held === 'latest' ? self::latestVisitTime() : self::firstVisitTime();
        $lock = self::lockReportRow(self::CLICK, $time);
        try {
            [$out, $err] = self::runEngine(['slim', 'click', (string) self::CLICK], 1);
            self::assertSame('RETURNED false', $out, $err);
            self::assertStringContainsString("DataEngine $step of click " . self::CLICK . ' failed: ', $err);
            self::assertStringContainsString('[errno 1205]', $err);

            [$out, $err] = self::runEngine(['full', 'click', (string) self::CLICK], 1);
            self::assertSame(
                'THREW ' . QueryException::class . ": DataEngine $step of click " . self::CLICK
                    . ' failed [errno 1205]',
                $out,
                $err
            );
        } finally {
            $lock->rollback();
            $lock->close();
        }
        self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK), 'both rows stay');

        [$out, $err] = self::runEngine(['slim', 'click', (string) self::CLICK]);
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the next rollup completes it');
    }

    /** rollUpClick() says which step failed, for the conversion repository's log. */
    public function testRollUpClickNamesTheStepThatFailed(): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        $db = self::requireScratch();
        $lock = self::lockReportRow(self::CLICK, self::firstVisitTime());
        self::q('SET SESSION innodb_lock_wait_timeout = 1');
        try {
            ClickRollupSql::rollUpClick(new Connection($db), self::CLICK);
            self::fail('the removal waited past the lock wait and did not throw');
        } catch (QueryException $e) {
            self::assertStringStartsWith(
                'the removal of the earlier visits\' rows of click ' . self::CLICK . ' in 202_dataengine failed: ',
                $e->getMessage()
            );
            self::assertTrue(Connection::isRetryableLockError($e), $e->getMessage());
        } finally {
            $lock->rollback();
            $lock->close();
            self::q('SET SESSION innodb_lock_wait_timeout = DEFAULT');
        }
        self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK));
    }

    /** A second connection holding the click's report row at $time, until rolled back. */
    private static function lockReportRow(int $click, int $time): \mysqli
    {
        $lock = mysqli_connect(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertInstanceOf(\mysqli::class, $lock);
        self::assertTrue($lock->begin_transaction());
        $held = $lock->query(
            "SELECT click_id FROM 202_dataengine WHERE click_id = $click AND click_time = $time FOR UPDATE"
        );
        self::assertInstanceOf(\mysqli_result::class, $held, $lock->error);
        self::assertSame(1, $held->num_rows, 'the row is there to hold');

        return $lock;
    }
}
