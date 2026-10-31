<?php

declare(strict_types=1);

namespace Tests\Identity;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaInstaller;

/**
 * Two clicks introduce the same brand-new signal at once: both probe
 * 202_identity_signals, find nothing, and insert. Under REPEATABLE READ the
 * probes' gap locks make the inserts deadlock, and the loser is retried.
 * Under READ COMMITTED there is no gap lock: the second insert waited for the
 * first to commit and failed on the duplicate key, which the link did not
 * retry, so that click was logged as "stored but not linked" and stayed a
 * one-touch journey. Both clicks must end on one visitor, at either level.
 *
 * The window is widened with a BEFORE INSERT trigger that sleeps, created on
 * the scratch database for the length of the test: each process's insert
 * waits a second after its probe, so the second process probes while the
 * first is still inside that window. Each side is a child process running
 * the real ClickIdentity::attach() (fixtures/attach-customer.php).
 *
 * @group integration
 */
final class IdentityRaceIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->query('DROP TRIGGER IF EXISTS p202_test_widen_signal_race');
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_identity_signals', '202_identity_visitors', '202_identity_observations', '202_identity_merges', '202_clicks_visitor'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
    }

    /** @return array<string, array{string}> */
    public static function isolationLevels(): array
    {
        return ['read committed' => ['RC'], 'repeatable read' => ['RR']];
    }

    /** @dataProvider isolationLevels */
    public function testTwoClicksRacingOnANewSignalEndOnOneVisitor(string $isolation): void
    {
        $db = self::$db;
        self::assertNotNull($db);
        $db->query('DROP TRIGGER IF EXISTS p202_test_widen_signal_race');
        $db->query('CREATE TRIGGER p202_test_widen_signal_race BEFORE INSERT ON 202_identity_signals FOR EACH ROW SET @p202_race = SLEEP(1)');

        $log = tempnam(sys_get_temp_dir(), 'p202-race-');
        self::assertIsString($log);
        try {
            $customer = 'race-' . $isolation . '-' . bin2hex(random_bytes(4));
            $first = $this->spawn(8001, $customer, $isolation, $log);
            usleep(300000);
            $second = $this->spawn(8002, $customer, $isolation, $log);
            $a = $this->finish($first);
            $b = $this->finish($second);
            $logged = (string) file_get_contents($log);
        } finally {
            $db->query('DROP TRIGGER IF EXISTS p202_test_widen_signal_race');
            @unlink($log);
        }

        self::assertStringNotContainsString('was stored but not linked', $logged, "a click lost its link:\n$logged");
        self::assertNotNull($a['key'], "click 8001 was not linked:\n$logged");
        self::assertNotNull($b['key'], "click 8002 was not linked:\n$logged");
        $keys = $db->query('SELECT click_id, visitor_key FROM 202_clicks_visitor ORDER BY click_id')->fetch_all(MYSQLI_ASSOC);
        self::assertCount(2, $keys, 'both clicks are in the graph');
        $canonical = static function (int $key) use ($db): int {
            $row = $db->query('SELECT alias_of FROM 202_identity_visitors WHERE visitor_key = ' . $key)->fetch_assoc();

            return $row !== null && $row['alias_of'] !== null ? (int) $row['alias_of'] : $key;
        };
        self::assertSame(
            $canonical((int) $keys[0]['visitor_key']),
            $canonical((int) $keys[1]['visitor_key']),
            'one customer id, one visitor'
        );
        self::assertSame('1', (string) $db->query('SELECT COUNT(*) AS n FROM 202_identity_signals')->fetch_assoc()['n']);
    }

    /** @return array{0: resource, 1: array<int, resource>} */
    private function spawn(int $clickId, string $customer, string $isolation, string $log): array
    {
        $env = getenv();
        $env['P202_TEST_ERROR_LOG'] = $log;
        $proc = proc_open(
            [PHP_BINARY, __DIR__ . '/fixtures/attach-customer.php', (string) $clickId, $customer, $isolation],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            $env
        );
        self::assertIsResource($proc);

        return [$proc, $pipes];
    }

    /**
     * @param array{0: resource, 1: array<int, resource>} $spawned
     * @return array{click: int, key: int|null}
     */
    private function finish(array $spawned): array
    {
        [$proc, $pipes] = $spawned;
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);
        self::assertSame(0, $exit, "the child failed:\n$out\n$err");
        $decoded = json_decode(trim($out), true, 4, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return ['click' => (int) $decoded['click'], 'key' => $decoded['key'] === null ? null : (int) $decoded['key']];
    }
}
