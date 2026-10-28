<?php

declare(strict_types=1);

namespace Tests\Click;

use PHPUnit\Framework\TestCase;

/**
 * What p202WithHeldCacheWrites() relies on when work ends the request inside
 * the hold: a transaction left open by exit is still open while the shutdown
 * functions run (its own connection reads the uncommitted row, another does
 * not) and is rolled back when the process ends. An autocommitted row is the
 * control: it survives the same exit. Run in a child process on the scratch
 * database.
 *
 * @group integration
 */
final class HeldWritesOpenTransactionIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::q('CREATE TABLE IF NOT EXISTS p202_txn_probe (id INT PRIMARY KEY) ENGINE=InnoDB');
            self::q('DELETE FROM p202_txn_probe');
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TABLE IF EXISTS p202_txn_probe');
            self::$db->close();
            self::$db = null;
        }
    }

    private static function child(bool $inTransaction, int $id): string
    {
        $connect = 'new mysqli(' . implode(', ', array_map(static fn ($v): string => var_export($v, true), [
            (string) getenv('P202_TEST_DB_HOST'), (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''), (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306),
        ])) . ')';
        $code = 'mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT); $m = ' . $connect . ';'
            . ($inTransaction ? ' $m->begin_transaction();' : '')
            . " \$m->query('INSERT INTO p202_txn_probe VALUES ($id)');"
            . ' register_shutdown_function(static function () use ($m): void { $o = ' . $connect . ';'
            . " echo 'own=', \$m->query('SELECT COUNT(*) FROM p202_txn_probe WHERE id = $id')->fetch_row()[0],"
            . " ' other=', \$o->query('SELECT COUNT(*) FROM p202_txn_probe WHERE id = $id')->fetch_row()[0]; });"
            . ' exit(0);';

        return (string) shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');
    }

    private static function kept(int $id): int
    {
        $result = self::requireScratch()->query("SELECT COUNT(*) FROM p202_txn_probe WHERE id = $id");

        return (int) $result->fetch_row()[0];
    }

    public function testATransactionLeftOpenByExitIsOpenAtShutdownAndRolledBackAfter(): void
    {
        self::requireScratch();
        self::assertSame('own=1 other=0', self::child(true, 1), 'open through the shutdown functions');
        self::assertSame(0, self::kept(1), 'rolled back when the process ended');
    }

    public function testAnAutocommittedRowSurvivesTheSameExit(): void
    {
        self::requireScratch();
        self::assertSame('own=1 other=1', self::child(false, 2));
        self::assertSame(1, self::kept(2));
    }
}
