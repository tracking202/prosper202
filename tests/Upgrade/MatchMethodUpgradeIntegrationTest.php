<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.78 -> 1.9.79 rung adds match_method to 202_conversion_logs and
 * 202_goal_events where the installer declares it, so an upgraded table is
 * the installed one column for column, and every row already there reads
 * 'unknown' (MatchMethod::UNKNOWN), the one spelling for "not recorded".
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78 whose two tables lack
 * the column, as a 1.9.78 install's do.
 *
 * @group integration
 */
final class MatchMethodUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLES = ['202_conversion_logs', '202_goal_events'];

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var array<string, list<array<string, mixed>>> the installer's columns, per table */
    private static array $installed = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column($found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [], 'version');
        foreach (self::TABLES as $table) {
            self::$installed[$table] = self::columns($table);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        foreach (self::TABLES as $table) {
            self::q("TRUNCATE TABLE $table");
            if (!in_array('match_method', array_column(self::columns($table), 'COLUMN_NAME'), true)) {
                self::q("ALTER TABLE $table ADD COLUMN `match_method` varchar(16) NOT NULL DEFAULT 'unknown'");
            }
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
        foreach (self::TABLES as $table) {
            self::q("TRUNCATE TABLE $table");
            if (in_array('match_method', array_column(self::columns($table), 'COLUMN_NAME'), true)) {
                self::q("ALTER TABLE $table DROP COLUMN match_method");
            }
        }
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
    }

    /** @return list<array<string, mixed>> */
    private static function columns(string $table): array
    {
        return self::$db->query("SELECT COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
            FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$table'
            ORDER BY ORDINAL_POSITION")->fetch_all(MYSQLI_ASSOC);
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

    public function testTheRungAddsTheColumnWhereTheInstallerDeclaresItAndOldRowsReadUnknown(): void
    {
        self::q("INSERT INTO 202_conversion_logs SET click_id = 1, campaign_id = 1, click_payout = 2, user_id = 1, click_time = 1,
            conv_time = 2, time_difference = '', ip = '', pixel_type = 2, user_agent = '', source = 'postback', dedupe_key = 'tx:OLD'");
        self::q("INSERT INTO 202_goal_events SET subject_type = 'click', subject_id = 1, user_id = 1, event_id = 'old', name = 'sale',
            properties = '{}', occurred_at = 1, received_at = 1, fingerprint = '" . str_repeat('0', 64) . "'");

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.79', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        foreach (self::TABLES as $table) {
            self::assertSame(self::$installed[$table], self::columns($table), "the upgraded $table is the installed one");
        }
        self::assertSame('unknown', (string) self::$db->query('SELECT match_method FROM 202_conversion_logs')->fetch_row()[0]);
        self::assertSame('unknown', (string) self::$db->query('SELECT match_method FROM 202_goal_events')->fetch_row()[0]);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');

        // A second run finds nothing to do.
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        foreach (self::TABLES as $table) {
            self::assertSame(self::$installed[$table], self::columns($table));
        }
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
    }
}
