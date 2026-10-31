<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * The cron job's rebuild of 202_dataengine from 202_dataengine_job windows
 * (DataEngine::processClickUpgrade()) where curl is not loaded: every
 * account's clicks in each window, one window per run, each marked done.
 *
 * With curl the job goes through 202-cronjobs/process_dataengine_job.php and
 * dej.php, which roll up every account's clicks and mark the window
 * themselves. Without it, processClickUpgrade() rolled up user 1's clicks
 * only ("AND 2c.user_id = 1"), and getSummary() marked the window
 * `processing` and left the "processed" mark to doSummary(), which an
 * INSERT … SELECT never reaches: the first window stayed taken and
 * unfinished, so no other window was ever rolled up.
 *
 * The run is the cron job's, in a process that boots the app with curl's
 * ini file left out of the scan directory (fixtures/account-scope-runner.php).
 * Needs a 202-config.php and P202_TEST_DB_*. It empties 202_dataengine_job
 * and puts its rows back.
 *
 * @group integration
 */
final class ClickUpgradeIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990101;
    private const OTHER = 990102;
    private const CLICKS = [99010101, 99010102, 99010103, 99010104];

    private static int $hour = 0;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<array<string, string>>|null 202_dataengine_job as this class found it */
    private static ?array $jobs = null;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        if (!is_file($root . '/202-config.php')) {
            self::markTestSkipped(
                'No 202-config.php: connect.php would exit before the cron job ran.'
                . ' tests/run-integration-suites.sh writes one.'
            );
        }
        if (!self::connectScratch()) {
            return;
        }
        require_once $root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        $jobs = self::$db->query('SELECT * FROM 202_dataengine_job');
        self::$jobs = $jobs instanceof \mysqli_result ? $jobs->fetch_all(MYSQLI_ASSOC) : [];
        // Two whole days back, on the hour: two windows of a day each.
        self::$hour = (intdiv(time(), 3600) - 72) * 3600;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::q('DELETE FROM 202_dataengine_job');
        foreach (self::$jobs ?? [] as $job) {
            self::row('202_dataengine_job', $job);
        }
        if (self::$versions !== null) {
            self::q('DELETE FROM 202_version');
            foreach (self::$versions as $version) {
                self::row('202_version', ['version' => $version]);
            }
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::q('DELETE FROM 202_dataengine_job');
    }

    private static function cleanUp(): void
    {
        $clicks = implode(', ', self::CLICKS);
        foreach (['202_clicks', '202_clicks_advance', '202_clicks_record', '202_dataengine'] as $table) {
            self::q("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
    }

    /** A click of $user at $time, with no rollup row. */
    private static function click(int $id, int $user, int $time): void
    {
        self::row('202_clicks', [
            'click_id' => $id, 'user_id' => $user, 'aff_campaign_id' => 0, 'ppc_account_id' => 0,
            'landing_page_id' => 0, 'click_cpc' => 0, 'click_payout' => 0, 'click_time' => $time,
        ]);
        self::row('202_clicks_advance', [
            'click_id' => $id, 'text_ad_id' => 0, 'ip_id' => 0, 'country_id' => 0, 'region_id' => 0,
            'city_id' => 0, 'platform_id' => 0, 'browser_id' => 0, 'device_id' => 0,
        ]);
        self::row('202_clicks_record', ['click_id' => $id, 'click_id_public' => $id]);
    }

    /** The cron job's processClickUpgrade(), without curl, in a process that boots the app. */
    private static function runWithoutCurl(): void
    {
        $scan = sys_get_temp_dir() . '/p202-nocurl-' . getmypid();
        if (!is_dir($scan)) {
            mkdir($scan);
        }
        foreach (array_filter(array_map('trim', explode(',', (string) php_ini_scanned_files()))) as $ini) {
            if (!str_contains(basename($ini), 'curl')) {
                copy($ini, $scan . '/' . basename($ini));
            }
        }
        $env = [
            'PHP_INI_SCAN_DIR' => $scan,
            'P202_TEST_REPORT_USER' => (string) self::USER,
            'P202_TEST_FROM' => (string) self::$hour,
            'P202_TEST_TO' => (string) (self::$hour + 3600),
        ] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/account-scope-runner.php',
                'click-upgrade'],
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
        array_map('unlink', glob($scan . '/*') ?: []);
        rmdir($scan);
        self::assertNotSame(2, proc_close($proc), "the runner could not set up: $err");
        self::assertSame('RETURNED {"curl":false}', trim($out), 'the job ran, without curl: ' . $out . $err);
    }

    /** @return array<int, string> click id => its rollup row's user */
    private static function rolledUp(): array
    {
        $result = self::$db->query('SELECT click_id, user_id FROM 202_dataengine WHERE click_id IN ('
            . implode(', ', self::CLICKS) . ') ORDER BY click_id');
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'user_id', 'click_id');
    }

    /** @return list<string> each window as "processing|processed" */
    private static function windows(): array
    {
        $result = self::$db->query(
            'SELECT CONCAT(processing, "|", processed) AS state FROM 202_dataengine_job ORDER BY time_from'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(MYSQLI_ASSOC), 'state');
    }

    public function testEachRunRollsUpEveryAccountsClicksInTheNextWindowAndMarksItDone(): void
    {
        $day = self::$hour;
        $next = $day + 86400;
        self::click(self::CLICKS[0], self::USER, $day + 600);
        self::click(self::CLICKS[1], self::OTHER, $day + 7200);
        self::click(self::CLICKS[2], self::USER, $next + 600);
        self::click(self::CLICKS[3], self::OTHER, $next + 7200);
        self::row('202_dataengine_job', ['time_from' => $day, 'time_to' => $next - 1]);
        self::row('202_dataengine_job', ['time_from' => $next, 'time_to' => $next + 86399]);

        self::runWithoutCurl();
        self::assertSame(
            [self::CLICKS[0] => (string) self::USER, self::CLICKS[1] => (string) self::OTHER],
            self::rolledUp(),
            'the first window\'s clicks, both accounts\''
        );
        self::assertSame(['0|1', '0|0'], self::windows(), 'the first window is done and the second not yet taken');

        self::runWithoutCurl();
        self::assertSame([
            self::CLICKS[0] => (string) self::USER, self::CLICKS[1] => (string) self::OTHER,
            self::CLICKS[2] => (string) self::USER, self::CLICKS[3] => (string) self::OTHER,
        ], self::rolledUp(), 'the next run takes the second window');
        self::assertSame(['0|1', '0|1'], self::windows());
    }
}
