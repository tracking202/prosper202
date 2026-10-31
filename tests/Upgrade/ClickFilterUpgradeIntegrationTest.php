<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.78 -> 1.9.79 rung creates the click filter's tables
 * (202_click_filter_settings, 202_click_filter_entries,
 * 202_clicks_filter_reason) as the installer makes them, logs nothing, and
 * leaves 202_clicks as it was: a 1.9.78 install has none of them, and every
 * account is filtered by the defaults until it changes them.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class ClickFilterUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLES = ['202_click_filter_settings', '202_click_filter_entries', '202_clicks_filter_reason'];

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

    public function testTheRungCreatesTheTablesAsTheInstallerDoes(): void
    {
        $installed = self::shapes();
        $clicks = self::shape('202_clicks');
        foreach (self::TABLES as $table) {
            self::q("DROP TABLE $table");
        }
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame($installed, self::shapes(), 'each table is the installed one');
        self::assertSame($clicks, self::shape('202_clicks'), '202_clicks is not altered');
        $version = self::$db->query('SELECT version FROM 202_version');
        self::assertInstanceOf(\mysqli_result::class, $version);
        self::assertSame('1.9.79', $version->fetch_row()[0]);
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
