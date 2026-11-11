<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;

/**
 * An upgrade whose connection to the database is lost still gets its report:
 * the pages read the stored version to say where the upgrade stopped, and
 * that read fails with the connection. The report says so and gives the
 * advice for a lost connection, on the upgrade page and in the 1-click log.
 *
 * @group integration
 */
final class LostConnectionUpgradeReportIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const string RUNNER = __DIR__ . '/fixtures/lost-connection-runner.php';

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::$db->close();
            self::$db = null;
        }
    }

    /** @return array{string, string} what the runner printed, and what was logged */
    private static function runner(string $lose, string $draw = ''): array
    {
        $proc = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', self::RUNNER],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            ['P202_LOSE' => $lose, 'P202_DRAW' => $draw] + getenv()
        );
        self::assertIsResource($proc);
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($proc), "the runner did not finish: $err");

        return [$out, $err];
    }

    public function testADatabaseLostPartWayAnswersFalseWithANoteAndTheReportIsDrawn(): void
    {
        self::requireScratch();

        [$out, $err] = self::runner('version-read');

        self::assertStringStartsWith("RETURNED false\n", $out, $err);
        self::assertStringNotContainsString('THREW', $out, 'the upgrade answers false; it does not end the page');
        self::assertStringContainsString('the upgrade stopped on a failed read of the database [errno 2006]', $out);
        self::assertStringContainsString('The connection to the database server was lost while the upgrade ran.', $out);
        self::assertStringContainsString('could not be read to say which version it is at', $out);
        $lock = "CONCAT('prosper202_upgrade:', SHA1(DATABASE()))";
        $held = self::$db->query('SELECT IS_FREE_LOCK(' . $lock . ')')->fetch_row();
        self::assertSame('1', (string) $held[0], 'the upgrade lock is not left held');
    }

    /** @return iterable<string, array{string}> */
    public static function losses(): iterable
    {
        yield 'lost before the upgrade' => [''];
        yield 'lost part way' => ['version-read'];
    }

    /**
     * What a 1-click page does after its database step failed, on the real
     * standalone shell: the signed-in shell and the retry form read the
     * database, so the log is drawn here and the request ends.
     *
     * @dataProvider losses
     */
    public function testAOneClickPageShowsItsLogAndEndsWhenTheDatabaseIsLost(string $lose): void
    {
        self::requireScratch();

        [$out, $err] = self::runner($lose, 'one-click');

        self::assertStringStartsWith("RETURNED false\n", $out, $err);
        self::assertStringNotContainsString('THREW', $out);
        self::assertStringNotContainsString('Fatal error', $out . $err);
        self::assertStringNotContainsString('CARRIED ON', $out, 'it ends before the shell that reads the database');
        self::assertStringEndsWith("</html>\n\nENDED", $out, 'the whole standalone page is drawn');
        self::assertStringContainsString('id="one-click-database-lost"', $out);
        self::assertStringContainsString('<textarea id="upgrade-log"', $out);
        self::assertStringContainsString('(the files are version 1.9.81)', $out);
        self::assertStringContainsString('The connection to the database server was lost', $out);
        self::assertStringContainsString('href="/202-config/upgrade.php">Run the database upgrade again</a>', $out);
    }

    public function testTheReportIsDrawnWithoutADatabaseToAsk(): void
    {
        self::requireScratch();
        $before = self::$db->query('SELECT version FROM 202_version')->fetch_all();

        [$out, $err] = self::runner('');

        self::assertStringStartsWith("RETURNED false\n", $out, $err);
        self::assertStringNotContainsString('THREW', $out, 'reading the stored version must not end the page');
        [$page, $log] = explode("\nLOG\n", $out, 2) + [1 => ''];
        self::assertStringContainsString('could not be read to say which version it is at', $page);
        self::assertStringContainsString('The connection to the database server was lost while', $page);
        self::assertStringContainsString('[errno 2006]', $page);
        self::assertStringContainsString('Running the upgrade again is safe', $page);
        self::assertStringContainsString('stopped at a version the database could not be read for', $log);
        self::assertStringContainsString('(the files are version 1.9.81)', $log);
        self::assertStringContainsString('The connection to the database server was lost', $log);
        self::assertStringContainsString('the stored version could not be read after the upgrade stopped', $err);

        self::assertSame($before, self::$db->query('SELECT version FROM 202_version')->fetch_all(), 'nothing was run');
    }
}
