<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdColumns;
use Prosper202\Click\ClickRecord;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Conversion\TrafficSourcePixels;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaReconciler;
use Prosper202\Database\Tables\TrackingTables;

/**
 * The 1.9.78 -> 1.9.79 rung adds 202_google's gbraid, wbraid and ttclid as
 * the installer declares them; and before it has, code already on 1.9.79
 * records a click without them rather than losing it: the click path does
 * not ask whether the database is upgraded, and an install's files are
 * replaced before its upgrade runs.
 *
 * Each test starts from 202_google as a 1.9.78 install has it, without the
 * three columns. The rung runs as the upgrade page runs it
 * (UPGRADE::upgrade_databases(), fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class AdClickIdsUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990961;
    private const FIRST_CLICK = 99096101;

    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
        '202_google', '202_clicks_record', '202_clicks_site',
    ];

    /** 202_google as the installer creates it. */
    private static string $installed = '';

    /** @var list<string>|null 202_version as this class found it, put back after */
    private static ?array $versions = null;

    /** @var list<array<string, string|null>> 202_reclick_backfill as found, which the rung marks */
    private static array $backfill = [];

    public static function setUpBeforeClass(): void
    {
        if (!self::connectScratch()) {
            return;
        }
        self::restoreColumns();
        self::$installed = self::createTable();
        self::$versions = array_column(self::all('SELECT version FROM 202_version'), 'version');
        self::$backfill = self::all('SELECT * FROM 202_reclick_backfill');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::cleanUp();
        self::restoreColumns();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
        self::q('DELETE FROM 202_reclick_backfill');
        foreach (self::$backfill as $row) {
            self::row('202_reclick_backfill', $row);
        }
        self::$db->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
        self::restoreColumns();
        self::q('ALTER TABLE 202_google DROP COLUMN gbraid, DROP COLUMN wbraid, DROP COLUMN ttclid');
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
    }

    protected function tearDown(): void
    {
        if (self::$db !== null) {
            self::restoreColumns();
        }
    }

    private static function cleanUp(): void
    {
        $range = 'BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FIRST_CLICK + 99);
        foreach ([...self::TABLES, '202_click_latest_visits'] as $table) {
            self::q("DELETE FROM $table WHERE click_id $range");
        }
    }

    /** The three columns back where a test took them away, as the installer declares them. */
    private static function restoreColumns(): void
    {
        $reconciler = new SchemaReconciler(static fn (string $sql): mixed => self::$db->query($sql));
        self::assertTrue($reconciler->reconcile(TrackingTables::google()), implode('; ', $reconciler->getErrors()));
    }

    private static function createTable(): string
    {
        return (string) self::all('SHOW CREATE TABLE 202_google')[0]['Create Table'];
    }

    /** @return list<array<string, string|null>> */
    private static function all(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error . ' in ' . $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
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

    /** A landing page click with the ids it came with; what was logged while it was recorded. */
    private static function recordLogged(int $clickId, string $gclid, string $gbraid, string $ttclid): string
    {
        $log = tempnam(sys_get_temp_dir(), 'p202-adids-log');
        self::assertIsString($log);
        $before = ini_get('error_log');
        ini_set('error_log', $log);
        try {
            (new MysqlClickRepository(new Connection(self::$db)))->recordClick(new ClickRecord(
                clickId: $clickId,
                userId: self::USER,
                clickTime: time(),
                c1Id: 7,
                gclid: $gclid,
                gbraid: $gbraid,
                ttclid: $ttclid,
                utmSourceId: 31,
                clickIdPublic: '9' . $clickId,
            ));
        } finally {
            ini_set('error_log', (string) $before);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        return $logged;
    }

    public function testTheRungAddsTheColumnsAsTheInstallerDeclaresThem(): void
    {
        self::assertStringNotContainsString('`ttclid`', self::createTable(), 'the test starts at 1.9.78');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.79', (string) self::all('SELECT version FROM 202_version')[0]['version']);
        self::assertSame(self::$installed, self::createTable(), 'upgraded, 202_google is what an install creates');

        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.78']);
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame(self::$installed, self::createTable(), 'run again, the rung changes nothing');
    }

    /**
     * Before the upgrade the click is stored, every row of it, its gclid
     * and UTM values included, and the ids it has no column for are said in
     * the log; a click that came with none of them says nothing. The tokens
     * read the ids as none.
     */
    public function testAClickBeforeTheUpgradeIsStoredWithoutTheIdsItHasNoColumnFor(): void
    {
        $clickId = self::FIRST_CLICK;
        $logged = self::recordLogged($clickId, 'Cj0gclid', '0AAAAgbraid', 'E.C.P.ttclid');

        self::assertStringContainsString(
            "p202 click: click $clickId is stored without the gbraid, ttclid it came with",
            $logged
        );
        foreach (self::TABLES as $table) {
            self::assertCount(1, self::all("SELECT 1 FROM $table WHERE click_id = $clickId"), "$table has the click");
        }
        self::assertSame(
            [['gclid' => 'Cj0gclid', 'utm_source_id' => '31']],
            self::all("SELECT gclid, utm_source_id FROM 202_google WHERE click_id = $clickId")
        );
        self::assertSame(
            ['gbraid' => '', 'wbraid' => '', 'ttclid' => ''],
            AdClickIdColumns::of(new Connection(self::$db), $clickId)
        );
        $tokens = TrafficSourcePixels::clickTokens(new Connection(self::$db), self::USER, $clickId)['tokens'] ?? [];
        self::assertSame(
            ['gclid' => 'Cj0gclid', 'gbraid' => '', 'wbraid' => '', 'ttclid' => ''],
            array_intersect_key($tokens, ClickRecord::AD_CLICK_IDS)
        );

        self::assertSame('', self::recordLogged($clickId + 1, 'Cj0other', '', ''), 'nothing dropped, nothing said');
        self::assertCount(1, self::all('SELECT 1 FROM 202_google WHERE click_id = ' . ($clickId + 1)));
    }
}
