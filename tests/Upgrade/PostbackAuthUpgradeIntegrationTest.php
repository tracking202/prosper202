<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.79 -> 1.9.80 rung brings postback authentication
 * (202_postback_auth): the table as the installer makes it, and a row for
 * every account already there with mode off, so the postbacks its networks
 * call keep converting. An account made after the upgrade has no row, which
 * reads as mode token. A second run changes no one's choice.
 * It runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class PostbackAuthUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USERS = [5911, 5912];
    private const LATER = 5913;

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
        self::q(\Prosper202\Database\Tables\ConversionTables::postbackAuth()->createStatement);
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
        self::q('DROP TABLE IF EXISTS 202_postback_auth');
        foreach (self::USERS as $user) {
            self::user($user);
        }
        self::stored('1.9.79');
    }

    private static function user(int $id): void
    {
        self::row('202_users', [
            'user_id' => $id, 'user_name' => 'pbauth' . $id, 'user_pass' => 'x', 'user_email' => "pbauth$id@example.com",
            'user_dash_email' => '', 'user_time_register' => 0, 'install_hash' => '', 'user_hash' => '',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function columns(): array
    {
        return self::$db->query("SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, COLLATION_NAME
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '202_postback_auth'
            ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
    }

    private static function stored(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    /** @return array<int, string> account => auth_mode, for this class's accounts that have a row */
    private static function requirements(): array
    {
        $ids = implode(',', [...self::USERS, self::LATER]);
        $rows = self::$db->query("SELECT user_id, auth_mode FROM 202_postback_auth WHERE user_id IN ($ids) ORDER BY user_id")
            ->fetch_all(MYSQLI_ASSOC);

        return array_combine(array_map('intval', array_column($rows, 'user_id')), array_column($rows, 'auth_mode'));
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

    private function assertUpgradedTo180(string $out, string $err): void
    {
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame(self::$installed, self::columns(), 'the upgraded 202_postback_auth is the installed one');
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
    }

    public function testTheAccountsAlreadyThereKeepTakingPostbacksWithoutAToken(): void
    {
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo180($out, $err);
        self::assertSame([5911 => 'off', 5912 => 'off'], self::requirements(), 'every account already there: not required');

        self::user(self::LATER);
        $store = new \Prosper202\Conversion\PostbackAuth\PostbackAuthStore(new \Prosper202\Database\Connection(self::$db));
        self::assertSame(\Prosper202\Conversion\PostbackAuth\PostbackAuthMode::TOKEN, $store->settings(self::LATER)->mode,
            'an account made after the upgrade needs the token or a signature');
        self::assertSame(\Prosper202\Conversion\PostbackAuth\PostbackAuthMode::OFF, $store->settings(5911)->mode);
        self::assertNull($store->settings(5911)->token, 'no token until one is handed out');
    }

    public function testASecondRunKeepsEveryAccountsChoice(): void
    {
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo180($out, $err);
        self::q("UPDATE 202_postback_auth SET auth_mode = 'signed' WHERE user_id = 5912");
        self::user(self::LATER);

        self::stored('1.9.79');
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo180($out, $err);
        self::assertSame([5911 => 'off', 5912 => 'signed'], self::requirements(),
            'a re-run keeps a choice made, and an account made after the table is left to require it');
    }

    /** An install older than 1.9.76 meets the table first in the 1.9.75 step, which reconciles every conversion table. */
    public function testAnOlderInstallGetsTheRowsInTheEarlierStepThatMakesTheTable(): void
    {
        self::stored('1.9.75');
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo180($out, $err);
        self::assertSame([5911 => 'off', 5912 => 'off'], self::requirements());
    }

    /** A run that stopped half way left its building table, and no table: the next run builds it again. */
    public function testARunThatStoppedHalfWayIsStartedAgain(): void
    {
        self::q(str_replace('`202_postback_auth`', '`202_postback_auth_building`',
            \Prosper202\Database\Tables\ConversionTables::postbackAuth()->createStatement));
        self::q("INSERT INTO 202_postback_auth_building SET user_id = 5911, auth_mode = 'token', updated_at = 0");
        [$out, $err] = self::upgrade();
        $this->assertUpgradedTo180($out, $err);
        self::assertSame([5911 => 'off', 5912 => 'off'], self::requirements());
        self::assertSame(0, self::$db->query("SHOW TABLES LIKE '202\\_postback\\_auth\\_building'")->num_rows);
    }

    private static function removeRows(): void
    {
        $ids = implode(',', [...self::USERS, self::LATER]);
        self::q("DELETE FROM 202_users WHERE user_id IN ($ids)");
        if (self::$db->query("SHOW TABLES LIKE '202\\_postback\\_auth'")->num_rows > 0) {
            self::q("DELETE FROM 202_postback_auth WHERE user_id IN ($ids)");
        }
    }
}
