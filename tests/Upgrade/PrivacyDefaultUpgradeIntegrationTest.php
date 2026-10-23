<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\UserTables;
use Tests\Support\AccountCreationPaths;

/**
 * The 1.9.78 -> 1.9.79 rung gives 202_users_pref.user_pref_privacy the
 * installer's DEFAULT, 'eu', so an account made after the upgrade starts
 * there, through every path that makes one, as on a fresh install; and every
 * account already there keeps its own setting, the 'disabled' it took from
 * the column's old default among them. Afterwards the table is the fresh
 * install's (the comparison tests/live/upgrade-equals-install.sh makes).
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78, on a table with 1.9.78's
 * default.
 *
 * @group integration
 */
final class PrivacyDefaultUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    /** user id => the setting the account had before the upgrade; the first took the column's default. */
    private const EXISTING = [990961 => 'disabled', 990962 => 'all', 990963 => 'eu'];

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static bool $backfillWasEmpty = false;

    /** @var array<string, int> the accounts made after the upgrade */
    private array $made = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
        $marks = (int) self::$db->query('SELECT COUNT(*) FROM 202_reclick_backfill')->fetch_row()[0];
        self::$backfillWasEmpty = $marks === 0;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        // Back as the installer leaves them, for the classes after this one.
        self::q('DROP TABLE IF EXISTS 202_users_pref');
        self::q(UserTables::usersPref()->createStatement);
        if (self::$backfillWasEmpty) {
            self::q('DELETE FROM 202_reclick_backfill');
        }
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        // A test in a process of its own does not have the class's connection.
        if (self::$db === null) {
            self::connectScratch();
        }
        self::requireScratch();
        self::q('DROP TABLE IF EXISTS 202_users_pref');
        self::q(UserTables::usersPref()->createStatement);
        self::removeExisting();
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            AccountCreationPaths::remove(self::$db, $this->made);
            self::removeExisting();
        }
    }

    private static function removeExisting(): void
    {
        $ids = implode(', ', array_keys(self::EXISTING));
        self::q("DELETE FROM 202_users_pref WHERE user_id IN ($ids)");
        self::q("DELETE FROM 202_users WHERE user_id IN ($ids)");
    }

    private static function shape(): string
    {
        return (string) self::$db->query('SHOW CREATE TABLE 202_users_pref')->fetch_row()[1];
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

    private static function stored(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    public function testTheRungStartsNewAccountsAtEuAndLeavesEveryExistingOneAsItWas(): void
    {
        $fresh = self::shape();
        self::assertStringContainsString("`user_pref_privacy` varchar(100) NOT NULL DEFAULT 'eu',", $fresh);
        // A 1.9.78 install's table, and its accounts.
        self::q("ALTER TABLE 202_users_pref ALTER COLUMN user_pref_privacy SET DEFAULT 'disabled'");
        self::assertStringContainsString(
            "`user_pref_privacy` varchar(100) NOT NULL DEFAULT 'disabled',",
            self::shape()
        );
        foreach (self::EXISTING as $id => $setting) {
            self::user($id);
            self::q($setting === 'disabled'
                ? "INSERT INTO 202_users_pref SET user_id = $id"
                : "INSERT INTO 202_users_pref SET user_id = $id, user_pref_privacy = " . self::str($setting));
        }
        self::assertSame(self::EXISTING, AccountCreationPaths::privacyOf(self::$db, array_keys(self::EXISTING)));
        self::stored('1.9.78');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.79', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        self::assertSame(
            self::EXISTING,
            AccountCreationPaths::privacyOf(self::$db, array_keys(self::EXISTING)),
            'every account already there keeps its setting'
        );

        $this->made = AccountCreationPaths::makeOneEach(self::$db, 'upgraded');
        self::assertSame(
            array_fill_keys(array_values($this->made), 'eu'),
            AccountCreationPaths::privacyOf(self::$db, array_values($this->made)),
            'an account made after the upgrade starts at eu, whichever path makes it'
        );

        // Run again (a retry of a step that failed elsewhere): nothing moves.
        self::stored('1.9.78');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        self::assertSame(self::EXISTING, AccountCreationPaths::privacyOf(self::$db, array_keys(self::EXISTING)));
    }

    /**
     * A default the step cannot set fails the step and says which, and
     * changes nothing: one its definition declares no quoted literal for,
     * and one on a table the step does not converge.
     *
     * Loading functions-upgrade.php pulls in the real DataEngine, which
     * breaks later suites that stub it, so the test runs in its own process.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testADefaultTheStepCannotSetFailsTheStep(): void
    {
        require_once __DIR__ . '/../../202-config/functions-upgrade.php';
        $GLOBALS['db'] = self::$db;
        $log = tempnam(sys_get_temp_dir(), 'p202-privacy-default-upgrade-');
        self::assertIsString($log);
        ini_set('error_log', $log);
        $fresh = self::shape();

        $prefs = [UserTables::usersPref()];
        try {
            self::assertFalse(_upgrade_measurement_tables($prefs, [], ['202_users_pref' => ['user_pref_show']]));
            self::assertFalse(_upgrade_measurement_tables($prefs, [], ['202_users' => ['user_timezone']]));
            $logged = (string) file_get_contents($log);
        } finally {
            unlink($log);
        }

        self::assertStringContainsString(
            'Prosper202 upgrade: failed to set the default of 202_users_pref.user_pref_show (202_users_pref.'
            . 'user_pref_show declares no single quoted DEFAULT: `user_pref_show` varchar(25) DEFAULT NULL)' . "\n",
            $logged
        );
        self::assertStringContainsString(
            'Prosper202 upgrade: failed to set the default of 202_users.user_timezone'
            . ' (the step converges no such table)',
            $logged
        );
        self::assertSame($fresh, self::shape());
    }
}
