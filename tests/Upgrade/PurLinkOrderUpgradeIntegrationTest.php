<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Tables\ClickTables;
use Prosper202\Database\Tables\GoalTables;

/** @group integration */
final class PurLinkOrderUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private static array $versions = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) { return; }
        self::$versions = array_column(self::$db->query('SELECT version FROM 202_version')->fetch_all(MYSQLI_ASSOC), 'version');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) { return; }
        self::q('DROP VIEW IF EXISTS 202_purlink_order_blocker');
        self::q(ClickTables::purLinkExitOrder()->createStatement);
        self::q(ClickTables::purLinkPixelFires()->createStatement);
        self::q(GoalTables::purLinkGoalSubjects()->createStatement);
        self::q('DELETE FROM 202_version');
        foreach (self::$versions as $version) { self::row('202_version', ['version' => $version]); }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void { self::requireScratch(); }

    /** @dataProvider priorVersions */
    public function testUpgradeInstallsTheSameTableAndRetriesWithoutLosingState(string $version): void
    {
        $shape = self::shape();
        $goalShape = self::goalShape();
        $pixelShape = self::pixelShape();
        self::q('DROP TABLE 202_purlink_exit_order');
        self::q('DROP TABLE IF EXISTS 202_purlink_pixel_fires');
        self::q('DROP TABLE 202_purlink_goal_subjects');
        self::setVersion($version);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err);
        self::assertSame('1.9.81', self::version());
        self::assertSame($shape, self::shape());
        self::assertSame($goalShape, self::goalShape());
        self::assertSame($pixelShape, self::pixelShape());
        self::q('INSERT INTO 202_purlink_pixel_fires VALUES (789, 123, 1, 2000000000)');
        self::q('INSERT INTO 202_purlink_exit_order VALUES (123, 1, 2, 3, 4, 2000000000)');
        self::q('INSERT INTO 202_purlink_goal_subjects (subject_id,user_id,click_id,landing_page_id,campaign_id) VALUES (456,1,123,2,4)');
        self::setVersion($version);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.81', self::version());
        self::assertSame('3', self::$db->query('SELECT sequence FROM 202_purlink_exit_order WHERE click_id=123')->fetch_row()[0]);
        self::assertSame('4', self::$db->query('SELECT campaign_id FROM 202_purlink_goal_subjects WHERE subject_id=456')->fetch_row()[0]);
        self::assertSame('123', self::$db->query('SELECT click_id FROM 202_purlink_pixel_fires WHERE conv_id=789')->fetch_row()[0]);
        self::q('DELETE FROM 202_purlink_exit_order WHERE click_id=123');
        self::q('DELETE FROM 202_purlink_pixel_fires WHERE conv_id=789');
        self::q('DELETE FROM 202_purlink_goal_subjects WHERE subject_id=456');
    }

    public function testFailureDoesNotAdvanceVersionAndTheNextRunRetries(): void
    {
        self::q('DROP TABLE 202_purlink_exit_order');
        self::q('CREATE VIEW 202_purlink_exit_order AS SELECT 1 AS click_id');
        self::setVersion('1.9.80');
        try {
            [$out, $err] = self::upgrade();
            self::assertSame('RETURNED false', $out, $err);
            self::assertSame('1.9.80', self::version(), 'a failed schema step must not mark itself complete');
            self::assertStringContainsString('Prosper202 upgrade', $err);
        } finally {
            self::q('DROP VIEW 202_purlink_exit_order');
        }
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame('1.9.81', self::version());
        self::assertNotSame('', self::shape());
    }

    public static function priorVersions(): iterable
    {
        yield 'schema 1.9.79' => ['1.9.79'];
        yield 'already labelled 1.9.80' => ['1.9.80'];
    }

    private static function setVersion(string $version): void
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => $version]);
    }

    private static function version(): string
    {
        return (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0];
    }

    private static function shape(): string
    {
        return (string) self::$db->query('SHOW CREATE TABLE 202_purlink_exit_order')->fetch_row()[1];
    }

    private static function pixelShape(): string
    {
        self::q(ClickTables::purLinkPixelFires()->createStatement);
        return (string) self::$db->query('SHOW CREATE TABLE 202_purlink_pixel_fires')->fetch_row()[1];
    }

    private static function goalShape(): string
    {
        return (string) preg_replace('/ AUTO_INCREMENT=\d+/', '', (string) self::$db->query('SHOW CREATE TABLE 202_purlink_goal_subjects')->fetch_row()[1]);
    }

    /** @return array{string, string} */
    private static function upgrade(): array
    {
        $proc = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_log=', __DIR__ . '/fixtures/ladder-runner.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, getenv());
        self::assertIsResource($proc);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertNotSame(2, proc_close($proc));
        return [trim((string) $out), (string) $err];
    }
}
