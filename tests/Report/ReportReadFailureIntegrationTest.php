<?php

declare(strict_types=1);

namespace Tests\Report;

use PHPUnit\Framework\TestCase;

/**
 * A report reader whose query fails says so, rather than answering with
 * what an empty account would get (CLAUDE.md #1): the Visitors list's count
 * read as 0 clicks and no pages, its read of the filters as no filters, the
 * report window's as 0 to 0, and the engine's count, per-source, variable
 * and chart queries as no rows.
 *
 * Each case boots the app in a child process against the scratch database,
 * holds a WRITE lock on one table from a second connection so the app's
 * query times out (fixtures/report-read-failure-runner.php), and calls the
 * reader. The pages' readers (functions-tracking202.php) fail as that file
 * fails its other reads, through record_mysql_error(): its error page, and
 * the failed statement in 202_mysql_errors. The engine's throw, as its
 * collectRows() does; the Analyze pages show their "could not be read"
 * notice for it. Each locked case has an unlocked twin, which shows the
 * reader returning and so that the lock is what the case measured.
 *
 * Needs a 202-config.php (connect.php exits without one) and P202_TEST_DB_*.
 *
 * @group integration
 */
final class ReportReadFailureIntegrationTest extends TestCase
{
    use ScratchReportDatabase;

    private const USER = 990031;

    /** A landing page id no other class's rows use. */
    private const LANDING_PAGE = 990031;

