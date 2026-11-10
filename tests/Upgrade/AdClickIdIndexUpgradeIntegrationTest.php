<?php

declare(strict_types=1);

namespace Tests\Upgrade;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\AdClickIdIndex;
use Prosper202\Click\ClickByAdClickId;
use Prosper202\Click\ClickRecordBuilder;
use Prosper202\Click\ClickRequest;
use Prosper202\Click\LatestVisit;
use Prosper202\Click\MysqlClickRepository;
use Prosper202\Database\Connection;

/**
 * 202_clicks_ad_ids says which clicks carry an ad click id
 * (Prosper202\Click\AdClickIdIndex), which a global postback with no SubID
 * finds its click by. The click path writes a click's rows with the click;
 * the 1.9.79 -> 1.9.80 rung makes the table as the installer declares it,
 * fills it for the clicks visited in the last 30 days with the rows the
 * click path would have written, and adds the account setting's column. A
 * click recorded before the upgrade ran is stored, and gets its rows from it.
 *
 * The rung runs in a process of its own (fixtures/ladder-runner.php).
 *
 * @group integration
 */
final class AdClickIdIndexUpgradeIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990729;
    private const FIRST_CLICK = 99072900;
    /** Further apart than one statement of the fill reads. */
    private const FAR_CLICK = self::FIRST_CLICK + 3 * AdClickIdIndex::BACKFILL_BATCH;
    private const TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_advance', '202_clicks_tracking', '202_clicks_variable',
        '202_google', '202_clicks_rotator', '202_clicks_record', '202_clicks_site', '202_click_latest_visits',
    ];
    private const GCLID = 'Cj0KCQiA-demo_Store-UPGRADE';
    private const MSCLKID = '9a3f6c1d2b7e4f08a1c5d9e3b6f2a7c4';

    /** @var array<string, string> the tables as the installer creates them */
    private static array $installed = [];

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
        foreach (['202_clicks_ad_ids', '202_users_pref', '202_google'] as $table) {
            self::$installed[$table] = self::createTable($table);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db === null) {
            return;
        }
        self::removeClicks();
        self::q('DELETE FROM 202_version');
        foreach (self::$versions ?? [] as $version) {
            self::row('202_version', ['version' => $version]);
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q(\Prosper202\Database\Tables\ClickTables::clicksAdIds()->createStatement);
        self::removeClicks();
    }

    private static function removeClicks(): void
    {
        $where = 'click_id BETWEEN ' . self::FIRST_CLICK . ' AND ' . (self::FAR_CLICK + 99);
        foreach (self::TABLES as $table) {
            self::q("DELETE FROM $table WHERE $where");
        }
        self::q('DELETE FROM 202_clicks_ad_ids');
    }

    private static function createTable(string $table): string
    {
        $result = self::$db->query("SHOW CREATE TABLE $table");
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return (string) $result->fetch_row()[1];
    }

    /**
     * A landing page click with the request's ids, as record_simple.php reads and builds it.
     *
     * @param array<string, string> $get
     */
    private static function record(int $clickId, array $get, int $age = 60): void
    {
        $mysql = ['user_id' => (string) self::USER, 'click_time' => (string) (time() - $age), 'click_cpc' => '0.10000'];
        foreach (ClickRequest::adClickIds($get) as $name => $id) {
            $mysql[$name] = $id;
        }
        (new MysqlClickRepository(new Connection(self::requireScratch())))
            ->recordClick(ClickRecordBuilder::fromLegacyArray($mysql, $clickId));
    }

    /** @return list<string> click:hash of every row of the test's clicks, in order */
    private static function rows(): array
    {
        $result = self::$db->query(
            'SELECT CONCAT(click_id, \':\', LOWER(HEX(id_hash))) FROM 202_clicks_ad_ids ORDER BY 1'
        );
        self::assertInstanceOf(\mysqli_result::class, $result, self::$db->error);

        return array_column($result->fetch_all(MYSQLI_NUM), 0);
    }

    /**
     * @param array<int, list<string>> $ids by click
     * @return list<string> the rows the click path writes for them
     */
    private static function rowsFor(array $ids): array
    {
        $rows = [];
        foreach ($ids as $click => $carried) {
            foreach ($carried as $id) {
                $rows[] = $click . ':' . bin2hex(AdClickIdIndex::hash($id));
            }
        }
        sort($rows);

        return $rows;
    }

    /** @return array{0: string, 1: string} what the runner printed, and what the ladder logged */
    private static function upgrade(): array
    {
        self::q('DELETE FROM 202_version');
        self::row('202_version', ['version' => '1.9.79']);
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

    public function testAClickIsRecordedWithARowForEachIdItKeeps(): void
    {
        self::record(self::FIRST_CLICK, ['gclid' => self::GCLID, 'msclkid' => self::MSCLKID, 'fbclid' => self::GCLID]);
        self::record(self::FIRST_CLICK + 1, ['c1' => 'no id at all']);

        self::assertSame(self::rowsFor([self::FIRST_CLICK => [self::GCLID, self::MSCLKID]]), self::rows());

        $found = ClickByAdClickId::find(new Connection(self::$db), ['msclkid' => self::MSCLKID], [], time() - 3600);
        self::assertSame(self::FIRST_CLICK, $found['click_id'], 'and a postback sending one back finds the click');
    }

    public function testAClickRecordedBeforeTheUpgradeIsStoredAndTheRungWritesItsRows(): void
    {
        self::q('DROP TABLE 202_clicks_ad_ids');
        self::q('ALTER TABLE 202_users_pref DROP COLUMN user_pref_click_id_params');
        self::record(self::FIRST_CLICK, ['gclid' => self::GCLID, 'msclkid' => self::MSCLKID]);
        self::record(self::FAR_CLICK, ['gbraid' => 'far-' . self::GCLID]);
        self::record(self::FIRST_CLICK + 2, ['c1' => 'no id at all']);
        $kept = self::$db->query('SELECT gclid, msclkid FROM 202_google WHERE click_id = ' . self::FIRST_CLICK);
        self::assertInstanceOf(\mysqli_result::class, $kept);
        self::assertSame([self::GCLID, self::MSCLKID], $kept->fetch_row(), 'the click is stored whole');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertStringNotContainsString('Prosper202 upgrade', $err, 'a rung that converged logs nothing');
        self::assertSame('1.9.80', (string) self::$db->query('SELECT version FROM 202_version')->fetch_row()[0]);
        foreach (self::$installed as $table => $installed) {
            self::assertSame($installed, self::createTable($table), "upgraded, $table is what an install creates");
        }
        $written = self::rowsFor([
            self::FIRST_CLICK => [self::GCLID, self::MSCLKID],
            self::FAR_CLICK => ['far-' . self::GCLID],
        ]);
        self::assertSame($written, self::rows(), 'the rows the click path writes, for clicks statements apart');

        // Run again, as a step that failed elsewhere is: nothing is written twice.
        [$out, $err] = self::upgrade();
        self::assertSame('RETURNED true', $out, $err);
        self::assertSame($written, self::rows());
    }

    public function testOnlyClicksAPostbackCanStillBeMatchedToAreFilled(): void
    {
        // The fill starts at the lowest click visited in the 30 days: none another suite left may be below these.
        self::q('DELETE FROM 202_clicks WHERE click_id < ' . self::FIRST_CLICK
            . ' AND click_time >= ' . (time() - ClickByAdClickId::LOOKBACK - 60));
        self::record(self::FIRST_CLICK, ['gclid' => 'old-' . self::GCLID], ClickByAdClickId::LOOKBACK + 7200);
        self::record(self::FIRST_CLICK + 5, ['gclid' => self::GCLID]);
        LatestVisit::refresh(new Connection(self::$db), self::FIRST_CLICK);
        self::q('DELETE FROM 202_clicks_ad_ids');

        [$out, $err] = self::upgrade();

        self::assertSame('RETURNED true', $out, $err);
        self::assertSame(self::rowsFor([self::FIRST_CLICK + 5 => [self::GCLID]]), self::rows());
    }

    public function testTheStepMakesTheTableAndTheSettingAndKeysNoIdOfAClick(): void
    {
        $ladder = (string) file_get_contents(dirname(__DIR__, 2) . '/202-config/functions-upgrade.php');
        $from = strpos($ladder, "if (\$prosper202_version == '1.9.79') {");
        $to = strpos($ladder, "UPDATE 202_version SET version='1.9.80'");
        self::assertIsInt($from);
        self::assertIsInt($to);
        $step = substr($ladder, $from, $to - $from);
        self::assertStringContainsString('ClickTables::clicksAdIds()', $step);
        self::assertStringContainsString('UserTables::usersPref()', $step);
        self::assertStringNotContainsString('KEY `by_', self::$installed['202_google'] ?? '');
    }
}
