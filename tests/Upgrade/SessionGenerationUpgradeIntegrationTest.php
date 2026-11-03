<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\UserTables;
use Tests\Support\ColumnShape;

/**
 * The 1.9.78 -> 1.9.79 rung adds 202_users.user_session_generation
 * (Prosper202\User\SessionGeneration) as the installer makes it: an unsigned
 * int, NOT NULL, DEFAULT 0, so every user already there is at 0, the value a
 * session from before the upgrade (which holds none) is read as, and no one
 * is signed out by the upgrade. Afterwards the table is the fresh install's
 * (the comparison tests/live/upgrade-equals-install.sh makes), and a second
 * run changes nothing.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78, on a 202_users without
 * the column.
 *
 * @group integration
 */
final class SessionGenerationUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const EXISTING = 990951;

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static bool $backfillWasEmpty = false;

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
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::EXISTING);
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
        self::requireScratch();
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::EXISTING);
        $has = self::$db->query("SHOW COLUMNS FROM 202_users LIKE 'user_session_generation'");
        if ($has instanceof \mysqli_result && $has->num_rows === 0) {
            self::q("ALTER TABLE 202_users ADD COLUMN user_session_generation int(10) unsigned NOT NULL DEFAULT '0'");
        }
    }

    /** The table's DDL, less the AUTO_INCREMENT counter, which inserts move. */
    private static function shape(): string
    {
        $ddl = (string) self::$db->query('SHOW CREATE TABLE 202_users')->fetch_row()[1];

        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl);
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

    /** @param array{type: string, nullable: bool, default: ?string} $shape */
    private static function assertTheInstallersColumn(array $shape): void
    {
        // MySQL 8 writes no display width (int unsigned), MariaDB does.
        self::assertMatchesRegularExpression('/^int(\(10\))? unsigned$/', $shape['type']);
        self::assertSame([false, '0'], [$shape['nullable'], $shape['default']]);
    }

    public function testTheRungAddsTheGenerationAtZeroAsTheInstallerMakesIt(): void
    {
        $generation = static fn (): array => ColumnShape::of(self::$db, '202_users', 'user_session_generation');
        self::assertStringContainsString('`user_session_generation`', UserTables::users()->createStatement);
        self::assertTheInstallersColumn($generation());
        $fresh = self::shape();

        // A 1.9.78 install's table, and a user on it.
        self::q('ALTER TABLE 202_users DROP COLUMN user_session_generation');
        self::q('INSERT INTO 202_users SET user_id = ' . self::EXISTING . ", user_name = 'genupgrade',"
            . " user_email = 'genupgrade@example.com', user_dash_email = '', user_pass = 'x', user_timezone = 'UTC',"
            . " user_time_register = 0, install_hash = '', user_hash = ''");
        self::stored('1.9.78');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertTheInstallersColumn($generation());
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        $at = self::$db->query('SELECT user_session_generation FROM 202_users WHERE user_id = ' . self::EXISTING);
        self::assertSame('0', (string) $at->fetch_row()[0], 'a user already there is at 0, as old sessions read');

        // Run again (a retry of a step that failed elsewhere): nothing moves.
        self::q('UPDATE 202_users SET user_session_generation = 2 WHERE user_id = ' . self::EXISTING);
        self::stored('1.9.78');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        $at = self::$db->query('SELECT user_session_generation FROM 202_users WHERE user_id = ' . self::EXISTING);
        self::assertSame('2', (string) $at->fetch_row()[0], 'a generation counted up is kept');
    }
}
