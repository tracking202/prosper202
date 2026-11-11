<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\ConversionTables;

/**
 * The 1.9.79 -> 1.9.80 rung gives a 202_destination_deliveries that is
 * already there without it the key user_destination_status, which Setup ›
 * Destinations' counts, filtered log and bulk send-again read by: a table an
 * earlier build of the rung made. Afterwards the table is the fresh
 * install's, its rows are kept, and a second run changes nothing.
 *
 * The rung runs as the upgrade page runs it (UPGRADE::upgrade_databases(),
 * fixtures/ladder-runner.php) from a stored 1.9.79.
 *
 * @group integration
 */
final class DestinationDeliveriesKeyUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990971;
    private const KEY = 'user_destination_status';

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
        self::q('DELETE FROM 202_destination_deliveries WHERE user_id = ' . self::USER);
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
        self::q(ConversionTables::destinationDeliveries()->createStatement);
        self::q('DELETE FROM 202_destination_deliveries WHERE user_id = ' . self::USER);
    }

    private static function hasKey(): bool
    {
        $found = self::$db->query("SHOW INDEX FROM 202_destination_deliveries WHERE Key_name = '" . self::KEY . "'");

        return $found instanceof \mysqli_result && $found->num_rows > 0;
    }

    /** The table's DDL, less the AUTO_INCREMENT counter, which inserts move. */
    private static function shape(): string
    {
        $ddl = (string) self::$db->query('SHOW CREATE TABLE 202_destination_deliveries')->fetch_row()[1];

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

    public function testTheRungAddsTheKeyToATableThatIsAlreadyThereAndKeepsItsRows(): void
    {
        self::assertStringContainsString('KEY `' . self::KEY . '`', ConversionTables::destinationDeliveries()->createStatement);
        self::assertTrue(self::hasKey(), 'the installer makes it');
        $fresh = self::shape();

        self::q('ALTER TABLE 202_destination_deliveries DROP KEY ' . self::KEY);
        self::assertFalse(self::hasKey(), 'the table as an earlier build of the rung made it');
        self::q('INSERT INTO 202_destination_deliveries SET user_id = ' . self::USER . ", destination_id = 7, conv_id = 990971, kind = 'conversion',
            status = 'failed', attempts = 8, next_attempt_at = 0, created_at = 1767225600");

        self::stored('1.9.79');
        [$out, $err] = self::upgrade();
        self::assertSame('1.9.80', self::$db->query('SELECT version FROM 202_version')->fetch_row()[0], "the rung ran: $out $err");
        self::assertTrue(self::hasKey(), 'the rung added it');
        self::assertSame($fresh, self::shape(), 'the upgraded table is the installed one');
        self::assertSame('failed', self::$db->query('SELECT status FROM 202_destination_deliveries WHERE user_id = ' . self::USER)->fetch_row()[0], 'its rows are kept');

        self::stored('1.9.79');
        self::upgrade();
        self::assertSame($fresh, self::shape(), 'a second run changes nothing');
    }
}
