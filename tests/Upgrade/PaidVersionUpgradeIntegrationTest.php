<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The paid (ClickServer) line stores `1.9.56` too, but its 1.9.55 -> 1.9.56
 * step only added 202_clicks_purlink: it never created user_data_feedback or
 * widened 202_users.user_pass from char(32). The ladder reads that 1.9.56 as
 * the free one, so the 1.9.56 step has to bring that schema itself
 * (_upgrade_1956_baseline()), and a free install that already has it must not
 * be changed by it a second time.
 *
 * Starts from the scratch database put in a paid 1.9.56's shape for what
 * differs (fixtures/ladder-runner.php runs the ladder as the upgrade page does).
 *
 * @group integration
 */
final class PaidVersionUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990956;

    /** A paid 1.9.56 password: salted MD5, 32 characters. */
    private const LEGACY_HASH = '0123456789abcdef0123456789abcdef';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    private static bool $backfillWasEmpty = false;

    /** @var array<string, string> table => SHOW CREATE TABLE of the fresh install */
    private static array $fresh = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        // A failed read must not become "no version", which teardown would then restore.
        $found = self::$db->query('SELECT version FROM 202_version');
        if (!$found instanceof \mysqli_result) {
            throw new \RuntimeException('could not read 202_version: ' . self::$db->error);
        }
        self::$versions = array_column($found->fetch_all(MYSQLI_ASSOC), 'version');
        $marks = self::$db->query('SELECT COUNT(*) FROM 202_reclick_backfill');
        if (!$marks instanceof \mysqli_result) {
            throw new \RuntimeException('could not read 202_reclick_backfill: ' . self::$db->error);
        }
        self::$backfillWasEmpty = (int) $marks->fetch_row()[0] === 0;
        foreach (['202_users', 'user_data_feedback'] as $table) {
            self::$fresh[$table] = self::shape($table);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
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
        self::cleanUp();
    }

    /** Puts back what a test (or a failed one) changed: the installed schema, no test rows. */
    private static function cleanUp(): void
    {
        self::q('DELETE FROM 202_attribution_models WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_users WHERE user_id = ' . self::USER);
        self::q('DROP TABLE IF EXISTS 202_clicks_purlink');
        self::q('ALTER TABLE 202_users MODIFY user_pass varchar(255) NOT NULL');
        self::q(\Prosper202\Database\Tables\CoreTables::userDataFeedback()->createStatement);
    }

    /** The table's DDL, less the AUTO_INCREMENT counter, which inserts move. */
    private static function shape(string $table): string
    {
        $ddl = (string) self::$db->query('SHOW CREATE TABLE `' . $table . '`')->fetch_row()[1];

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

    /** What a paid 1.9.56 database has that a free one does not, and lacks that a free one has. */
    private static function paidShape(): void
    {
        self::q('DROP TABLE user_data_feedback');
        self::q('ALTER TABLE 202_users MODIFY user_pass char(32) NOT NULL');
        // As the paid 1.9.55 -> 1.9.56 step made it (with a valid charset clause).
        self::q('CREATE TABLE 202_clicks_purlink (
            `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
            `click_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `acip` int(10) unsigned NOT NULL DEFAULT 0,
            `lpip` int(10) unsigned NOT NULL DEFAULT 0,
            `click_outbound_site_url_id` bigint(20) unsigned NOT NULL DEFAULT 0,
            `click_lead` tinyint(1) unsigned NOT NULL DEFAULT 0,
            `click_time` int(10) unsigned NOT NULL,
            PRIMARY KEY (`id`),
            KEY `click_lead` (`click_lead`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        self::q('INSERT INTO 202_clicks_purlink SET click_id = 1, click_time = 1');
        self::q('INSERT INTO 202_users SET user_id = ' . self::USER . ", user_name = 'paidupgrade',"
            . " user_email = 'paidupgrade@example.com', user_dash_email = '', user_pass = '" . self::LEGACY_HASH . "',"
            . " user_timezone = 'UTC', user_time_register = 0, install_hash = '', user_hash = ''");
        self::stored('1.9.56');
    }

    private static function assertUpgradedToTheInstalledSchema(string $out, string $err): void
    {
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        foreach (self::$fresh as $table => $ddl) {
            self::assertSame($ddl, self::shape($table), "the upgraded $table is the installed one");
        }
    }

    public function testAPaid1956DatabaseGetsTheSchemaOfTheFree1956Step(): void
    {
        self::paidShape();

        [$out, $err] = self::upgrade();

        self::assertUpgradedToTheInstalledSchema($out, $err);
        $pass = self::$db->query('SELECT user_pass FROM 202_users WHERE user_id = ' . self::USER)->fetch_row()[0];
        self::assertSame(self::LEGACY_HASH, $pass, 'the stored password is kept, so the legacy sign-in still works');

        // A password set before anyone signs in (API, admin, CLI) now fits whole.
        require_once __DIR__ . '/../../202-config/functions-auth.php';
        $hash = hash_user_pass('a new password');
        self::assertGreaterThan(32, strlen($hash));
        self::q('UPDATE 202_users SET user_pass = ' . self::str($hash) . ' WHERE user_id = ' . self::USER);
        $pass = self::$db->query('SELECT user_pass FROM 202_users WHERE user_id = ' . self::USER)->fetch_row()[0];
        self::assertSame($hash, $pass);

        // The paid-only table is not the ladder's to touch.
        self::assertSame('1', (string) self::$db->query('SELECT COUNT(*) FROM 202_clicks_purlink')->fetch_row()[0]);
    }

    public function testTheFreePathIsUnchangedAndASecondRunChangesNothing(): void
    {
        // Free 1.9.55: the 1.9.55 step brings the schema, the 1.9.56 step then finds it there.
        self::q('DROP TABLE user_data_feedback');
        self::q('ALTER TABLE 202_users MODIFY user_pass char(32) NOT NULL');
        self::stored('1.9.55');
        [$out, $err] = self::upgrade();
        self::assertUpgradedToTheInstalledSchema($out, $err);

        // A free 1.9.56 (already widened, table there): nothing to do.
        self::stored('1.9.56');
        [$out, $err] = self::upgrade();
        self::assertUpgradedToTheInstalledSchema($out, $err);
    }

    public function testAColumnAlreadyWiderThanTheInstallersIsNotNarrowed(): void
    {
        self::q('ALTER TABLE 202_users MODIFY user_pass varchar(300) NOT NULL');
        self::stored('1.9.56');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        $type = self::$db->query("SHOW COLUMNS FROM 202_users LIKE 'user_pass'")->fetch_assoc()['Type'] ?? null;
        self::assertSame('varchar(300)', $type);
    }
}
