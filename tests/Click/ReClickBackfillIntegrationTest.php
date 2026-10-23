<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\ReClickBackfill;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;

/**
 * ReClickBackfill on a real server, over clicks re-clicked as rtr.php wrote
 * them before 1.9.79: the re-click's row with the redirector link's traffic
 * source and cost, no lead, the duplicate filter's flag, and no record of
 * which visit is the latest, with a report row per visit. The walk gives
 * every row the first visit's source, cost and flags and the click's lead and
 * value, records the latest visit, marks the click for the attribution rollup
 * and rolls its report rows up again; and touches no click with one visit,
 * none above the marker, and none whose rows name two accounts.
 *
 * The click tables are emptied for each test, so the walk from 0 reads only
 * the clicks here.
 *
 * @group integration
 */
final class ReClickBackfillIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990701;
    private const OTHER = 990702;
    private const NOW = 1_790_000_000;

    /** Converted on its first visit (a lead at 3.00), re-clicked onto another campaign. */
    private const CONVERTED = 11;
    /** Re-clicked, never converted: each visit keeps its own payout. */
    private const NOT_A_LEAD = 12;
    private const ONE_VISIT = 13;
    /** A chunk further on. */
    private const LATER = 5012;
    /** Re-clicked after the upgrade marked the newest click. */
    private const AFTER_MARK = 6000;

    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_record', '202_clicks_rotator',
        '202_clicks_tracking', '202_clicks_site', '202_dataengine', '202_click_latest_visits', '202_reclick_backfill',
        '202_attribution_rollup_dirty_clicks',
    ];

    private string $log = '';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_test_record_refused');
            self::q('DROP TRIGGER IF EXISTS p202_test_repair_waits');
            foreach (self::TABLES as $table) {
                self::q("TRUNCATE TABLE $table");
            }
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DROP TRIGGER IF EXISTS p202_test_record_refused');
        foreach (self::TABLES as $table) {
            self::q("TRUNCATE TABLE $table");
        }
        $log = tempnam(sys_get_temp_dir(), 'p202-reclick-log');
        self::assertIsString($log);
        $this->log = $log;
    }

    protected function tearDown(): void
    {
        if ($this->log !== '' && is_file($this->log)) {
            unlink($this->log);
        }
    }

    /**
     * One visit's rows in 202_clicks and 202_clicks_spy, and the click's own
     * rows once.
     *
     * @param array{campaign: int, source: int, cpc: string, lead: int, payout: string, filtered: int, bot: int} $v
     */
    private static function visit(int $click, int $time, array $v, int $user = self::USER): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $click, user_id = $user, aff_campaign_id = {$v['campaign']},"
                . " ppc_account_id = {$v['source']}, click_cpc = {$v['cpc']}, click_lead = {$v['lead']},"
                . " click_payout = {$v['payout']}, click_filtered = {$v['filtered']}, click_bot = {$v['bot']},"
                . " click_time = $time" . ($table === '202_clicks' ? ', rotator_id = 7, rule_id = 9' : ''));
        }
        self::q("INSERT IGNORE INTO 202_clicks_advance SET click_id = $click, ip_id = 0, country_id = 0,"
            . ' region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0');
        self::q("INSERT IGNORE INTO 202_clicks_record SET click_id = $click, click_id_public = $click");
        self::q("INSERT IGNORE INTO 202_clicks_rotator SET click_id = $click, rotator_id = 7, rule_id = 8,"
            . ' rule_redirect_id = 9');
    }

    /** A report row per visit, as a rollup before 1.9.79 wrote them. */
    private static function rolledUpPerVisit(int $click): void
    {
        self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
            . ' landing_page_id, click_filtered, clicks, leads, payout, income, cost)'
            . ' SELECT c.user_id, c.click_id, c.click_time, c.ppc_account_id, c.aff_campaign_id, 0, c.click_filtered,'
            . ' 1, c.click_lead, c.click_payout, c.click_lead * c.click_payout, c.click_cpc FROM 202_clicks AS c'
            . " WHERE c.click_id = $click");
    }

    /** The first visit, from source 3 at 0.25, neither filtered nor a bot. */
    private static function first(int $campaign, int $lead, string $payout): array
    {
        return [
            'campaign' => $campaign, 'source' => 3, 'cpc' => '0.25', 'lead' => $lead, 'payout' => $payout,
            'filtered' => 0, 'bot' => 0,
        ];
    }

    /** A re-click's row as rtr.php wrote it before 1.9.79: the link's source 13 at 0.75, no lead, a duplicate and a bot. */
    private static function legacyReClick(int $campaign, string $payout): array
    {
        return [
            'campaign' => $campaign, 'source' => 13, 'cpc' => '0.75', 'lead' => 0, 'payout' => $payout,
            'filtered' => 1, 'bot' => 1,
        ];
    }

    /** A click visited at $at and re-clicked an hour later as rtr.php wrote it before 1.9.79. */
    private static function legacyReClicked(int $click, int $at, int $lead, string $payout): void
    {
        self::visit($click, $at, self::first(1, $lead, $payout));
        self::visit($click, $at + 3600, self::legacyReClick(2, '7.00'));
        self::rolledUpPerVisit($click);
    }

    /** The upgrade's marker: the newest click, read on its own, then the walk up to it. */
    private static function mark(): void
    {
        $newest = self::$db->query(ReClickBackfill::NEWEST_CLICK_SQL);
        self::assertInstanceOf(\mysqli_result::class, $newest, self::$db->error);
        self::q(ReClickBackfill::markSql((int) $newest->fetch_assoc()['newest']));
    }

    private function backfill(int $now = self::NOW): ReClickBackfill
    {
        return new ReClickBackfill(new Connection(self::requireScratch()), static fn (): int => $now);
    }

    /** @return array<string, mixed>|null the walk at $now, with what it logged in $this->log */
    private function walk(int $deadline = PHP_INT_MAX, int $now = self::NOW): ?array
    {
        $before = ini_get('error_log');
        ini_set('error_log', $this->log);
        try {
            return $this->backfill($now)->run($deadline);
        } finally {
            ini_set('error_log', (string) $before);
        }
    }

    private function logged(): string
    {
        return (string) file_get_contents($this->log);
    }

    /** @return list<list<string>> the click's rows in $table, oldest first */
    private static function rows(string $table, int $click, string $columns): array
    {
        $result = self::$db->query(
            "SELECT $columns FROM $table WHERE click_id = $click ORDER BY click_time, aff_campaign_id"
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return $result->fetch_all(MYSQLI_NUM);
    }

    private static function recorded(int $click): ?string
    {
        $result = self::$db->query("SELECT click_time FROM 202_click_latest_visits WHERE click_id = $click");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);
        $row = $result->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    /** @return list<int> the clicks marked for the attribution rollup, as the account's */
    private static function marked(): array
    {
        $result = self::$db->query('SELECT DISTINCT click_id FROM 202_attribution_rollup_dirty_clicks'
            . ' WHERE user_id = ' . self::USER . ' ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_map('intval', array_column($result->fetch_all(), 0));
    }

    private const SOURCE = 'ppc_account_id, click_cpc, click_filtered, click_bot, click_lead, click_payout';

    public function testTheWalkBringsEveryClickReClickedBeforeTheUpgradeToTheRule(): void
    {
        $at = self::NOW - 30 * 86400;
        self::legacyReClicked(self::CONVERTED, $at, 1, '3.00');
        self::legacyReClicked(self::NOT_A_LEAD, $at, 0, '1.00');
        self::visit(self::ONE_VISIT, $at, self::legacyReClick(2, '7.00'));
        self::legacyReClicked(self::LATER, $at, 0, '1.00');
        self::mark();
        self::legacyReClicked(self::AFTER_MARK, $at, 0, '1.00');
        $afterMark = self::rows('202_clicks', self::AFTER_MARK, self::SOURCE);

        $did = $this->walk();

        self::assertSame([
            'reclicked_clicks' => 3, 'same_second_ties' => 0, 'mixed_accounts' => 0, 'skipped_clicks' => 0,
            'next_click_id' => self::LATER + 1, 'through_click_id' => self::LATER, 'finished' => true,
            'failed_click_id' => null,
        ], $did);
        self::assertSame('', $this->logged(), 'success logs nothing');
        // Every row: the first visit's source, cost and flags; the click's lead and value.
        self::assertSame(
            [['1', '3', '0.25000', '0', '0', '1', '3.00000'], ['2', '3', '0.25000', '0', '0', '1', '3.00000']],
            self::rows('202_clicks', self::CONVERTED, 'aff_campaign_id, ' . self::SOURCE)
        );
        self::assertSame(
            [['1', '3', '0.25', '0', '0', '1', '3.00'], ['2', '3', '0.25', '0', '0', '1', '3.00']],
            self::rows('202_clicks_spy', self::CONVERTED, 'aff_campaign_id, ' . self::SOURCE)
        );
        self::assertSame(
            [['3', '0.25000', '0', '0', '0', '1.00000'], ['3', '0.25000', '0', '0', '0', '7.00000']],
            self::rows('202_clicks', self::NOT_A_LEAD, self::SOURCE),
            'a click that is no lead: each visit keeps its own payout'
        );
        foreach ([self::CONVERTED, self::NOT_A_LEAD, self::LATER] as $click) {
            self::assertSame((string) ($at + 3600), self::recorded($click), "click $click's latest visit is recorded");
        }
        self::assertNull(self::recorded(self::ONE_VISIT));
        self::assertSame(
            [['13', '0.75000', '1', '1', '0', '7.00000']],
            self::rows('202_clicks', self::ONE_VISIT, self::SOURCE),
            'a click with one visit is not touched'
        );
        self::assertNull(self::recorded(self::AFTER_MARK));
        self::assertSame(
            $afterMark,
            self::rows('202_clicks', self::AFTER_MARK, self::SOURCE),
            'nor one above the marker'
        );
        self::assertSame([self::CONVERTED, self::NOT_A_LEAD, self::LATER], self::marked());
        self::assertSame(
            [[(string) ($at + 3600), '0', '1', '3.00000']],
            self::rows('202_dataengine', self::CONVERTED, 'click_time, click_filtered, leads, income'),
            'one report row, at the latest visit, unfiltered as the first visit was, with the lead and its income'
        );
        self::assertSame(
            [[(string) ($at + 3600), '0', '0']],
            self::rows('202_dataengine', self::NOT_A_LEAD, 'click_time, click_filtered, leads')
        );

        $state = $this->backfill()->state();
        self::assertSame(self::NOW, $state['finished_at'] ?? null);
        self::assertNull($this->backfill()->progress(), 'done: the reports stop saying so');
        self::assertNull($this->walk(), 'and the next run does nothing');

        // A walk interrupted after its work and before its cursor moved runs
        // the same clicks again and writes what they already hold.
        $rows = fn (): array => array_map(
            static fn (int $c): array => [
                self::rows('202_clicks', $c, 'click_time, aff_campaign_id, ' . self::SOURCE),
                self::rows('202_clicks_spy', $c, 'click_time, aff_campaign_id, ' . self::SOURCE),
                self::recorded($c),
                self::rows('202_dataengine', $c, 'click_time, click_filtered, leads, income, cost'),
            ],
            [self::CONVERTED, self::NOT_A_LEAD, self::LATER]
        );
        $once = $rows();
        self::q('UPDATE 202_reclick_backfill SET next_click_id = 0, finished_at = NULL');
        self::assertSame(3, $this->walk()['reclicked_clicks'] ?? null);
        self::assertSame($once, $rows());
    }

    /** A run stops at its deadline after a chunk, and the reports say how far the walk is. */
    public function testARunStopsAtItsDeadlineAndTheWalkSaysHowFarItIs(): void
    {
        $at = self::NOW - 86400;
        self::legacyReClicked(self::CONVERTED, $at, 1, '3.00');
        self::legacyReClicked(self::LATER, $at, 0, '1.00');
        self::mark();
        $progress = $this->backfill()->progress();
        self::assertSame([true, 0, 0], [$progress['in_progress'] ?? null, $progress['percent'] ?? null,
            $progress['reclicked_clicks'] ?? null]);

        $did = $this->walk(self::NOW);

        self::assertSame([1, ReClickBackfill::CHUNK, false], [$did['reclicked_clicks'] ?? null,
            $did['next_click_id'] ?? null, $did['finished'] ?? null], 'one chunk, the first');
        self::assertNull(self::recorded(self::LATER));
        $progress = $this->backfill()->progress();
        self::assertSame([99, 1], [$progress['percent'] ?? null, $progress['reclicked_clicks'] ?? null]);

        self::assertTrue($this->walk(self::NOW)['finished'] ?? null);
        self::assertSame((string) ($at + 3600), self::recorded(self::LATER));
    }

    /**
     * The deadline is asked before each click, not each chunk: a chunk dense
     * with re-clicks ran 85 s past a 10 s deadline. A run past its deadline
     * repairs one click and leaves the cursor at the next it did not repair.
     */
    public function testARunStopsMidChunkAtItsDeadline(): void
    {
        $at = self::NOW - 86400;
        self::legacyReClicked(self::CONVERTED, $at, 1, '3.00');
        self::legacyReClicked(self::NOT_A_LEAD, $at, 0, '1.00');
        self::mark();

        $did = $this->walk(self::NOW);

        self::assertNotNull($did);
        self::assertSame(
            [1, self::NOT_A_LEAD, false, null],
            [$did['reclicked_clicks'], $did['next_click_id'], $did['finished'], $did['failed_click_id']],
            'one click, and the cursor at the next'
        );
        self::assertNull(self::recorded(self::NOT_A_LEAD));
        self::assertSame('', $this->logged(), 'a stop is not a failure');

        $did = $this->walk(self::NOW);
        self::assertSame([1, true], [$did['reclicked_clicks'] ?? null, $did['finished'] ?? null]);
        self::assertSame((string) ($at + 3600), self::recorded(self::NOT_A_LEAD));
    }

    /**
     * A click re-clicked every minute for months has more visit seconds than
     * a statement takes placeholders (65,535): its repair named each second
     * and failed on every run, and the walk, which never steps over a click,
     * stopped there for good.
     */
    public function testAClickWithMoreVisitsThanAStatementTakesPlaceholdersIsRepaired(): void
    {
        $at = self::NOW - 90 * 86400;
        $visits = 70_000;
        self::visit(self::CONVERTED, $at, self::first(1, 1, '3.00'));
        $digit = '(SELECT 0 AS d UNION ALL SELECT 1 UNION ALL SELECT 2 UNION ALL SELECT 3 UNION ALL SELECT 4'
            . ' UNION ALL SELECT 5 UNION ALL SELECT 6 UNION ALL SELECT 7 UNION ALL SELECT 8 UNION ALL SELECT 9)';
        self::q('INSERT INTO 202_clicks (click_id, user_id, aff_campaign_id, ppc_account_id, click_cpc, click_lead,'
            . ' click_payout, click_filtered, click_bot, click_time, rotator_id, rule_id)'
            . ' SELECT ' . self::CONVERTED . ', ' . self::USER . ', 2, 13, 0.75, 0, 7.00, 1, 1,'
            . " $at + 60 * (1 + a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d), 7, 9"
            . " FROM $digit AS a, $digit AS b, $digit AS c, $digit AS e, $digit AS f"
            . ' WHERE a.d + 10 * b.d + 100 * c.d + 1000 * e.d + 10000 * f.d < ' . $visits);
        self::legacyReClicked(self::NOT_A_LEAD, $at, 0, '1.00');
        self::mark();

        $did = $this->walk();

        self::assertNotNull($did);
        self::assertSame([2, true, null], [$did['reclicked_clicks'], $did['finished'], $did['failed_click_id']], $this->logged());
        $rows = self::$db->query('SELECT COUNT(*), SUM(ppc_account_id = 3 AND click_cpc = 0.25 AND click_lead = 1'
            . ' AND click_payout = 3.00 AND click_filtered = 0 AND click_bot = 0) FROM 202_clicks WHERE click_id = '
            . self::CONVERTED)->fetch_row();
        self::assertSame([(string) ($visits + 1), (string) ($visits + 1)], $rows, 'every visit has the first visit\'s source and the lead');
        self::assertSame((string) ($at + 60 * $visits), self::recorded(self::CONVERTED));
        self::assertSame((string) ($at + 3600), self::recorded(self::NOT_A_LEAD), 'and the walk went on past it');
    }

    /**
     * Two rows in a click's latest second are both its latest visit: counted,
     * and left as they are, since nothing says which the visitor made last.
     */
    public function testTwoRowsInTheLatestSecondAreCountedNotFolded(): void
    {
        $at = self::NOW - 86400;
        self::visit(self::CONVERTED, $at, self::first(1, 0, '1.00'));
        self::visit(self::CONVERTED, $at + 60, self::legacyReClick(2, '7.00'));
        self::visit(self::CONVERTED, $at + 60, self::legacyReClick(3, '9.00'));
        self::mark();

        $did = $this->walk();

        self::assertSame([1, 1], [$did['reclicked_clicks'] ?? null, $did['same_second_ties'] ?? null]);
        self::assertSame(1, $this->backfill()->state()['same_second_ties'] ?? null);
        self::assertSame(
            [['1', '3', '0.25000'], ['2', '3', '0.25000'], ['3', '3', '0.25000']],
            self::rows('202_clicks', self::CONVERTED, 'aff_campaign_id, ppc_account_id, click_cpc'),
            'three rows, each with the first visit\'s source'
        );
        self::assertSame((string) ($at + 60), self::recorded(self::CONVERTED));
    }

    /** A click whose rows name two accounts is left as it is, counted and logged; the walk goes on past it. */
    public function testAClickOfTwoAccountsIsLeftAsItIs(): void
    {
        $at = self::NOW - 86400;
        self::visit(self::CONVERTED, $at, self::first(1, 0, '1.00'));
        self::visit(self::CONVERTED, $at + 60, self::legacyReClick(2, '7.00'), self::OTHER);
        self::legacyReClicked(self::LATER, $at, 0, '1.00');
        self::mark();
        $before = self::rows('202_clicks', self::CONVERTED, self::SOURCE);

        $did = $this->walk();

        self::assertSame([1, 1, true], [$did['reclicked_clicks'] ?? null, $did['mixed_accounts'] ?? null,
            $did['finished'] ?? null]);
        self::assertSame($before, self::rows('202_clicks', self::CONVERTED, self::SOURCE));
        self::assertNull(self::recorded(self::CONVERTED));
        self::assertStringContainsString(
            'click ' . self::CONVERTED . ' has rows of more than one account',
            $this->logged()
        );
        self::assertSame((string) ($at + 3600), self::recorded(self::LATER));
    }

    /**
     * A click that fails is logged once, naming it, without the values the
     * server's words quote; its transaction leaves nothing, the clicks before
     * it in the chunk are done, and the cursor stops at it, so the next run
     * starts there. The walk's progress says it is stopped at that click and
     * how many runs in a row it failed, until a run gets past it.
     */
    public function testAFailedClickIsLoggedAndIsWhereTheNextRunStarts(): void
    {
        $at = self::NOW - 86400;
        foreach ([21, 22, 23] as $click) {
            self::legacyReClicked($click, $at, 1, '3.00');
        }
        self::mark();
        self::q("CREATE TRIGGER p202_test_record_refused BEFORE INSERT ON 202_click_latest_visits FOR EACH ROW"
            . " BEGIN IF NEW.click_id = 22 THEN SIGNAL SQLSTATE '45000'"
            . " SET MESSAGE_TEXT = 'refused for the visitor ''v-secret-9'''; END IF; END");
        $legacy = self::rows('202_clicks', 22, self::SOURCE);

        try {
            $did = $this->walk();
            $stopped = $this->backfill()->progress();
            $again = $this->walk();
            $stillStopped = $this->backfill()->progress();
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_record_refused');
        }
        self::assertSame(
            [22, 1, self::NOW],
            [$stopped['stalled_at_click_id'] ?? null, $stopped['failed_runs'] ?? null, $stopped['failing_since'] ?? null]
        );
        self::assertStringContainsString('stopped at click 22', (string) ($stopped['note'] ?? ''));
        self::assertSame([0, 22], [$again['reclicked_clicks'] ?? null, $again['failed_click_id'] ?? null]);
        self::assertSame(
            [22, 2, self::NOW],
            [
                $stillStopped['stalled_at_click_id'] ?? null,
                $stillStopped['failed_runs'] ?? null,
                $stillStopped['failing_since'] ?? null,
            ],
            'another run in a row, since the first'
        );

        self::assertSame([1, 22, false], [$did['reclicked_clicks'] ?? null, $did['failed_click_id'] ?? null,
            $did['finished'] ?? null]);
        self::assertSame(22, $this->backfill()->state()['next_click_id'] ?? null);
        $lines = array_values(array_filter(explode("\n", $this->logged()), static fn (string $l): bool => $l !== ''));
        self::assertCount(2, $lines, $this->logged());
        self::assertStringContainsString('p202 re-click backfill: click 22 ', $lines[0]);
        self::assertStringNotContainsString('v-secret-9', $lines[0]);
        self::assertSame(
            $legacy,
            self::rows('202_clicks', 22, self::SOURCE),
            'the failed click\'s transaction left nothing'
        );
        self::assertNull(self::recorded(22));
        self::assertNotNull(self::recorded(21));
        self::assertNull(self::recorded(23), 'the run ended at the failure');

        self::assertTrue($this->walk()['finished'] ?? null);
        self::assertSame((string) ($at + 3600), self::recorded(22));
        self::assertSame((string) ($at + 3600), self::recorded(23));
        $state = $this->backfill()->state();
        self::assertSame(
            ['reclicked_clicks' => 3, 'failed_click_id' => null, 'failed_runs' => 0, 'failing_since' => null],
            array_intersect_key((array) $state, array_flip(['reclicked_clicks', 'failed_click_id', 'failed_runs', 'failing_since'])),
            'past the click, the failure is cleared'
        );
    }

    /**
     * A click that fails for an hour of runs is the click's to fail (a value
     * its rows hold), not the moment's: once it has failed in
     * STEP_OVER_RUNS runs in a row over STEP_OVER_SECONDS it is logged,
     * counted and stepped over, and the walk goes on and finishes, saying so.
     * Either condition alone keeps retrying it: ten runs inside a minute (a
     * lock held that long), or two runs hours apart (a cron run hourly).
     */
    public function testAClickThatFailsForAnHourOfRunsIsSteppedOver(): void
    {
        $at = self::NOW - 86400;
        foreach ([31, 32, 33, 34] as $click) {
            self::legacyReClicked($click, $at, 1, '3.00');
        }
        self::mark();
        self::q("CREATE TRIGGER p202_test_record_refused BEFORE INSERT ON 202_click_latest_visits FOR EACH ROW"
            . " BEGIN IF NEW.click_id IN (32, 34) THEN SIGNAL SQLSTATE '45000'"
            . " SET MESSAGE_TEXT = 'refused by the test'; END IF; END");
        $legacy = self::rows('202_clicks', 32, self::SOURCE);
        $runs = ReClickBackfill::STEP_OVER_RUNS;
        try {
            for ($run = 0; $run < $runs; $run++) {
                $did = $this->walk(PHP_INT_MAX, self::NOW + $run);
                self::assertSame(32, $did['failed_click_id'] ?? null, "run $run stops at the click");
            }
            $inAMinute = $this->backfill(self::NOW + $runs)->progress();
            $past = $this->walk(PHP_INT_MAX, self::NOW + ReClickBackfill::STEP_OVER_SECONDS);
            $next = $this->backfill(self::NOW + ReClickBackfill::STEP_OVER_SECONDS)->state();
            $hoursLater = $this->walk(PHP_INT_MAX, self::NOW + 3 * ReClickBackfill::STEP_OVER_SECONDS);
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_record_refused');
        }
        self::assertSame(
            [32, $runs],
            [$inAMinute['stalled_at_click_id'] ?? null, $inAMinute['failed_runs'] ?? null],
            'ten runs inside a minute retry it'
        );
        self::assertSame(
            [1, 1, 34, false],
            [
                $past['skipped_clicks'] ?? null, $past['reclicked_clicks'] ?? null,
                $past['failed_click_id'] ?? null, $past['finished'] ?? null,
            ],
            'an hour on: stepped over, 33 done, stopped at 34'
        );
        $fields = ['failed_click_id', 'failed_runs', 'skipped_clicks', 'last_skipped_click_id'];
        self::assertSame(
            ['failed_click_id' => 34, 'failed_runs' => 1, 'skipped_clicks' => 1, 'last_skipped_click_id' => 32],
            array_intersect_key((array) $next, array_flip($fields))
        );
        self::assertSame(
            [34, 2],
            [$hoursLater['failed_click_id'] ?? null, $this->backfill()->state()['failed_runs'] ?? null],
            'two runs hours apart retry it'
        );

        self::assertTrue($this->walk()['finished'] ?? null);
        self::assertNull(self::recorded(32), 'the stepped-over click keeps no record: it counts at each visit');
        self::assertSame($legacy, self::rows('202_clicks', 32, self::SOURCE), 'and keeps its rows as they were');
        self::assertNotNull(self::recorded(33));
        self::assertNotNull(self::recorded(34));
        $done = $this->backfill()->progress();
        self::assertSame(
            [false, 1, 32],
            [$done['in_progress'] ?? null, $done['skipped_clicks'] ?? null, $done['last_skipped_click_id'] ?? null],
            'finished with a click stepped over, the walk still says so'
        );
        self::assertStringContainsString('the latest click 32', (string) ($done['note'] ?? ''));
        $skips = array_values(array_filter(
            explode("\n", $this->logged()),
            static fn (string $l): bool => str_contains($l, 'stepped over')
        ));
        self::assertCount(1, $skips, $this->logged());
        self::assertStringContainsString('p202 re-click backfill: click 32 ', $skips[0]);
        self::assertStringContainsString('after failing in ' . ($runs + 1) . ' runs in a row', $skips[0]);
    }

    /**
     * A conversion has rewritten the click's cost on every row and not
     * committed when the repair starts: the repair waits on the click's lock
     * and copies the cost the conversion committed. A repair that read the
     * first visit without the lock read the old cost before the commit and
     * wrote it after, over the conversion's.
     */
    public function testARepairCopiesTheCostAConversionCommittedWhileItWaited(): void
    {
        self::legacyReClicked(self::NOT_A_LEAD, self::NOW - 86400, 0, '1.00');
        $lock = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        self::assertTrue($lock->begin_transaction());
        self::assertNotNull((new \Prosper202\Conversion\Ledger\MysqlConversionLedger(new Connection($lock)))
            ->lockClick(self::NOT_A_LEAD));
        self::assertTrue($lock->query("UPDATE 202_clicks SET click_cpc = '0.50000' WHERE click_id = " . self::NOT_A_LEAD));
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/repair-runner.php', (string) self::NOT_A_LEAD],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($proc);
        try {
            $thread = trim((string) fgets($pipes[1]));
            self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread);
            $deadline = microtime(true) + 15;
            do {
                $waiting = self::$db->query('SELECT COUNT(*) FROM information_schema.INNODB_TRX'
                    . " WHERE trx_mysql_thread_id = $thread AND trx_state = 'LOCK WAIT'");
                self::assertInstanceOf(\mysqli_result::class, $waiting, self::$db->error);
                $waits = (int) $waiting->fetch_row()[0] === 1;
                // The server refreshes INNODB_TRX only once it has gone 0.1s unread.
            } while (!$waits && microtime(true) < $deadline && usleep(250000) === null);
            self::assertTrue($waits, 'the repair waits on the click\'s lock');
        } finally {
            self::assertTrue($lock->commit());
            $lock->close();
        }
        $out = trim((string) stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), $out);
        self::assertSame('repaired', $out);

        $costs = self::$db->query('SELECT click_cpc FROM 202_clicks WHERE click_id = ' . self::NOT_A_LEAD
            . ' ORDER BY click_time')->fetch_all();
        self::assertSame([['0.50000'], ['0.50000']], $costs, 'the cost the conversion committed, on every visit');
    }

    /**
     * A repair holds its click's lock and the rows it rewrote to its commit,
     * and the next click's rows go in meanwhile: each UPDATE stops at the
     * rows the repair read, short of the gap after the newest click's rows,
     * where a new click is inserted. The repair is held, its rows rewritten,
     * by a trigger waiting on a named lock this connection holds.
     */
    public function testARepairOfTheNewestClickHoldsBackNoNewClick(): void
    {
        self::legacyReClicked(self::NOT_A_LEAD, self::NOW - 86400, 0, '1.00');
        $lock = 'p202_test_repair_' . getmypid();
        self::q("CREATE TRIGGER p202_test_repair_waits BEFORE INSERT ON 202_click_latest_visits FOR EACH ROW BEGIN"
            . " IF @p202_test_repair_waits = 1 THEN DO GET_LOCK('$lock', 20); DO RELEASE_LOCK('$lock'); END IF; END");
        $connect = static fn (): \mysqli => new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        $holder = $connect();
        $next = $connect();
        $proc = null;
        try {
            self::assertSame('1', (string) $holder->query("SELECT GET_LOCK('$lock', 0)")->fetch_row()[0]);
            $proc = proc_open(
                [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/repair-runner.php',
                    (string) self::NOT_A_LEAD],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($proc);
            $thread = trim((string) fgets($pipes[1]));
            self::assertMatchesRegularExpression('/\A[1-9][0-9]*\z/', $thread);
            $deadline = microtime(true) + 15;
            do {
                $state = self::$db->query("SELECT STATE FROM information_schema.PROCESSLIST WHERE ID = $thread");
                $held = ($state instanceof \mysqli_result ? $state->fetch_row()[0] ?? null : null) === 'User lock';
            } while (!$held && microtime(true) < $deadline && usleep(100000) === null);
            if (!$held) {
                self::fail('The repair never reached the trigger: ' . stream_get_contents($pipes[2]));
            }

            self::assertTrue($next->query('SET SESSION innodb_lock_wait_timeout = 1'));
            $outcome = 'written';
            try {
                (new MysqlClickRepository(new Connection($next)))->recordClick(
                    new ClickRecord(
                        clickId: self::NOT_A_LEAD + 1,
                        userId: self::USER,
                        clickTime: self::NOW,
                        ppcAccountId: 3,
                        clickCpc: '0.25',
                        recordsCampaignRows: false,
                        rotator: new RotatorRoute(7, 8, 9),
                    ),
                    static fn (): int => 51
                );
            } catch (\Throwable $e) {
                $outcome = $e->getMessage();
            }
            $holder->query("SELECT RELEASE_LOCK('$lock')");
            $repaired = trim((string) stream_get_contents($pipes[1])) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($proc));
            $proc = null;
        } finally {
            if (is_resource($proc)) {
                $holder->query("SELECT RELEASE_LOCK('$lock')");
                proc_close($proc);
            }
            $holder->close();
            $next->close();
            self::q('DROP TRIGGER IF EXISTS p202_test_repair_waits');
        }

        self::assertSame('written', $outcome, 'the next click\'s rows went in while the repair held its click');
        self::assertSame('repaired', $repaired);
        self::assertSame((string) (self::NOW - 86400 + 3600), self::recorded(self::NOT_A_LEAD));
    }

    public function testAnInstallWithNoMarkerHasNothingToWalk(): void
    {
        self::legacyReClicked(self::CONVERTED, self::NOW - 86400, 0, '1.00');

        self::assertNull($this->walk());
        self::assertNull($this->backfill()->progress());
        self::assertNull(self::recorded(self::CONVERTED));
    }
}
