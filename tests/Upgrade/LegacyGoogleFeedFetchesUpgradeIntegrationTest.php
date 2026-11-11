<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\ConversionTables;

/**
 * The 1.9.79 -> 1.9.80 rung creates 202_legacy_google_feed_fetches (the
 * schedules still fetching paid 1.9.56's retired Google Ads feed) as the
 * installer makes it, logs nothing when it converges, and converges again
 * on a table that is already there (a retried upgrade).
 *
 * @group integration
 */
final class LegacyGoogleFeedFetchesUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

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

    public function testTheRungCreatesTheInstalledTableAndRecordsTheVersion(): void
    {
        self::requireScratch();
        $fresh = self::shape();
        self::q('DROP TABLE 202_legacy_google_feed_fetches');
        self::version('1.9.79');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame($fresh, self::shape(), 'the table is the installed one');
        self::assertSame('1.9.80', self::storedVersion());
    }

    public function testTheRungConvergesOnATableAlreadyThere(): void
    {
        self::requireScratch();
        $fresh = self::shape();
        self::version('1.9.79');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame($fresh, self::shape());
        self::assertSame('1.9.80', self::storedVersion());
    }

    private static function version(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    private static function storedVersion(): string
    {
        $found = self::$db->query('SELECT version FROM 202_version LIMIT 1');
        self::assertInstanceOf(\mysqli_result::class, $found);

        return (string) $found->fetch_row()[0];
    }

    private static function shape(): string
    {
        $ddl = self::$db->query('SHOW CREATE TABLE 202_legacy_google_feed_fetches');
        self::assertInstanceOf(\mysqli_result::class, $ddl);
        self::assertStringContainsString('`fetch_id`', ConversionTables::legacyGoogleFeedFetches()->createStatement);

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
