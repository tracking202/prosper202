<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\TrackingTables;

/**
 * The 1.9.79 -> 1.9.80 rung adds
 * 202_ppc_network_variables.copied_from_ppc_variable_id (the variable a
 * traffic source's copy took each of its own from,
 * TrafficSourceOwners::split()) as the installer makes it: null on every
 * variable already there. Afterwards the table is the fresh install's, and
 * a second run changes nothing.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php)
 * from a stored 1.9.79, on a table without the column.
 *
 * @group integration
 */
final class CopiedVariableUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const VARIABLE = 990971;

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
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = ' . self::VARIABLE);
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
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id = ' . self::VARIABLE);
        $has = self::$db->query("SHOW COLUMNS FROM 202_ppc_network_variables LIKE 'copied_from_ppc_variable_id'");
        if ($has instanceof \mysqli_result && $has->num_rows === 0) {
            self::q('ALTER TABLE 202_ppc_network_variables'
                . ' ADD COLUMN copied_from_ppc_variable_id int(11) unsigned DEFAULT NULL AFTER deleted');
        }
    }

    /** The table's DDL, less the AUTO_INCREMENT counter, which inserts move. */
    private static function shape(): string
    {
        $ddl = (string) self::$db->query('SHOW CREATE TABLE 202_ppc_network_variables')->fetch_row()[1];

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

    /** @return array<string, string|null> */
    private static function variable(): array
    {
        return self::$db->query('SELECT name, parameter, deleted, copied_from_ppc_variable_id'
            . ' FROM 202_ppc_network_variables WHERE ppc_variable_id = ' . self::VARIABLE)->fetch_assoc();
    }

    public function testTheRungAddsTheColumnAsTheInstallerMakesIt(): void
    {
        self::assertStringContainsString(
            '`copied_from_ppc_variable_id`',
            TrackingTables::ppcNetworkVariables()->createStatement
        );
        $fresh = self::shape();

        // A 1.9.79 install's table, with a variable in it.
        self::q('ALTER TABLE 202_ppc_network_variables DROP COLUMN copied_from_ppc_variable_id');
        self::q('INSERT INTO 202_ppc_network_variables SET ppc_variable_id = ' . self::VARIABLE
            . ", ppc_network_id = 7, name = 'Placement', parameter = 'placement', placeholder = '{placement}'");
        self::stored('1.9.79');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        self::assertSame(
            ['name' => 'Placement', 'parameter' => 'placement', 'deleted' => '0',
                'copied_from_ppc_variable_id' => null],
            self::variable()
        );

        // Run again: nothing moves, and a copy's origin is kept.
        self::q('UPDATE 202_ppc_network_variables SET copied_from_ppc_variable_id = 41 WHERE ppc_variable_id = '
            . self::VARIABLE);
        self::stored('1.9.79');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        self::assertSame('41', self::variable()['copied_from_ppc_variable_id']);
    }
}
