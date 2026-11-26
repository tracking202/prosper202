<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\CampaignTables;
use Prosper202\Database\Tables\TrackingTables;

/**
 * The 1.9.80 -> 1.9.81 rung widens 202_ppc_network_variables.ppc_network_id
 * from the signed mediumint the table was made with to the unsigned one
 * 202_ppc_networks.ppc_network_id is, so a source past 8388607 keeps its
 * custom variables under its own id rather than under 8388607 (the
 * reconciler retypes no column, so _upgrade_ppc_variable_network_width()
 * does). Afterwards the table is the fresh install's, the variables already
 * there are as they were, and a second run changes nothing.
 *
 * The rung runs as the upgrade page runs it (fixtures/ladder-runner.php)
 * from a stored 1.9.80, on a table with the signed column.
 *
 * @group integration
 */
final class NetworkVariableWidthUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const VARIABLE = 990981;
    private const PAST = 990982;

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
        self::clear();
        self::q('ALTER TABLE 202_ppc_network_variables MODIFY COLUMN ' . self::declared());
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
        self::clear();
        self::q('ALTER TABLE 202_ppc_network_variables MODIFY COLUMN ' . self::declared());
    }

    private static function clear(): void
    {
        self::q('DELETE FROM 202_ppc_network_variables WHERE ppc_variable_id IN (' . self::VARIABLE . ', '
            . self::PAST . ')');
    }

    /** The column as the installer makes it. */
    private static function declared(): string
    {
        return SchemaReconciler::requiredColumns(TrackingTables::ppcNetworkVariables())['ppc_network_id'];
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

    private static function sourceOf(int $variable): ?string
    {
        $row = self::$db->query('SELECT ppc_network_id FROM 202_ppc_network_variables WHERE ppc_variable_id = '
            . $variable)->fetch_row();

        return $row === null ? null : (string) $row[0];
    }

    public function testTheColumnIsAsWideAsTheSourceIdItNames(): void
    {
        $type = static function (string $column): string {
            self::assertSame(1, preg_match('/^`[^`]+`\s+(\w+(?:\(\d+\))?(?:\s+unsigned)?)/i', $column, $m), $column);

            return strtolower($m[1]);
        };
        self::assertSame(
            $type(SchemaReconciler::requiredColumns(CampaignTables::ppcNetworks())['ppc_network_id']),
            $type(self::declared())
        );
    }

    public function testTheRungWidensTheColumnAsTheInstallerMakesIt(): void
    {
        $fresh = self::shape();

        // A 1.9.80 install's table, with a variable on the highest id it holds.
        self::q('ALTER TABLE 202_ppc_network_variables MODIFY COLUMN `ppc_network_id` mediumint(8) NOT NULL');
        self::q('INSERT INTO 202_ppc_network_variables SET ppc_variable_id = ' . self::VARIABLE
            . ", ppc_network_id = 8388607, name = 'Placement', parameter = 'placement', placeholder = '{placement}'");
        self::assertNotSame($fresh, self::shape(), 'the table is the 1.9.80 one');
        self::stored('1.9.80');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.81', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        self::assertSame('8388607', self::sourceOf(self::VARIABLE), 'a variable already there keeps its source');

        // Under the app's empty sql_mode a source past the old width is kept.
        self::q("SET SESSION sql_mode=''");
        try {
            self::q('INSERT INTO 202_ppc_network_variables SET ppc_variable_id = ' . self::PAST
                . ", ppc_network_id = 16777215, name = 'Past', parameter = 'past', placeholder = '{past}'");
        } finally {
            self::q("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }
        self::assertSame('16777215', self::sourceOf(self::PAST));

        // Run again: nothing moves.
        self::stored('1.9.80');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        self::assertSame('16777215', self::sourceOf(self::PAST));
    }

    /**
     * A failed widen leaves the version where it is, so the next run retries.
     * The failure is made with a CHECK the widened row breaks: a negative id
     * becomes 0, which the CHECK refuses, so the server refuses the ALTER.
     */
    public function testAFailedWidenLeavesTheVersion(): void
    {
        self::q('ALTER TABLE 202_ppc_network_variables MODIFY COLUMN `ppc_network_id` mediumint(8) NOT NULL');
        self::q('INSERT INTO 202_ppc_network_variables SET ppc_variable_id = ' . self::VARIABLE
            . ", ppc_network_id = -5, name = 'Negative', parameter = 'neg', placeholder = '{neg}'");
        self::q('ALTER TABLE 202_ppc_network_variables ADD CONSTRAINT p202_test_k7wd CHECK (ppc_network_id <> 0)');
        self::stored('1.9.80');
        try {
            [$out, $err] = self::upgrade();
            $version = (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0];
            $type = (string) self::$db->query("SHOW COLUMNS FROM 202_ppc_network_variables LIKE 'ppc_network_id'")
                ->fetch_assoc()['Type'];
        } finally {
            self::q('ALTER TABLE 202_ppc_network_variables DROP CONSTRAINT p202_test_k7wd');
        }
        self::assertSame('1.9.80', $version, $out . $err);
        self::assertStringContainsString('failed to widen 202_ppc_network_variables.ppc_network_id', $err);
        self::assertStringNotContainsString('unsigned', $type, 'the column was not changed');
        self::assertSame('-5', self::sourceOf(self::VARIABLE));
    }
}
