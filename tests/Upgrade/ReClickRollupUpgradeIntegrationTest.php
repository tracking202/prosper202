<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.77 -> 1.9.78 rung queues the report rows a rollup before 1.9.78
 * wrote per visit (ClickRollupSql::QUEUE_EARLIER_VISIT_ROWS), and the cron's
 * dirty-hours pass then leaves a re-clicked click one row, at its latest
 * visit, with its first visit's flags.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.77; the pass as the cron runs
 * it (DataEngine::processDirtyHours(), tests/DataEngine/fixtures/rollup-runner.php).
 *
 * @group integration
 */
final class ReClickRollupUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990951;
    private const OTHER = 990952;
    private const RECLICKED = 99095101;
    private const ONE_VISIT = 99095102;
    private const ROLLED_SINCE = 99095103;
    private const CLICKS = [self::RECLICKED, self::ONE_VISIT, self::ROLLED_SINCE];

    private static int $hour = 0;

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
        self::$hour = (intdiv(time(), 3600) - 96) * 3600;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::q('DROP TRIGGER IF EXISTS p202_test_queue_refused');
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
        self::cleanUp();
        self::q('DROP TRIGGER IF EXISTS p202_test_queue_refused');
        self::stored('1.9.77');
    }

    private static function cleanUp(): void
    {
        $clicks = implode(', ', self::CLICKS);
        $tables = ['202_clicks', '202_clicks_advance', '202_clicks_record', '202_clicks_rotator', '202_dataengine'];
        foreach ($tables as $t) {
            self::q("DELETE FROM $t WHERE click_id IN ($clicks)");
        }
        self::q('DELETE FROM 202_dirty_hours WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ')');
    }

    private static function stored(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    /** One visit's 202_clicks row, and the click's own rows once. */
    private static function visit(int $click, int $user, int $time, int $rotator, int $filtered): void
    {
        self::row('202_clicks', [
            'click_id' => $click, 'user_id' => $user, 'aff_campaign_id' => 0, 'landing_page_id' => 0,
            'ppc_account_id' => 0, 'click_cpc' => '0.10', 'click_payout' => '1.00', 'click_time' => $time,
            'click_filtered' => $filtered, 'rotator_id' => $rotator,
        ]);
        self::q("INSERT IGNORE INTO 202_clicks_advance SET click_id = $click, text_ad_id = 0, keyword_id = 0,"
            . ' ip_id = 0, country_id = 0, region_id = 0, city_id = 0, platform_id = 0, browser_id = 0, device_id = 0');
        self::q("INSERT IGNORE INTO 202_clicks_record SET click_id = $click, click_id_public = $click");
    }

    /** A report row as a rollup before 1.9.78 wrote it: the visit's own values and flag. */
    private static function rolledUp(int $click, int $time): void
    {
        self::q('INSERT INTO 202_dataengine (user_id, click_id, click_time, ppc_account_id, aff_campaign_id,'
            . ' landing_page_id, click_filtered, clicks, leads, payout, income, cost)'
            . ' SELECT c.user_id, c.click_id, c.click_time, c.ppc_account_id, c.aff_campaign_id, 0,'
            . " c.click_filtered, 1, 0, c.click_payout, 0, c.click_cpc FROM 202_clicks AS c WHERE c.click_id = $click"
            . " AND c.click_time = $time");
    }

    /**
     * A click visited at the hour's start and re-clicked through the
     * redirector three hours later, which the duplicate filter flagged, each
     * visit with its row; a click with one visit; and another account's
     * re-clicked click already rolled up at its latest visit alone.
     */
    private static function seed(): void
    {
        $first = self::$hour + 600;
        $latest = self::$hour + 3 * 3600 + 600;
        self::visit(self::RECLICKED, self::USER, $first, 0, 0);
        self::visit(self::RECLICKED, self::USER, $latest, 77, 1);
        self::rolledUp(self::RECLICKED, $first);
        self::rolledUp(self::RECLICKED, $latest);
        self::visit(self::ONE_VISIT, self::USER, self::$hour + 6 * 3600, 0, 0);
        self::rolledUp(self::ONE_VISIT, self::$hour + 6 * 3600);
        self::visit(self::ROLLED_SINCE, self::OTHER, $first, 0, 0);
        self::visit(self::ROLLED_SINCE, self::OTHER, $latest, 77, 0);
        self::rolledUp(self::ROLLED_SINCE, $latest);
    }

    /**
     * @param list<string> $args
     * @return array{string, string} what the runner printed, and what was logged
     */
    private static function runFixture(string $runner, array $args = []): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', $runner, ...$args],
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

    /** @return array{string, string} */
    private static function upgrade(): array
    {
        return self::runFixture(__DIR__ . '/fixtures/ladder-runner.php');
    }

    private static function cronPass(): void
    {
        $runner = dirname(__DIR__) . '/DataEngine/fixtures/rollup-runner.php';
        [$out, $err] = self::runFixture($runner, ['full', 'dirty-hours']);
        self::assertSame('RETURNED true', $out, $err);
    }

    /** @return list<string> the account's queued hours, as "from-to processed" relative to the first visit's hour */
    private static function queued(int $user): array
    {
        $result = self::$db->query('SELECT CONCAT(click_time_from - ' . self::$hour . ", '-', click_time_to - "
            . self::$hour . ", ' ', processed + 0) FROM 202_dirty_hours WHERE user_id = $user"
            . ' AND ppc_account_id = 0 AND aff_campaign_id = 0 ORDER BY click_time_from');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(), 0);
    }

    /** @return list<string> the click's report rows, as "time filtered", relative to the first visit's hour */
    private static function rows(int $click): array
    {
        $result = self::$db->query('SELECT CONCAT(click_time - ' . self::$hour . ", ' ', click_filtered)"
            . " FROM 202_dataengine WHERE click_id = $click ORDER BY click_time");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(), 0);
    }

    private static function version(): string
    {
        return (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0];
    }

    /**
     * The rung queues the hours of the re-clicked click's earlier row and of
     * its latest visit, and logs nothing; the cron's pass leaves one row, at
     * the latest visit, unfiltered as the first visit was; the rung run
     * again queues nothing.
     */
    public function testTheRungQueuesTheRowsAPerVisitRollupLeftForTheCron(): void
    {
        self::seed();

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.78', self::version());
        $hours = ['0-3599 0', '10800-14399 0'];
        self::assertSame($hours, self::queued(self::USER), 'the earlier row\'s hour and the latest visit\'s');
        self::assertSame([], self::queued(self::OTHER), 'a click rolled up at its latest visit alone');

        self::cronPass();
        self::assertSame(['11400 0'], self::rows(self::RECLICKED), 'one row, at the latest visit, first visit\'s flag');
        self::assertSame(['21600 0'], self::rows(self::ONE_VISIT));
        self::assertSame([], self::queued(self::USER), 'the pass took the queue');

        self::stored('1.9.77');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame([], self::queued(self::USER), 'once the pass has run, the rung queues nothing');
    }

    /** A queue the server refuses fails the rung: the version stays, for the next run to queue again. */
    public function testARefusedQueueKeepsTheVersion(): void
    {
        self::seed();
        self::q("CREATE TRIGGER p202_test_queue_refused BEFORE INSERT ON 202_dirty_hours FOR EACH ROW"
            . " SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'refused by the test'");
        try {
            [$out, $err] = self::upgrade();
        } finally {
            self::q('DROP TRIGGER IF EXISTS p202_test_queue_refused');
        }

        self::assertSame('RETURNED false', $out, $err);
        self::assertStringContainsString('failed to queue report rows for the cron to roll up again', $err);
        self::assertSame('1.9.77', self::version());

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame(['0-3599 0', '10800-14399 0'], self::queued(self::USER));
    }
}
