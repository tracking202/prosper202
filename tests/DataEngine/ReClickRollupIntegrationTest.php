<?php

declare(strict_types=1);

namespace Tests\DataEngine;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\DataEngine\ClickRollupSql;
use Prosper202\DataEngine\RollupRefresh;

/**
 * A re-clicked click has one report row (202_dataengine), at its latest
 * visit, whichever path rolls it up: by its id (the click path's slim
 * engine, the full engine, the conversion repository's rollUpClick()), an
 * hour rebuild (DataEngine::getSummary()) or a dirty hour
 * (DataEngine::processDirtyHours()). Its rows change only where its latest
 * visit is rolled up: a rebuild that holds an earlier visit and not the
 * latest writes nothing of the click and removes nothing of it.
 *
 * A redirector re-click gives the click another 202_clicks row, at its own
 * visit's time, and the rollup wrote a report row per visit (the key is the
 * click and the visit's time), so every report counted the click once per
 * visit: its clicks, its cost, and after a conversion its leads and income,
 * since the ledger writes click_lead and click_payout on every visit's row.
 *
 * The visits are written as MysqlClickRepository writes them: a 202_clicks
 * and 202_clicks_spy row per visit, the click's own rows once, a re-click
 * rewriting them, and the click's latest visit recorded (LatestVisit::refresh()).
 * A re-click carries its first visit's traffic source, cost and flags. A
 * re-click written before 1.9.79 that the backfill has not reached has
 * none of that: its own row's values and no record. The engines run in a
 * process of their own (fixtures/rollup-runner.php): both declare DataEngine.
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
    private const FOURTH_CLICK = 99085104;
    private const CLICKS = [self::CLICK, self::SECOND_CLICK, self::OTHERS_CLICK, self::FOURTH_CLICK];

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
            '202_clicks_site', '202_clicks_rotator', '202_click_latest_visits', '202_dataengine',
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
     * also gives it the route it is on and records its latest visit, unless
     * it is one written before 1.9.79 that the backfill has not reached.
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
        int $bot = 0,
        bool $recorded = true
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
            if ($recorded) {
                LatestVisit::refresh(new Connection(self::requireScratch()), $click);
            }
        }
    }

    /**
     * A click visited at its first visit's time, then re-clicked onto another
     * campaign at a higher payout, keeping its first visit's traffic source
     * and cost, as the re-click writer records it.
     */
    private static function reClicked(
        int $click,
        int $user = self::USER,
        int $firstCampaign = self::FIRST_CAMPAIGN,
        ?int $latestCampaign = null
    ): void {
        self::visit($click, self::firstVisitTime(), $firstCampaign, self::FIRST_ACCOUNT, '0.75', '1.00', $user);
        $latest = $latestCampaign ?? ($user === self::USER ? self::LATEST_CAMPAIGN : self::OTHERS_CAMPAIGN);
        self::visit($click, self::latestVisitTime(), $latest, self::FIRST_ACCOUNT, '0.75', '2.50', $user, true);
    }

    /**
     * The report rows a rollup made before 1.9.79 left for a re-clicked
     * click: one per visit, each with that visit's values (of two in one
     * second, the first read). Written here rather than by ClickRollupSql,
     * so they do not depend on the code under test. The same rows are left
     * by a rollup whose removal step failed after its latest row was written.
     */
    private static function rolledUpPerVisit(int $click): void
    {
        self::q('INSERT IGNORE INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
            . ' landing_page_id, click_lead, click_filtered, click_bot, clicks, leads, payout, income, cost)'
            . ' SELECT c.user_id, c.click_id, c.click_time, c.ppc_account_id, c.aff_campaign_id, c.landing_page_id,'
            . ' c.click_lead, c.click_filtered, c.click_bot, 1, c.click_lead, c.click_payout,'
            . ' IF(c.click_lead > 0, c.click_payout, 0), c.click_cpc'
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

    /** @return list<string> the click's report rows' times, oldest first */
    private static function rowTimes(int $click): array
    {
        return array_column(self::reportRows($click), 'click_time');
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
            'ppc_account_id' => (string) self::FIRST_ACCOUNT, 'clicks' => '1', 'leads' => $leads,
            'payout' => $payout, 'income' => $income, 'cost' => '0.75000',
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

    /** The full engine's rebuild of [$from, $to] (dej.php, the cron's rebuild windows). */
    private static function rebuild(int $from, int $to): void
    {
        [$out, $err] = self::runEngine(['full', 'window', (string) $from, (string) $to]);
        self::assertSame('RETURNED true', $out, $err);
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
     * one row, under the latest visit's time, campaign and payout.
     *
     * @dataProvider clickRollups
     */
    public function testARollupByIdKeepsOneRowAtTheClicksLatestVisit(string $path): void
    {
        self::visit(self::CLICK, self::firstVisitTime(), self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '1.00');
        self::rollUp($path, self::CLICK);
        self::assertSame([self::firstRow()], self::reportRows(self::CLICK), 'the first visit\'s row');

        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50', self::USER, true);
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

    /** A click rolled up by id leaves no row at an earlier visit that a rollup before 1.9.79 wrote. @dataProvider clickRollups */
    public function testRollingTheClickRemovesTheRowOfItsEarlierVisit(string $path): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK), 'the rows from before');

        self::rollUp($path, self::CLICK);

        self::assertSame([self::latestRow()], self::reportRows(self::CLICK));
    }

    /**
     * The first visit's hour rebuilt (dej.php, the cron's rebuild windows)
     * holds the click's earlier visit and not its latest: it writes nothing
     * of the click and removes nothing of it, however often it runs. The
     * latest visit's hour removes the row at the first visit, three hours
     * before it, and keeps the one row; the first visit's hour, rebuilt
     * after it, writes nothing back.
     */
    public function testAnHourRebuildChangesAClicksRowsOnlyInItsLatestVisitsHour(): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        $first = self::$hour;
        $latest = self::$hour + 3 * 3600;

        foreach (['the first rebuild', 'a second rebuild'] as $run) {
            self::rebuild($first, $first + 3599);
            self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK), $run);
        }

        self::rebuild($latest, $latest + 3599);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the latest visit\'s hour');

        self::rebuild($first, $first + 3599);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the first visit\'s hour again');
    }

    /**
     * The latest visit's hour queued (a conversion's day, an Update CPC of
     * that day) removes the click's row at its first visit, in another hour:
     * a row a rollup before 1.9.79 wrote, or one a rollup's removal failed to
     * take. The removal read the queued hour's rows alone, and left the
     * click counted twice until the first visit's hour was queued as well.
     */
    public function testALatestVisitsDirtyHourRemovesTheClicksRowInAnotherHour(): void
    {
        self::reClicked(self::CLICK);
        self::rolledUpPerVisit(self::CLICK);
        self::assertSame([self::firstRow(), self::latestRow()], self::reportRows(self::CLICK), 'the rows from before');

        self::dirtyHour(self::$hour + 3 * 3600, 0);

        self::assertSame([self::latestRow()], self::reportRows(self::CLICK));
    }

    /**
     * A dirty hour names an account, and a campaign or a traffic source
     * account (processDirtyHours()), each the latest visit's: the removal is
     * bounded by the same conditions as the rollup. The first visits' hour,
     * queued for their campaign, holds no click's latest visit and changes
     * nothing; the latest visits' hour, for one campaign, moves that
     * campaign's click and leaves the account's other campaign's; for the
     * whole account, it moves the account's clicks and leaves another
     * account's.
     */
    public function testADirtyHourRemovesOnlyTheRowsItsConditionsName(): void
    {
        self::reClicked(self::CLICK);
        self::reClicked(self::SECOND_CLICK, self::USER, self::FIRST_CAMPAIGN, self::THIRD_CAMPAIGN);
        self::reClicked(self::OTHERS_CLICK, self::OTHER, self::OTHERS_CAMPAIGN);
        foreach ([self::CLICK, self::SECOND_CLICK, self::OTHERS_CLICK] as $click) {
            self::rolledUpPerVisit($click);
        }
        $latestHour = self::$hour + 3 * 3600;

        self::dirtyHour(self::$hour, self::FIRST_CAMPAIGN);
        foreach ([self::CLICK, self::SECOND_CLICK, self::OTHERS_CLICK] as $click) {
            self::assertCount(2, self::reportRows($click), "click $click: the first visits' hour and campaign");
        }

        self::dirtyHour($latestHour, self::LATEST_CAMPAIGN);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the dirty hour\'s campaign');
        self::assertCount(2, self::reportRows(self::SECOND_CLICK), 'another campaign of the account');
        self::assertCount(2, self::reportRows(self::OTHERS_CLICK), 'another account');

        self::dirtyHour($latestHour, 0);
        self::assertSame([(string) self::latestVisitTime()], self::rowTimes(self::SECOND_CLICK), 'the account\'s hour');
        self::assertCount(2, self::reportRows(self::OTHERS_CLICK), 'another account, in the account\'s hour');
    }

    /** USER's hour from $from queued, for $campaign or all of USER's, and the cron's pass over the queue. */
    private static function dirtyHour(int $from, int $campaign): void
    {
        self::row('202_dirty_hours', [
            'user_id' => self::USER, 'ppc_account_id' => 0, 'aff_campaign_id' => $campaign,
            'click_time_from' => $from, 'click_time_to' => $from + 3599, 'landing_page_id' => 0,
        ]);
        [$out, $err] = self::runEngine(['full', 'dirty-hours']);
        self::assertSame('RETURNED true', $out, $err);
    }

    /** @return iterable<string, array{string}> */
    public static function rebuildsOfTheFirstVisit(): iterable
    {
        yield 'a Setup change to the first visit\'s campaign (RollupRefresh)' => ['campaign'];
        yield 'the first visit\'s hour queued for the account (an Update CPC of its day)' => ['hour'];
        yield 'the first visit\'s hour rebuilt' => ['rebuild'];
    }

    /**
     * The click path's rollup of a re-click failed (the place of its latest
     * visit's row held past the lock wait, tried twice and logged), so the
     * click's one row is its first visit's. A rebuild that holds the first
     * visit and not the latest writes nothing of the click and removes
     * nothing of it: the click still counts, once, until it or its latest
     * visit's hour is rolled up. The removal was keyed on the earlier visit,
     * and took the row without writing one, so the click was gone from every
     * report (measured).
     *
     * @dataProvider rebuildsOfTheFirstVisit
     */
    public function testARebuildOfTheFirstVisitKeepsTheRowOfAClickWhoseLatestWasNotRolledUp(string $rebuild): void
    {
        self::visit(self::CLICK, self::firstVisitTime(), self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '1.00');
        self::rollUp('connection', self::CLICK);
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50', self::USER, true);
        $lock = self::lockReportRow(self::CLICK, $latest, false);
        try {
            [$out, $err] = self::runEngine(['slim', 'click', (string) self::CLICK], 1);
            self::assertSame('RETURNED false', $out, $err);
            self::assertStringContainsString('DataEngine rollup of click ' . self::CLICK . ' failed: ', $err);
        } finally {
            $lock->rollback();
            $lock->close();
        }
        self::assertSame([self::firstRow()], self::reportRows(self::CLICK), 'the latest visit was not written');
        // The failure queued the latest visit's hour, which would move the
        // click at the next pass; taken out, so the rebuild runs alone.
        $hour = intdiv($latest, 3600) * 3600;
        self::assertSame(
            [[(string) self::USER, '0', '0', (string) $hour, (string) ($hour + 3599), '0']],
            self::dirtyHours()
        );
        self::q('DELETE FROM 202_dirty_hours WHERE user_id = ' . self::USER);

        if ($rebuild === 'campaign') {
            RollupRefresh::campaign(new Connection(self::requireScratch()), self::USER, self::FIRST_CAMPAIGN, time());
            [$out, $err] = self::runEngine(['full', 'dirty-hours']);
            self::assertSame('RETURNED true', $out, $err);
        } elseif ($rebuild === 'hour') {
            self::dirtyHour(self::$hour, 0);
        } else {
            self::rebuild(self::$hour, self::$hour + 3599);
        }
        self::assertSame([self::firstRow()], self::reportRows(self::CLICK), 'the click still counts');

        self::rollUp('slim', self::CLICK);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the next rollup moves it');
    }

    /**
     * A re-click written before 1.9.79 that the backfill has not reached
     * has no record (LatestVisit): it counts at each visit, each row with
     * its own values (the re-click's carries the redirector link's traffic
     * source and cost, and the duplicate filter's flag), as 1.9.78 counted
     * it, whichever path rolls it up and in whichever order its hours are
     * rebuilt. Once the backfill gives the re-click's row its first visit's
     * values and records it, a rollup of the click leaves the one row.
     */
    public function testAReClickTheBackfillHasNotReachedCountsAtEachVisitUntilRecorded(): void
    {
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, self::firstVisitTime(), self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '1.00');
        self::visit(
            self::CLICK,
            $latest,
            self::LATEST_CAMPAIGN,
            self::LINK_ACCOUNT,
            '0.10',
            '2.50',
            self::USER,
            true,
            1,
            0,
            false
        );
        $linkRow = array_replace(
            self::latestRow(),
            ['ppc_account_id' => (string) self::LINK_ACCOUNT, 'cost' => '0.10000']
        );
        $eachVisit = [self::firstRow(), $linkRow];

        self::rollUp('connection', self::CLICK);
        self::assertSame($eachVisit, self::reportRows(self::CLICK), 'by id');
        self::assertSame(['0|0', '1|0'], self::flags(self::CLICK), 'by id, each row its own flags');

        self::q('DELETE FROM 202_dataengine WHERE click_id = ' . self::CLICK);
        self::rebuild(self::$hour + 3 * 3600, self::$hour + 3 * 3600 + 3599);
        self::rebuild(self::$hour, self::$hour + 3599);
        self::assertSame($eachVisit, self::reportRows(self::CLICK), 'the latest visit\'s hour, then the first\'s');
        self::assertSame(['0|0', '1|0'], self::flags(self::CLICK), 'by the hour, each row its own flags');

        self::q('UPDATE 202_clicks SET ppc_account_id = ' . self::FIRST_ACCOUNT . ', click_cpc = 0.75,'
            . ' click_filtered = 0, click_bot = 0 WHERE click_id = ' . self::CLICK . " AND click_time = $latest");
        LatestVisit::refresh(new Connection(self::requireScratch()), self::CLICK);
        self::rollUp('connection', self::CLICK);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'recorded');
        self::assertSame(['0|0'], self::flags(self::CLICK), 'recorded, the first visit\'s flags');
    }

    /**
     * A re-clicked click is filtered, or a bot, as its first visit was: the
     * re-click writer copies the first visit's flags onto the re-click's row,
     * and the rollup reads the latest row's own. A recorded re-click whose
     * row carries flags of its own is not a state the writer or the backfill
     * leaves. A click with one visit keeps its own flags, on the redirector
     * or off it. By id and by the hour's rebuild alike.
     */
    public function testAReClickedClicksFlagsAreTheOnesItsLatestRowCarries(): void
    {
        $first = self::firstVisitTime();
        $latest = self::latestVisitTime();
        [$campaign, $account] = [self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT];
        $reCampaign = self::LATEST_CAMPAIGN;
        self::visit(self::CLICK, $first, $campaign, $account, '0.75', '1.00');
        self::visit(self::CLICK, $latest, $reCampaign, $account, '0.75', '2.50', self::USER, true);
        self::visit(self::SECOND_CLICK, $first, $campaign, $account, '0.75', '1.00', self::USER, false, 1, 1);
        self::visit(self::SECOND_CLICK, $latest, $reCampaign, $account, '0.75', '2.50', self::USER, true, 1, 1);
        $other = self::OTHERS_CAMPAIGN;
        self::visit(self::OTHERS_CLICK, $latest, $other, $account, '0.10', '2.50', self::OTHER, true, 1, 0, false);
        self::visit(self::FOURTH_CLICK, $latest, $campaign, $account, '0.10', '2.50', self::USER, false, 0, 1);
        $conn = new Connection(self::requireScratch());
        foreach (self::CLICKS as $click) {
            ClickRollupSql::rollUpClick($conn, $click);
        }
        $expected = [
            self::CLICK => ['0|0'], self::SECOND_CLICK => ['1|1'], self::OTHERS_CLICK => ['1|0'],
            self::FOURTH_CLICK => ['0|1'],
        ];
        foreach ($expected as $click => $flags) {
            self::assertSame($flags, self::flags($click), "click $click by id");
        }

        self::q('DELETE FROM 202_dataengine WHERE click_id IN (' . implode(', ', self::CLICKS) . ')');
        self::rebuild(self::$hour + 3 * 3600, self::$hour + 3 * 3600 + 3599);
        foreach ($expected as $click => $flags) {
            self::assertSame($flags, self::flags($click), "click $click by the hour");
        }
    }

    /**
     * The hour's rebuild reads its window's ends as bounds: a latest visit
     * at either end is rolled up, and its click's row at an earlier visit,
     * before the window, removed; a click whose latest visit is a second
     * past the end is left as it is; a click with one visit at the end is
     * rolled up with its own flags.
     */
    public function testAnHourRebuildReadsTheVisitsAtItsEdges(): void
    {
        $start = self::$hour + 3 * 3600;
        $end = $start + 3599;
        [$campaign, $account] = [self::FIRST_CAMPAIGN, self::FIRST_ACCOUNT];
        $reCampaign = self::LATEST_CAMPAIGN;
        self::visit(self::CLICK, $start - 3600, $campaign, $account, '0.75', '1.00');
        self::visit(self::CLICK, $start, $reCampaign, $account, '0.75', '2.50', self::USER, true);
        self::visit(self::SECOND_CLICK, $start - 1, $campaign, $account, '0.75', '1.00');
        self::visit(self::SECOND_CLICK, $end, $reCampaign, $account, '0.75', '2.50', self::USER, true);
        self::visit(self::OTHERS_CLICK, $end, self::OTHERS_CAMPAIGN, $account, '0.10', '2.50', self::OTHER);
        self::visit(self::OTHERS_CLICK, $end + 1, self::OTHERS_CAMPAIGN, $account, '0.10', '2.50', self::OTHER, true);
        self::visit(self::FOURTH_CLICK, $end, $campaign, $account, '0.10', '2.50', self::USER, true, 1, 0, false);
        foreach ([self::CLICK, self::SECOND_CLICK, self::OTHERS_CLICK] as $click) {
            self::rolledUpPerVisit($click);
        }

        self::rebuild($start, $end);

        self::assertSame([(string) $start], self::rowTimes(self::CLICK), 'a latest visit at the start');
        self::assertSame([(string) $end], self::rowTimes(self::SECOND_CLICK), 'a latest visit at the end');
        self::assertSame(
            [(string) $end, (string) ($end + 1)],
            self::rowTimes(self::OTHERS_CLICK),
            'a latest visit a second past the end'
        );
        self::assertSame(['1|0'], self::flags(self::FOURTH_CLICK), 'one visit at the end');
    }

    /**
     * Two visits in one second are both latest (LatestVisit::isLatest()) and
     * fold into one row by the key, which holds one of them whole.
     */
    public function testTwoVisitsInOneSecondFoldIntoOneRowOfOneVisit(): void
    {
        self::reClicked(self::CLICK);
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::THIRD_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '3.00', self::USER, true);
        self::rolledUpPerVisit(self::CLICK);
        ClickRollupSql::rollUpClick(new Connection(self::requireScratch()), self::CLICK);

        $rows = self::reportRows(self::CLICK);
        self::assertCount(1, $rows);
        $third = array_replace(
            self::latestRow(),
            ['aff_campaign_id' => (string) self::THIRD_CAMPAIGN, 'payout' => '3.00']
        );
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
            self::assertStringContainsString('its latest visit\'s hour is queued for the cron job', $err);

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

    /**
     * Between a deploy and its upgrade a rollup reads a table the upgrade
     * makes (202_click_latest_visits), and fails. Nothing else re-rolls the
     * click, so each path queues its latest visit's hour, and the cron job's
     * dirty-hours pass rolls it up once the table is there: until then the
     * pass fails and leaves the hour queued.
     *
     * @dataProvider clickRollups
     */
    public function testAClickWhoseRollupFailsIsQueuedForTheCronJob(string $path): void
    {
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50');
        $hour = intdiv($latest, 3600) * 3600;
        $queued = [[(string) self::USER, '0', '0', (string) $hour, (string) ($hour + 3599), '0']];

        self::q('RENAME TABLE 202_click_latest_visits TO 202_click_latest_visits_away');
        try {
            $log = self::failedRollUp($path, self::CLICK);
            self::assertStringContainsString('[errno 1146]', $log);
            self::assertStringContainsString(
                'its latest visit\'s hour is queued for the cron job to roll up again',
                $log
            );
            self::assertSame($queued, self::dirtyHours());

            [$out] = self::runEngine(['full', 'dirty-hours']);
            self::assertStringStartsWith('THREW ', $out, 'the pass fails while the table is missing');
            self::assertSame($queued, self::dirtyHours(), 'and leaves the hour queued');
        } finally {
            self::q('RENAME TABLE 202_click_latest_visits_away TO 202_click_latest_visits');
        }
        self::assertSame([], self::reportRows(self::CLICK));

        [$out, $err] = self::runEngine(['full', 'dirty-hours']);
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame([self::latestRow()], self::reportRows(self::CLICK), 'the cron job rolled the click up');
        self::assertSame([], self::dirtyHours());
    }

    /** A click with no row has nothing to queue, and says so. */
    public function testAFailedRollupOfAClickWithNoRowQueuesNothing(): void
    {
        $line = ClickRollupSql::queueRetry(new Connection(self::requireScratch()), self::CLICK);
        self::assertSame('no row of the click was found to queue', $line);
        self::assertSame([], self::dirtyHours());
    }

    /**
     * An hour the pass has already taken and marked (processed, and deleted
     * at the end of the pass) is armed again, not left to be deleted.
     */
    public function testAQueuedHourAlreadyTakenIsArmedAgain(): void
    {
        $latest = self::latestVisitTime();
        self::visit(self::CLICK, $latest, self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50');
        $hour = intdiv($latest, 3600) * 3600;
        self::row('202_dirty_hours', [
            'ppc_account_id' => 0, 'aff_campaign_id' => 0, 'user_id' => self::USER, 'click_time_from' => $hour,
            'click_time_to' => $hour + 3599, 'landing_page_id' => 0,
        ]);
        self::q("UPDATE 202_dirty_hours SET processed = 1, deleted = 1 WHERE user_id = " . self::USER);

        ClickRollupSql::queueRetry(new Connection(self::requireScratch()), self::CLICK);

        $result = self::$db->query('SELECT processed + 0, deleted + 0 FROM 202_dirty_hours WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        self::assertSame([['0', '0']], $result->fetch_all());
    }

    /** A queue that fails is logged with why, and the caller's line says the rows are out of date. */
    public function testAQueueThatFailsSaysSo(): void
    {
        self::visit(self::CLICK, self::latestVisitTime(), self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50');
        self::q('RENAME TABLE 202_dirty_hours TO 202_dirty_hours_away');
        try {
            $line = ClickRollupSql::queueRetry(new Connection(self::requireScratch()), self::CLICK);
        } finally {
            self::q('RENAME TABLE 202_dirty_hours_away TO 202_dirty_hours');
        }
        self::assertStringStartsWith('its latest visit\'s hour could not be queued for the cron job (', $line);
        self::assertStringContainsString('[errno 1146]', $line);
        self::assertStringEndsWith(
            'so its report rows are out of date until it or that hour is rolled up again',
            $line
        );
    }

    /**
     * Roll the click up on $path, expecting a failure: what each path logs.
     */
    private static function failedRollUp(string $path, int $click): string
    {
        if ($path === 'connection') {
            $log = tempnam(sys_get_temp_dir(), 'p202-rollup-log');
            self::assertIsString($log);
            $was = ini_set('error_log', $log);
            try {
                (new MysqlConversionRepository(new Connection(self::requireScratch())))->refreshClickReport($click);
            } finally {
                ini_set('error_log', (string) $was);
            }
            $line = (string) file_get_contents($log);
            unlink($log);
            self::assertStringContainsString('conversion ledger: click ' . $click . ' changed but its report row'
                . ' was not refreshed: ', $line);

            return $line;
        }
        [$out, $err] = self::runEngine([$path, 'click', (string) $click]);
        if ($path === 'slim') {
            self::assertSame('RETURNED false', $out, $err);
        } else {
            self::assertSame('THREW ' . QueryException::class . ': DataEngine rollup of click ' . $click
                . ' failed [errno 1146]', $out, $err);
        }

        return $err;
    }

    /** @return list<list<string>> the account's queued hours: user, traffic source account, campaign, from, to, processed */
    private static function dirtyHours(): array
    {
        $result = self::$db->query('SELECT user_id, ppc_account_id, aff_campaign_id, click_time_from, click_time_to,'
            . ' processed + 0 FROM 202_dirty_hours WHERE user_id = ' . self::USER . ' ORDER BY click_time_from');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return $result->fetch_all();
    }

    /**
     * A click whose rows name two accounts (written before the account tie):
     * a rollup of it removes only its latest visit's account's earlier rows
     * (`de.user_id = 2c.user_id`), never the other account's report row.
     *
     * @dataProvider clickRollups
     */
    public function testARollupRemovesOnlyTheLatestVisitsAccountsEarlierRows(string $path): void
    {
        self::visit(self::CLICK, self::firstVisitTime(), self::OTHERS_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '1.00', self::OTHER);
        self::visit(self::CLICK, self::latestVisitTime(), self::LATEST_CAMPAIGN, self::FIRST_ACCOUNT, '0.75', '2.50', self::USER, true);
        self::rolledUpPerVisit(self::CLICK);

        self::rollUp($path, self::CLICK);

        $result = self::$db->query('SELECT user_id, click_time FROM 202_dataengine WHERE click_id = ' . self::CLICK
            . ' ORDER BY click_time');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        self::assertSame(
            [[(string) self::OTHER, (string) self::firstVisitTime()], [(string) self::USER, (string) self::latestVisitTime()]],
            $result->fetch_all(),
            'the other account\'s row stays'
        );
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

    /**
     * A second connection holding the click's report row at $time, or the
     * place it would go when there is none, until rolled back.
     */
    private static function lockReportRow(int $click, int $time, bool $present = true): \mysqli
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
        self::assertSame($present ? 1 : 0, $held->num_rows, $present ? 'the row is there to hold' : 'no row yet');

        return $lock;
    }
}
