<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.78 -> 1.9.79 rung makes the pixels' same-IP fallback an account's
 * setting (202_users_pref.user_pref_same_ip_fallback): every account already
 * there keeps the fallback (1), an account made after the upgrade starts
 * without it (the column's DEFAULT, 0), and the upgraded table is the
 * installed one column for column.
 *
 * The column arrives with whichever step first reconciles the table — the
 * 1.9.76 step, for an install older than 1.9.77 — so the ladder is run from
 * both. It runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class SameIpFallbackUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const COLUMN = 'user_pref_same_ip_fallback';
    private const USERS = [5901, 5902];
    private const LATER = 5903;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<array<string, mixed>> the installer's columns */
    private static array $installed = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        self::$installed = self::columns();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::removeRows();
        self::restoreColumn();
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
        self::removeRows();
        if (in_array(self::COLUMN, array_column(self::columns(), 'COLUMN_NAME'), true)) {
            self::q('ALTER TABLE 202_users_pref DROP COLUMN ' . self::COLUMN);
        }
        foreach (self::USERS as $user) {
            self::row('202_users_pref', ['user_id' => $user]);
        }
        self::stored('1.9.78');
    }

    /** @return list<array<string, mixed>> */
    private static function columns(): array
    {
        return self::$db->query("SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_users_pref'
            ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    }

    private static function stored(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    /** @return array<int, int> user => the setting */
    private static function settings(): array
    {
        $ids = implode(',', [...self::USERS, self::LATER]);
        $rows = self::$db->query('SELECT user_id, ' . self::COLUMN . " AS v FROM 202_users_pref WHERE user_id IN ($ids) ORDER BY user_id")
            ->fetch_all(MYSQLI_ASSOC);

        return array_combine(array_map('intval', array_column($rows, 'user_id')), array_map('intval', array_column($rows, 'v')));
    }

    /** @return array{0: string, 1: string} what the runner printed, and what the ladder logged */
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

    private function assertUpgradedTo179(string $out, string $err): void
    {
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.79', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame(self::$installed, self::columns(), 'the upgraded 202_users_pref is the installed one');
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
    }

    public function testTheAccountsAlreadyThereKeepTheFallbackAndANewAccountStartsWithout(): void
    {
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo179($out, $err);
        self::assertSame([5901 => 1, 5902 => 1], self::settings(), 'every account already there keeps the fallback');

        self::row('202_users_pref', ['user_id' => self::LATER]);
        self::assertSame(0, self::settings()[self::LATER], 'an account made after the upgrade starts without it');

        // A second run changes nothing, an account's own choice included.
        self::q('UPDATE 202_users_pref SET ' . self::COLUMN . ' = 0 WHERE user_id = 5902');
        self::stored('1.9.78');
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo179($out, $err);
        self::assertSame([5901 => 1, 5902 => 0, self::LATER => 0], self::settings());
    }

    /** An install older than 1.9.77 meets the table first in the 1.9.76 step's reconcile. */
    public function testAnOlderInstallGetsTheColumnOnInTheEarlierStepThatReconcilesTheTable(): void
    {
        self::stored('1.9.76');
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo179($out, $err);
        self::assertSame([5901 => 1, 5902 => 1], self::settings());
    }

    /** A run that added the column and stopped before its DEFAULT was made off. */
    public function testARunThatStoppedHalfWayIsFinishedWithoutRewritingTheValues(): void
    {
        self::q('ALTER TABLE 202_users_pref ADD COLUMN `' . self::COLUMN . "` tinyint(1) NOT NULL DEFAULT '1'"
            . ' AFTER `user_pref_dynamic_bid`');
        self::q('UPDATE 202_users_pref SET ' . self::COLUMN . ' = 0 WHERE user_id = 5902');
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo179($out, $err);
        self::assertSame([5901 => 1, 5902 => 0], self::settings());
    }

    private static function removeRows(): void
    {
        self::q('DELETE FROM 202_users_pref WHERE user_id IN (' . implode(',', [...self::USERS, self::LATER]) . ')');
    }

    private static function restoreColumn(): void
    {
        $columns = array_column(self::columns(), 'COLUMN_DEFAULT', 'COLUMN_NAME');
        if (!array_key_exists(self::COLUMN, $columns)) {
            self::q('ALTER TABLE 202_users_pref ADD COLUMN `' . self::COLUMN . "` tinyint(1) NOT NULL DEFAULT '0'"
                . ' AFTER `user_pref_dynamic_bid`');
        } elseif ((string) $columns[self::COLUMN] !== '0') {
            self::q('ALTER TABLE 202_users_pref ALTER COLUMN `' . self::COLUMN . "` SET DEFAULT '0'");
        }
    }
}
