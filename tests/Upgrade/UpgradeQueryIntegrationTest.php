<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Integration coverage for the upgrade query runner against a real MySQL: proves
 * that a genuine lock-wait timeout (errno 1205) is retried and succeeds once the
 * lock clears, and that real success / permanent-error paths behave as documented.
 *
 * Tagged @group integration so it is excluded from the default/CI run
 * (phpunit.xml excludes that group). It self-skips unless a DB is reachable. Run:
 *   P202_TEST_DB_NAME=test vendor/bin/phpunit --group integration tests/Upgrade/UpgradeQueryIntegrationTest.php
 *
 * @group integration
 */
final class UpgradeQueryIntegrationTest extends TestCase
{
    private static ?\mysqli $main = null;
    private static ?\mysqli $lock = null;
    private static string $table = '';

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';

        $name = getenv('P202_TEST_DB_NAME') ?: '';
        if ($name === '') {
            self::markTestSkipped('Set P202_TEST_DB_NAME (and _HOST/_USER/_PASS) to run the upgrade integration test.');
        }

        $host = getenv('P202_TEST_DB_HOST') ?: '127.0.0.1';
        $user = getenv('P202_TEST_DB_USER') ?: 'root';
        $pass = getenv('P202_TEST_DB_PASS') ?: '';

        // Don't let the driver throw on the connection probe; we want a clean skip.
        mysqli_report(MYSQLI_REPORT_OFF);

        self::$main = @new \mysqli($host, $user, $pass, $name);
        self::$lock = @new \mysqli($host, $user, $pass, $name);

        // Restore the codebase default (connect.php uses MYSQLI_REPORT_STRICT) right
        // after the probe so the OFF state can't leak into the rest of the process —
        // including down the markTestSkipped() path below, which bypasses tearDown.
        mysqli_report(MYSQLI_REPORT_STRICT);

        if (self::$main->connect_errno || self::$lock->connect_errno) {
            self::markTestSkipped('Could not connect to the test database.');
        }

        self::$table = 'p202_upgrade_it_' . substr(md5((string) mt_rand()), 0, 8);
        self::$main->query(
            'CREATE TABLE `' . self::$table . '` (id INT PRIMARY KEY, val INT NOT NULL) ENGINE=InnoDB'
        );
        self::$main->query('INSERT INTO `' . self::$table . '` (id, val) VALUES (1, 0)');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$main instanceof \mysqli && !self::$main->connect_errno) {
            self::$main->query('DROP TABLE IF EXISTS `' . self::$table . '`');
            self::$main->close();
        }
        if (self::$lock instanceof \mysqli && !self::$lock->connect_errno) {
            self::$lock->close();
        }

        // Defensive: also restore the codebase default (MYSQLI_REPORT_STRICT, as
        // set in connect.php) here. setUpBeforeClass already restores it after the
        // connection probe, so this only matters if a later change moves that.
        mysqli_report(MYSQLI_REPORT_STRICT);
    }

    private function noopSleeper(): callable
    {
        return static function (int $seconds): void {
            // No real backoff sleep in tests.
        };
    }

    private function aborter(): callable
    {
        return static function (int $errno, string $error): void {
            throw new RuntimeException('aborted:' . $errno);
        };
    }

    public function testRealSuccessReturnsResult(): void
    {
        $result = _upgrade_query_run(
            self::$main,
            'SELECT val FROM `' . self::$table . '` WHERE id = 1',
            $this->noopSleeper(),
            $this->aborter()
        );

        $this->assertInstanceOf(\mysqli_result::class, $result);
        $this->assertSame(0, (int) $result->fetch_row()[0]);
    }

    public function testRealPermanentErrorReturnsFalse(): void
    {
        $result = _upgrade_query_run(
            self::$main,
            'THIS IS NOT VALID SQL',
            $this->noopSleeper(),
            $this->aborter()
        );

        $this->assertFalse($result);
    }

    public function testRealLockWaitIsRetriedThenSucceeds(): void
    {
        // Hold a row lock on a second connection.
        self::$lock->begin_transaction();
        $held = self::$lock->query('SELECT val FROM `' . self::$table . '` WHERE id = 1 FOR UPDATE');
        $this->assertInstanceOf(\mysqli_result::class, $held);

        // Make the main connection give up waiting for the lock almost immediately,
        // so the first UPDATE fails with errno 1205.
        self::$main->query('SET SESSION innodb_lock_wait_timeout = 1');

        // If the server ignored/clamped that (some managed builds enforce a floor),
        // the UPDATE below would block for the server default instead of failing
        // fast — and since the lock is only released inside the sleeper (which runs
        // only after a 1205), the test would hang. Bail cleanly instead.
        $timeoutRow = self::$main->query('SELECT @@innodb_lock_wait_timeout AS t')->fetch_assoc();
        if ((int) $timeoutRow['t'] > 2) {
            self::$lock->commit(); // release the held lock before skipping
            $this->markTestSkipped('Server did not honor a low innodb_lock_wait_timeout; skipping to avoid a long block.');
        }

        // The injected sleeper releases the lock the first time the runner backs off,
        // so the retried UPDATE succeeds — exercising the real transient-retry path.
        $released = false;
        $sleeper = function (int $seconds) use (&$released): void {
            if (!$released) {
                self::$lock->commit();
                $released = true;
            }
        };

        $result = _upgrade_query_run(
            self::$main,
            'UPDATE `' . self::$table . '` SET val = val + 1 WHERE id = 1',
            $sleeper,
            $this->aborter()
        );

        $this->assertTrue($result, 'the retried UPDATE should succeed once the lock clears');
        $this->assertTrue($released, 'the runner should have backed off (and released the lock) once');

        $check = self::$main->query('SELECT val FROM `' . self::$table . '` WHERE id = 1');
        $this->assertSame(1, (int) $check->fetch_row()[0]);
    }
}
