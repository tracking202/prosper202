<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.79 -> 1.9.80 rung creates the money-loss alerts' tables
 * (202_alert_settings, 202_alert_state) and adds 202_conversion_logs'
 * user_conv_time as the installer makes them, keeps the conversions there,
 * and logs nothing: a 1.9.79 install has none of them, so every account has
 * the defaults (alerts on) until it changes them; an account that turned them
 * off keeps its row through the step.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class MoneyLossAlertsUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLES = ['202_alert_settings', '202_alert_state', '202_alert_log', '202_conversion_logs'];

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        $rows = $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [];
        self::$versions = array_column($rows, 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
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
    }

    public function testTheRungCreatesTheTablesAndTheIndexAsTheInstallerDoes(): void
    {
        $installed = self::shapes();
        self::q('DROP TABLE 202_alert_settings');
        self::q('DROP TABLE 202_alert_state');
        self::q('DROP TABLE 202_alert_log');
        // As 1.9.79 left it: neither of the keys nor the columns the 1.9.80 step adds.
        self::q('ALTER TABLE 202_conversion_logs DROP INDEX user_conv_time, DROP INDEX user_currency_click_time,'
            . ' DROP COLUMN currency, DROP COLUMN stated_amount, DROP COLUMN stated_currency, DROP COLUMN currency_basis');
        self::q('DELETE FROM 202_conversion_logs WHERE user_id = 7899');
        self::q('INSERT INTO 202_conversion_logs SET click_id = 978990001, campaign_id = 1, click_payout = 1,'
            . " user_id = 7899, click_time = 1, conv_time = 2, time_difference = '', pixel_type = 0,"
            . " user_agent = '', dedupe_key = 'k978990001'");
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);

        try {
            [$out, $err] = self::upgrade();

            self::assertSame('RETURNED true', $out, $err);
            self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
            self::assertSame($installed, self::shapes(), 'each table is the installed one');
            $kept = self::$db->query('SELECT conv_time FROM 202_conversion_logs WHERE user_id = 7899');
            self::assertInstanceOf(\mysqli_result::class, $kept);
            self::assertSame([['2']], $kept->fetch_all());
            $version = self::$db->query('SELECT version FROM 202_version');
            self::assertInstanceOf(\mysqli_result::class, $version);
            self::assertSame('1.9.80', $version->fetch_row()[0]);
        } finally {
            self::q('DELETE FROM 202_conversion_logs WHERE user_id = 7899');
        }
    }

    public function testAnAccountThatTurnedAlertsOffStaysOffThroughTheStep(): void
    {
        self::q('DELETE FROM 202_alert_settings WHERE user_id = 7899');
        self::q('INSERT INTO 202_alert_settings SET user_id = 7899, enabled = 0, updated_at = 1');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);

        try {
            [$out, $err] = self::upgrade();

            self::assertSame('RETURNED true', $out, $err);
            $kept = self::$db->query('SELECT enabled FROM 202_alert_settings WHERE user_id = 7899');
            self::assertInstanceOf(\mysqli_result::class, $kept);
            self::assertSame([['0']], $kept->fetch_all());
        } finally {
            self::q('DELETE FROM 202_alert_settings WHERE user_id = 7899');
        }
    }

    /** @return array<string, string> */
    private static function shapes(): array
    {
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = self::shape($table);
        }

        return $out;
    }

    private static function shape(string $table): string
    {
        $ddl = self::$db->query("SHOW CREATE TABLE $table");
        self::assertInstanceOf(\mysqli_result::class, $ddl);

        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $ddl->fetch_row()[1]);
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
}