    private static string $root = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        self::$root = dirname(__DIR__, 2);
        if (!is_file(self::$root . '/202-config.php')) {
            self::markTestSkipped('No 202-config.php: connect.php would exit before any reader ran. tests/run-integration-suites.sh writes one.');
        }
        if (!self::connectScratch()) {
            return;
        }
        // An installed instance's version: connect.php refuses a command-line
        // run against a schema older than the code, and the installer's
        // tables carry none.
        require_once self::$root . '/202-config/version.php';
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => PROSPER202_VERSION]);
        self::cleanUp();
        self::user(self::USER);
        self::row('202_users_pref', ['user_id' => self::USER, 'user_pref_time_predefined' => 'today']);
        // One click the per-source report finds, so its second query (the
        // traffic-source accounts) runs: on the account's own advanced
        // landing page, which the report lists clicks under (a page that is
        // not the account's, or not there, has no heading to list one under).
        self::row('202_landing_pages', [
            'landing_page_id' => self::LANDING_PAGE,
            'user_id' => self::USER,
            'aff_campaign_id' => 0,
            'landing_page_type' => 1,
            'landing_page_nickname' => 'Read failure page',
            'landing_page_url' => 'https://page.example/',
            'landing_page_time' => time(),
        ]);
        self::row('202_dataengine', [
            'user_id' => self::USER,
            'click_id' => 99003101,
            'click_time' => time(),
            'ppc_account_id' => 0,
            'landing_page_id' => self::LANDING_PAGE,
            'aff_campaign_id' => 0,
            'payout' => 0,
            'clicks' => 1,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            if (self::$versions !== null) {
                self::q('DELETE FROM 202_version');
                foreach (self::$versions as $version) {
                    self::row('202_version', ['version' => $version]);
                }
            }
        }
    }

    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_dataengine WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_landing_pages WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users_pref WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_mysql_errors WHERE user_id = ' . self::USER);
    }

    /** @return array{out: string, err: string, code: int} */
    private static function runReader(string $reader, string $lock): array
    {
        self::requireScratch();
        $env = ['P202_TEST_REPORT_USER' => (string) self::USER] + getenv();
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', __DIR__ . '/fixtures/report-read-failure-runner.php', $reader, $lock],
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
        $code = proc_close($proc);
        self::assertNotSame(2, $code, "the runner could not set up: $err");

        return ['out' => $out, 'err' => $err, 'code' => $code];
    }

    /** @return array<string, array{string, string, string}> */
    public static function pageReaders(): array
    {
        return [
            'the Visitors count' => ['visitors', '202_dataengine', 'SELECT count(*) AS count FROM 202_dataengine'],
            'the Visitors filters' => ['visitors', '202_users_pref', 'SELECT * FROM 202_users_pref'],
            'the report window' => ['window', '202_users_pref', 'SELECT user_pref_time_predefined'],
        ];
    }

    /** @dataProvider pageReaders */
    public function testAPageReaderWhoseQueryFailsShowsTheErrorPage(string $reader, string $lock, string $statement): void
    {
        self::q('DELETE FROM 202_mysql_errors WHERE user_id = ' . self::USER);

        $run = self::runReader($reader, $lock);

        self::assertStringNotContainsString('RETURNED', $run['out'], "the reader answered as if the read had worked:\n" . $run['out']);
        self::assertStringContainsString('A database error has occurred', $run['out'], 'record_mysql_error() did not run: ' . $run['out'] . $run['err']);
        $logged = self::$db->query('SELECT mysql_error_text, mysql_error_sql FROM 202_mysql_errors WHERE user_id = ' . self::USER);
        self::assertInstanceOf(\mysqli_result::class, $logged);
        $rows = $logged->fetch_all(MYSQLI_ASSOC);
        self::assertCount(1, $rows, 'one failed statement recorded');
        self::assertStringContainsString('Lock wait timeout', $rows[0]['mysql_error_text']);
        self::assertStringContainsString($statement, $rows[0]['mysql_error_sql'], 'the statement recorded is the reader that failed');
    }

    /** @dataProvider pageReaders */
    public function testThePageReaderReturnsWhenItsTableCanBeRead(string $reader): void
    {
        $run = self::runReader($reader, 'none');

        self::assertStringStartsWith('RETURNED ', $run['out'], $run['out'] . $run['err']);
        $result = json_decode(substr(trim($run['out']), 9), true);
        self::assertIsArray($result);
        if ($reader === 'visitors') {
            self::assertSame(1, $result['rows'], 'the one click is counted');
        } else {
            self::assertSame('today', $result['user_pref_time_predefined']);
            self::assertGreaterThan(0, $result['from']);
        }
    }

    /** @return array<string, array{string, string, string}> */
    public static function engineReaders(): array
    {
        return [
            'the pagination count' => ['engine-count', '202_dataengine', 'DataEngine count query failed'],
            'the variable report' => ['engine-variable', '202_dataengine', 'DataEngine report query failed'],
            'the variable report\'s values' => ['engine-variable', '202_variable_sets2', 'DataEngine report query failed'],
            'the per-source report' => ['engine-per-ppc', '202_dataengine', 'DataEngine report query failed'],
            'the per-source report\'s accounts' => ['engine-per-ppc', '202_ppc_accounts', 'DataEngine report query failed'],
            'the chart' => ['engine-chart', '202_dataengine', 'DataEngine chart query failed'],
        ];
    }

    /** @dataProvider engineReaders */
    public function testAnEngineReaderWhoseQueryFailsThrows(string $reader, string $lock, string $message): void
    {
        $run = self::runReader($reader, $lock);

        self::assertSame("THREW RuntimeException: $message", trim($run['out']), $run['err']);
    }

    /** @dataProvider engineReaders */
    public function testTheEngineReaderReturnsWhenItsTablesCanBeRead(string $reader): void
    {
        $run = self::runReader($reader, 'none');

        self::assertStringStartsWith('RETURNED ', $run['out'], $run['out'] . $run['err']);
        $result = json_decode(substr(trim($run['out']), 9), true);
        if ($reader === 'engine-count') {
            self::assertGreaterThanOrEqual(1, $result);
        } elseif ($reader === 'engine-per-ppc') {
            // The click was found, so the accounts query ran for it.
            self::assertArrayHasKey((string) self::LANDING_PAGE, $result);
            self::assertArrayHasKey('ppc_accounts', $result[(string) self::LANDING_PAGE]);
        } else {
            self::assertIsArray($result);
        }
    }
}
