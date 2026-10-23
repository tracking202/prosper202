<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Click\ReClickBackfill;
use Prosper202\Click\RotatorRoute;
use Prosper202\Database\Connection;
use Prosper202\Database\Tables\ClickTables;

/**
 * The 1.9.78 -> 1.9.79 rung creates 202_click_latest_visits and the re-click
 * backfill's marker, the newest click at the upgrade, and walks nothing; the
 * cron's walk (ReClickBackfill) then brings every click re-clicked before it
 * to the rule a re-click is written by since, records its latest visit and
 * rolls it up once, at that visit. That covers a re-click this release's code
 * made before the upgrade created the table, which rtr.php lets happen: it
 * does not ask whether the database is upgraded.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78, without the two tables,
 * as a 1.9.78 install is; the walk as the cron runs it.
 *
 * @group integration
 */
final class ReClickRollupUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990951;
    /** Converted on its first visit and re-clicked as rtr.php wrote it before 1.9.79. */
    private const LEGACY = 21;
    private const ONE_VISIT = 22;
    /** Re-clicked by this release's code before the upgrade created the record's table. */
    private const BEFORE_UPGRADE = 23;

    /** Emptied for each test: the walk from 0 reads only the clicks here. */
    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_record',
        '202_clicks_rotator', '202_clicks_site', '202_dataengine', '202_attribution_rollup_dirty_clicks',
    ];

    private static int $at = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::$at = (intdiv(time(), 3600) - 96) * 3600 + 600;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::q('DROP TRIGGER IF EXISTS p202_test_mark_refused');
        foreach (self::TABLES as $table) {
            self::q("TRUNCATE TABLE $table");
        }
        // Back as the installer leaves them, for the classes after this one.
        self::q('DROP TABLE IF EXISTS 202_click_latest_visits, 202_reclick_backfill');
        self::q(ClickTables::clickLatestVisits()->createStatement);
        self::q(ClickTables::reClickBackfill()->createStatement);
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DROP TRIGGER IF EXISTS p202_test_mark_refused');
        foreach (self::TABLES as $table) {
            self::q("TRUNCATE TABLE $table");
        }
        // A 1.9.78 install has neither.
        self::q('DROP TABLE IF EXISTS 202_click_latest_visits, 202_reclick_backfill');
        self::stored('1.9.78');
    }

    private static function stored(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    /**
     * One visit's rows in 202_clicks and 202_clicks_spy, and the click's own
     * rows once.
     */
    private static function visit(int $click, int $time, int $source, string $cpc, int $lead, int $filtered): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            self::q("INSERT INTO $table SET click_id = $click, user_id = " . self::USER . ', aff_campaign_id = 0,'
                . " ppc_account_id = $source, click_cpc = $cpc, click_lead = $lead, click_payout = 3.00,"
                . " click_filtered = $filtered, click_time = $time");
        }
        self::q("INSERT IGNORE INTO 202_clicks_advance SET click_id = $click, text_ad_id = 0, keyword_id = 0,"
            . ' ip_id = 0, country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0');
        self::q("INSERT IGNORE INTO 202_clicks_record SET click_id = $click, click_id_public = $click");
    }

    /** The report rows a rollup before 1.9.79 wrote: one per visit, each with the visit's own values. */
    private static function rolledUpPerVisit(int $click): void
    {
        self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
            . ' landing_page_id, click_filtered, clicks, leads, payout, income, cost)'
            . ' SELECT c.user_id, c.click_id, c.click_time, c.ppc_account_id, c.aff_campaign_id, 0,'
            . ' c.click_filtered, 1, c.click_lead, c.click_payout, c.click_lead * c.click_payout, c.click_cpc'
            . " FROM 202_clicks AS c WHERE c.click_id = $click");
    }

    /**
     * A click from source 3 at 0.25 that converted, re-clicked three hours
     * later through a link of source 13 at 0.75 as rtr.php wrote it before
     * 1.9.79 (no lead, the duplicate filter's flag), rolled up per visit; and
     * a click with one visit.
     */
    private static function seed(): void
    {
        self::visit(self::LEGACY, self::$at, 3, '0.25', 1, 0);
        self::visit(self::LEGACY, self::$at + 3 * 3600, 13, '0.75', 0, 1);
        self::rolledUpPerVisit(self::LEGACY);
        self::visit(self::ONE_VISIT, self::$at + 6 * 3600, 3, '0.25', 0, 0);
        self::rolledUpPerVisit(self::ONE_VISIT);
    }

    /** A redirector click and its re-click by this release's code: what it logged. */
    private static function reClickedBeforeTheUpgrade(): string
    {
        $repo = new MysqlClickRepository(new Connection(self::requireScratch()));
        $repo->recordClick(
            new ClickRecord(
                clickId: self::BEFORE_UPGRADE,
                userId: self::USER,
                clickTime: self::$at,
                ppcAccountId: 3,
                clickCpc: '0.25',
                recordsCampaignRows: false,
                rotator: new RotatorRoute(7, 8, 9),
            ),
            static fn (): int => 51
        );
        $log = tempnam(sys_get_temp_dir(), 'p202-reclick-log');
        self::assertIsString($log);
        $before = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            $repo->recordReClick(
                new ClickRecord(
                    clickId: self::BEFORE_UPGRADE,
                    userId: self::USER,
                    clickTime: self::$at + 3600,
                    ppcAccountId: 13,
                    clickCpc: '0.75',
                    recordsCampaignRows: false,
                    rotator: new RotatorRoute(17, 18, 19),
                ),
                static fn (): int => 52
            );
        } finally {
            ini_set('error_log', (string) $before);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        return $logged;
    }

    /** @return array{string, string} what the runner printed, and what was logged */
    private static function upgrade(): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");

        return [trim($out), $err];
    }

    /** The cron's walk, as 202-cronjobs/index.php runs it. */
    private static function cronWalk(): ?array
    {
        $walk = new ReClickBackfill(new Connection(self::requireScratch()));

        return $walk->run(time() + ReClickBackfill::RUN_SECONDS);
    }

    private static function version(): string
    {
        return (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0];
    }

    /** @return list<list<string>> */
    private static function all(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_NUM);
    }

    /** @return list<list<string>> the click's visits: time (from the first visit's hour), source, cost, lead, filtered */
    private static function visits(int $click): array
    {
        return self::all('SELECT click_time - ' . self::$at . ', ppc_account_id, click_cpc, click_lead, click_filtered'
            . " FROM 202_clicks WHERE click_id = $click ORDER BY click_time");
    }

    /** @return list<list<string>> the click's report rows: time (from the first visit), filtered, leads, income */
    private static function reportRows(int $click): array
    {
        return self::all('SELECT click_time - ' . self::$at . ', click_filtered, leads, income FROM 202_dataengine'
            . " WHERE click_id = $click ORDER BY click_time");
    }

    /** @return list<list<string>> */
    private static function recorded(): array
    {
        return self::all(
            'SELECT click_id, click_time - ' . self::$at . ' FROM 202_click_latest_visits ORDER BY click_id'
        );
    }

    /**
     * The rung creates the tables and the marker, logs nothing and walks
     * nothing; the cron's walk brings the legacy re-click and the one made
     * before the upgrade to the rule, records both and rolls each up once,
     * at its latest visit; run again, the rung and the walk do nothing more.
     */
    public function testTheRungMarksTheReClicksAndTheCronsWalkBringsThemToTheRule(): void
    {
        self::seed();
        $logged = self::reClickedBeforeTheUpgrade();
        self::assertStringContainsString('p202 re-click: click ' . self::BEFORE_UPGRADE . ' was re-clicked before'
            . ' the 1.9.79 upgrade created 202_click_latest_visits', $logged, 'kept, and said');
        self::assertCount(2, self::visits(self::BEFORE_UPGRADE));
        $legacy = self::visits(self::LEGACY);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.79', self::version());
        self::assertSame(
            [['0', (string) self::BEFORE_UPGRADE, '1']],
            self::all('SELECT next_click_id, through_click_id, finished_at IS NULL FROM 202_reclick_backfill'),
            'the newest click at the upgrade, nothing walked'
        );
        self::assertSame([], self::recorded());
        self::assertSame($legacy, self::visits(self::LEGACY), 'the request rewrote no click');

        $walk = self::cronWalk();

        self::assertSame([2, true], [$walk['reclicked_clicks'] ?? null, $walk['finished'] ?? null]);
        $rule = [['0', '3', '0.25000', '1', '0'], ['10800', '3', '0.25000', '1', '0']];
        self::assertSame(
            $rule,
            self::visits(self::LEGACY),
            'the first visit\'s source, cost and flag, the lead, on every row'
        );
        self::assertSame(
            [[(string) self::LEGACY, '10800'], [(string) self::BEFORE_UPGRADE, '3600']],
            self::recorded()
        );
        self::assertSame(
            [['10800', '0', '1', '3.00000']],
            self::reportRows(self::LEGACY),
            'one report row, at the latest visit'
        );
        self::assertCount(1, self::reportRows(self::BEFORE_UPGRADE));
        self::assertSame([['21600', '0', '0', '0.00000']], self::reportRows(self::ONE_VISIT));

        self::stored('1.9.78');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame(
            [['0']],
            self::all('SELECT finished_at IS NULL FROM 202_reclick_backfill'),
            'the walk stays done'
        );
        self::assertNull(self::cronWalk());
        self::assertSame($rule, self::visits(self::LEGACY));
        self::assertSame([['10800', '0', '1', '3.00000']], self::reportRows(self::LEGACY));
    }

    /**
     * A 202_reclick_backfill made before the walk kept the click it stopped
     * at and the clicks it stepped over (a database that ran #246 before
     * 1.9.78 took it out) gains the five columns from the rung's reconcile,
     * which logs nothing, and the walk reads them.
     */
    public function testTheRungAddsTheStoppedClickColumnsToAnEarlierMarkerTable(): void
    {
        self::seed();
        $earlier = ClickTables::reClickBackfill()->createStatement;
        foreach (self::STOPPED_CLICK_COLUMNS as $column) {
            $without = (string) preg_replace('/\n\s*`' . $column . '`[^\n]*,/', '', $earlier, -1, $removed);
            self::assertSame(1, $removed, $column . ' was in the definition, and is out of the earlier one');
            $earlier = $without;
        }
        self::q($earlier);
        self::assertSame([], self::backfillColumns(), 'the earlier table has none of the five');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.79', self::version());
        self::assertSame(
            [
                ['failed_click_id', 'YES', 'NULL'], ['failed_runs', 'NO', '0'], ['failing_since', 'YES', 'NULL'],
                ['last_skipped_click_id', 'YES', 'NULL'], ['skipped_clicks', 'NO', '0'],
            ],
            self::backfillColumns()
        );
        $walk = self::cronWalk();
        self::assertSame([1, true], [$walk['reclicked_clicks'] ?? null, $walk['finished'] ?? null]);
        $state = (new ReClickBackfill(new Connection(self::$db)))->state() ?? [];
        self::assertSame(
            [
                'failed_click_id' => null, 'failed_runs' => 0, 'failing_since' => null,
                'skipped_clicks' => 0, 'last_skipped_click_id' => null,
            ],
            array_intersect_key($state, array_flip(self::STOPPED_CLICK_COLUMNS))
        );
    }

    private const STOPPED_CLICK_COLUMNS = [
        'failed_click_id', 'failed_runs', 'failing_since', 'skipped_clicks', 'last_skipped_click_id',
    ];

    /** @return list<list<string>> name, nullable and default of the backfill's stopped-click columns */
    private static function backfillColumns(): array
    {
        return self::all(
            "SELECT COLUMN_NAME, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, 'NULL') FROM information_schema.COLUMNS"
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_reclick_backfill'"
            . " AND COLUMN_NAME IN ('" . implode("', '", self::STOPPED_CLICK_COLUMNS) . "') ORDER BY COLUMN_NAME"
        );
    }

    /** A marker the server refuses fails the rung: the version stays, for the next run to mark. */
    public function testARefusedMarkerKeepsTheVersion(): void
    {
        self::seed();
        // A run before this one created the table and stopped.
        self::q(ClickTables::reClickBackfill()->createStatement);
        self::q("CREATE TRIGGER p202_test_mark_refused BEFORE INSERT ON 202_reclick_backfill FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'");
        try {
            [$out, $err] = self::upgrade();
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_mark_refused');
        }

        self::assertSame('RETURNED false', $out, $err);
        self::assertStringContainsString('failed to mark the clicks re-clicked before 1.9.79', $err);
        self::assertSame('1.9.78', self::version());

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame(
            [['0', (string) self::ONE_VISIT]],
            self::all('SELECT next_click_id, through_click_id FROM 202_reclick_backfill')
        );
    }
}
