<?php

declare(strict_types=1);

namespace Tests\Install;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * The installer's key step runs before any table exists, and it rendered as
 * far as its licence-service pixel and stopped there with a 500: the pixel
 * names this install's address, p202TrackingBaseUrl() read the owner's
 * stored domain, and 202_users_pref was not there yet (measured on a fresh
 * install: "Unable to read the tracking domain of account 1: Table
 * '…202_users_pref' doesn't exist").
 *
 * Before an account exists there is no owner and nothing stored, so the
 * address is the server's own name. Once one exists, a read that fails still
 * throws (CLAUDE.md #11).
 *
 * Each case runs fixtures/key-step-runner.php against a database of its own,
 * created empty, which is the database a fresh install has.
 *
 * @group integration
 */
final class KeyStepOnFreshDatabaseIntegrationTest extends TestCase
{
    /** tests/live/guard.sh's P202_SCRATCH_RE: a name that reads as disposable. */
    private const SCRATCH_RE
        = '/(^|[_-])(test|tests|scratch|tmp|temp|ci|eval|evals|fixture|sandbox|w[0-9]+|probe|check)([_-]|$)/';

    private const KEY = 'fresh-install-key-1';

    private static ?\mysqli $server = null;

    private static string $name = '';

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        if (!is_file(dirname(__DIR__, 2) . '/202-config.php')) {
            self::markTestSkipped(
                'No 202-config.php: connect.php would exit before the page ran '
                . '(tests/run-integration-suites.sh writes one).'
            );
        }
        self::$name = (string) (getenv('P202_TEST_DB_NAME') ?: 'p202_integration_test') . '_fresh_install';
        self::assertMatchesRegularExpression(self::SCRATCH_RE, self::$name, 'the database dropped here is scratch');
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        self::$server = new \mysqli(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            '',
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            self::$server->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
            self::$server->close();
            self::$server = null;
        }
    }

    protected function setUp(): void
    {
        self::$server->query('DROP DATABASE IF EXISTS `' . self::$name . '`');
        self::$server->query('CREATE DATABASE `' . self::$name . '`');
    }

    public function testTheKeyStepRendersWholeOnAnEmptyDatabase(): void
    {
        $tables = self::$server->query(
            "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = '" . self::$name . "'"
        );
        self::assertSame(0, (int) $tables->fetch_row()[0], 'the database starts empty, as a fresh install\'s does');

        [$code, $out, $err] = self::runStep('');

        self::assertSame(0, $code, "the page ended the request with an error:\n$err\n" . substr($out, -400));
        self::assertStringNotContainsString('Uncaught', $out . $err);
        self::assertStringContainsString('value="' . self::KEY . '"', $out, 'the page shows the returned key');
        // The licence service is who the address is for: the server's own
        // name, never the Host header (RequestHostSourceTest).
        self::assertStringContainsString(
            'deeplink/cookie/set/' . base64_encode('http://install.example/') . '"',
            $out,
            'the pixel names the server\'s own name'
        );
        self::assertStringEndsWith('</html>', trim($out), 'the page rendered to its end');
    }

    public function testAnInstalledAppReadsTheOwnersDomainAndThrowsWhenItCannot(): void
    {
        $db = new \mysqli(
            (string) getenv('P202_TEST_DB_HOST'),
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            self::$name,
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        $db->query("SET SESSION sql_mode = ''");
        $installed = (new SchemaInstaller($db))->install();
        self::assertTrue($installed->success, 'schema: ' . implode('; ', $installed->errors));
        $db->query("INSERT INTO 202_users SET user_id = 1, user_name = 'owner'");
        $db->query("INSERT INTO 202_users_pref SET user_id = 1, user_tracking_domain = 'owner.example'");

        [$code, $out, $err] = self::runStep('function:p202TrackingBaseUrl');
        self::assertSame(0, $code, $err);
        self::assertSame('http://owner.example/', trim($out), 'an installed app names the owner\'s stored domain');

        $db->query('DROP TABLE 202_users_pref');
        $db->close();
        [$code, $out, $err] = self::runStep('function:p202TrackingBaseUrl');
        self::assertSame(0, $code, $err);
        self::assertStringStartsWith(
            'THREW: Unable to read the tracking domain of account 1',
            trim($out),
            'with an account, a read that fails throws rather than naming the server'
        );
    }

    /** @return array{int, string, string} exit code, stdout, stderr */
    private static function runStep(string $mode): array
    {
        $env = getenv();
        $env['P202_TEST_DB_NAME'] = self::$name;
        $env['P202_TEST_KEY'] = self::KEY;
        $command = [PHP_BINARY, __DIR__ . '/fixtures/key-step-runner.php'];
        if ($mode !== '') {
            $command[] = $mode;
        }
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
        self::assertIsResource($process);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $out, $err];
    }
}
