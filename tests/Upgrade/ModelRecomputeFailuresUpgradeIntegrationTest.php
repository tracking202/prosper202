<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\AttributionTables;

/**
 * The 1.9.79 -> 1.9.80 rung adds 202_attribution_models.recompute_failures,
 * recompute_retry_at and recompute_error (AttributionWorker::
 * fanOutModelRecomputes()) as the installer makes them, so every model
 * already there, including one with a recompute waiting, is at 0 failures
 * and due at once. Afterwards the table is the fresh install's (the
 * comparison tests/live/upgrade-equals-install.sh makes), and a second run
 * changes nothing.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.79, on a
 * 202_attribution_models without the columns.
 *
 * @group integration
 */
final class ModelRecomputeFailuresUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const MODEL = 990961;
    private const COLUMNS = ['recompute_failures', 'recompute_retry_at', 'recompute_error'];

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
        self::q('DELETE FROM 202_attribution_models WHERE model_id = ' . self::MODEL);
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
        self::q('DELETE FROM 202_attribution_models WHERE model_id = ' . self::MODEL);
        self::q(AttributionTables::attributionModels()->createStatement);
        $has = self::$db->query("SHOW COLUMNS FROM 202_attribution_models LIKE 'recompute_failures'");
        if ($has instanceof \mysqli_result && $has->num_rows === 0) {
            self::q("ALTER TABLE 202_attribution_models
                ADD COLUMN recompute_failures smallint(5) unsigned NOT NULL DEFAULT '0' AFTER recompute_cursor,
                ADD COLUMN recompute_retry_at int(10) unsigned NOT NULL DEFAULT '0' AFTER recompute_failures,
                ADD COLUMN recompute_error varchar(255) DEFAULT NULL AFTER recompute_retry_at");
        }
    }

    /** The table's DDL, less the AUTO_INCREMENT counter, which inserts move. */
    private static function shape(): string
    {
        $ddl = (string) self::$db->query('SHOW CREATE TABLE 202_attribution_models')->fetch_row()[1];

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
    private static function failureState(): array
    {
        $r = self::$db->query('SELECT recompute_requested_at, recompute_failures, recompute_retry_at, recompute_error
            FROM 202_attribution_models WHERE model_id = ' . self::MODEL);

        return $r->fetch_assoc();
    }

    public function testTheRungAddsTheFailureColumnsAsTheInstallerMakesThem(): void
    {
        foreach (self::COLUMNS as $column) {
            self::assertStringContainsString('`' . $column . '`', AttributionTables::attributionModels()->createStatement);
        }
        $fresh = self::shape();

        // A 1.9.79 install's table, with a model whose recompute is waiting.
        self::q('ALTER TABLE 202_attribution_models DROP COLUMN recompute_failures,'
            . ' DROP COLUMN recompute_retry_at, DROP COLUMN recompute_error');
        self::q('INSERT INTO 202_attribution_models SET model_id = ' . self::MODEL . ", user_id = 1,"
            . " model_name = 'Upgrade', model_slug = 'upgrade-" . self::MODEL . "', model_type = 'linear',"
            . " weighting_config = '{}', recompute_requested_at = 5, created_at = 1, updated_at = 1");
        self::stored('1.9.79');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        self::assertSame(
            ['recompute_requested_at' => '5', 'recompute_failures' => '0', 'recompute_retry_at' => '0', 'recompute_error' => null],
            self::failureState(),
            'a waiting recompute keeps its request and is due at once'
        );

        // Run again (a retry of a step that failed elsewhere): nothing moves.
        self::q("UPDATE 202_attribution_models SET recompute_failures = 3, recompute_retry_at = 70,
            recompute_error = 'kept' WHERE model_id = " . self::MODEL);
        self::stored('1.9.79');
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        self::assertSame(
            ['recompute_requested_at' => '5', 'recompute_failures' => '3', 'recompute_retry_at' => '70', 'recompute_error' => 'kept'],
            self::failureState(),
            'recorded failures are kept'
        );
    }
}
