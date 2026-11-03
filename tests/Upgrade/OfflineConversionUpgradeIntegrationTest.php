<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * The 1.9.78 -> 1.9.79 rung creates 202_offline_conversion_feeds and
 * 202_offline_conversion_names as the installer does, so an upgraded install
 * has the installed tables statement for statement, and a second run finds
 * nothing to do.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.78 without the two tables, as
 * a 1.9.78 install is.
 *
 * @group integration
 */
final class OfflineConversionUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const TABLES = ['202_offline_conversion_feeds', '202_offline_conversion_names'];

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var array<string, string> the installer's CREATE TABLE, per table */
    private static array $installed = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        $found = self::$db->query('SELECT version FROM 202_version');
        self::$versions = array_column(
            $found instanceof \mysqli_result ? $found->fetch_all(MYSQLI_ASSOC) : [],
            'version'
        );
        foreach (self::TABLES as $table) {
            self::$installed[$table] = self::created($table);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        foreach (self::TABLES as $table) {
            if (self::created($table) === '') {
                self::q(self::$installed[$table]);
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
            self::q("DROP TABLE IF EXISTS $table");
        }
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
    }

    /** The table's CREATE TABLE without its AUTO_INCREMENT counter, or '' when there is none. */
    private static function created(string $table): string
    {
        $exists = self::$db->query("SHOW TABLES LIKE '" . self::$db->real_escape_string($table) . "'");
        self::assertInstanceOf(\mysqli_result::class, $exists, self::$db->error);
        if ($exists->num_rows === 0) {
            return '';
        }
        $create = self::$db->query("SHOW CREATE TABLE `$table`");
        self::assertInstanceOf(\mysqli_result::class, $create, self::$db->error);
        $row = $create->fetch_row();
        self::assertIsArray($row);

        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) $row[1]);
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

    public function testTheRungCreatesTheTablesTheInstallerCreates(): void
    {
        foreach (self::TABLES as $table) {
            self::assertSame('', self::created($table), "$table is gone before the rung");
        }

        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        $version = self::$db->query('SELECT version FROM 202_version');
        self::assertInstanceOf(\mysqli_result::class, $version, self::$db->error);
        self::assertSame(['1.9.80'], $version->fetch_row());
        foreach (self::TABLES as $table) {
            self::assertSame(self::$installed[$table], self::created($table), "the upgraded $table is the installed one");
        }
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');

        // A second run finds nothing to do.
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        foreach (self::TABLES as $table) {
            self::assertSame(self::$installed[$table], self::created($table));
        }
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
    }
}
