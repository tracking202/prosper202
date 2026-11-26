<?php

declare(strict_types=1);

namespace Tests\Conversion;

use Api\V3\Controllers\UpdateController;
use Api\V3\Exception\NotFoundException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Conversion\RevenueUploadImporter;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;

/**
 * POST /conversions/uploads/{id}/undo (UploadBatchUndo): undoing a revenue
 * upload leaves the clicks and the report rows as they were before it, the
 * dry run lists exactly the rows the undo removes, and a batch of another
 * account is not found. Against a real server (P202_TEST_DB_HOST), as
 * ConversionLedgerIntegrationTest.
 *
 * @group integration
 */
final class UploadBatchUndoIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private MysqlConversionRepository $repo;
    private RevenueUploadImporter $importer;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);
        try {
            $db = @mysqli_connect(
                $host,
                (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
                (string) (getenv('P202_TEST_DB_PASS') ?: ''),
                (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
                (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
            );
        } catch (\Throwable) {
            return;
        }
        if (!$db) {
            return;
        }
        $db->query("SET SESSION sql_mode=''");
        (new SchemaInstaller($db))->install();
        $db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        self::$db?->close();
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach (['202_conversion_logs', '202_clicks', '202_clicks_spy', '202_aff_campaigns', '202_attribution_pending',
            '202_conversion_uploads', '202_dataengine', '202_notification_pending'] as $t) {
            self::$db->query('TRUNCATE TABLE ' . $t);
        }
        $conn = new Connection(self::$db);
        $this->repo = new MysqlConversionRepository($conn);
        $this->importer = new RevenueUploadImporter($conn, $this->repo);
    }

    private static function fixture(string $sql): void
    {
        self::$db->query("SET SESSION sql_mode=''");
        try {
            if (self::$db->query($sql) !== true) {
                throw new \RuntimeException('fixture failed: ' . self::$db->error);
            }
        } finally {
            self::$db->query("SET SESSION sql_mode='STRICT_TRANS_TABLES'");
        }
    }

    private function campaign(int $id, int $userId = 1): void
    {
        self::fixture("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=$userId, aff_network_id=1, aff_campaign_name='c$id',
            aff_campaign_url='http://x', aff_campaign_payout=10.00, aff_campaign_time=1, aff_campaign_foreign_payout=10.00, payout_mode='replace'");
    }

    private function click(int $id, int $campaign, int $userId = 1): void
    {
        foreach (['202_clicks', '202_clicks_spy'] as $t) {
            self::fixture("INSERT INTO $t SET click_id=$id, user_id=$userId, aff_campaign_id=$campaign, click_payout=0.00, click_cpc=0, click_lead=0, click_time=1700000000");
        }
        // Every tracked click has its report row before anything converts it.
        \Prosper202\DataEngine\ClickRollupSql::rollUpClick(new Connection(self::$db), $id);
    }

    private function upload(string $csv, int $userId = 1): int
    {
        $h = fopen('php://memory', 'r+');
        fwrite($h, $csv);
        rewind($h);

        return $this->importer->import($userId, 'report.csv', $h, 0, 1)['batch_id'];
    }

    /**
     * What a report and the click show: the click's lead flag and payout on
     * both click tables, and its row in the table every report reads.
     *
     * @return array<string, mixed>
     */
    private function state(int $clickId): array
    {
        $c = self::$db->query("SELECT click_lead, click_payout FROM 202_clicks WHERE click_id=$clickId")->fetch_assoc();
        $s = self::$db->query("SELECT click_lead, click_payout FROM 202_clicks_spy WHERE click_id=$clickId")->fetch_assoc();
        $r = self::$db->query("SELECT leads, payout, income FROM 202_dataengine WHERE click_id=$clickId")->fetch_assoc();

        return [
            'lead' => (int) $c['click_lead'],
            'spy_lead' => (int) $s['click_lead'],
            'report' => $r === null ? null : ['leads' => (int) $r['leads'], 'income' => (string) $r['income']],
        ];
    }

    /** @return list<int> the live conv_ids of a batch */
    private function liveRows(int $batchId): array
    {
        $rows = self::$db->query("SELECT conv_id FROM 202_conversion_logs WHERE source='revenue_upload' AND source_ref='batch:$batchId' AND deleted=0 ORDER BY conv_id")->fetch_all(MYSQLI_ASSOC);

        return array_map(static fn (array $r): int => (int) $r['conv_id'], $rows);
    }

    private static function payout(int $clickId): string
    {
        return (string) self::$db->query("SELECT click_payout FROM 202_clicks WHERE click_id=$clickId")->fetch_assoc()['click_payout'];
    }

    public function testUndoingTheNewestUploadRestoresTheOneBeforeItAndUndoingThatRestoresThePostback(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $this->click(102, 7);
        $this->repo->record(1, ['click_id' => 100, 'source' => 'postback', 'payout' => '9', 'transaction_id' => 'P'],
            \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID);
        $beforeAny = [100 => $this->state(100), 101 => $this->state(101), 102 => $this->state(102)];
        self::assertSame(1, $beforeAny[100]['lead']);
        self::assertSame('9.00000', self::payout(100));

        $a = $this->upload("subid,amount\n100,1.00\n100,2.00\n101,4\n");
        $afterA = [100 => $this->state(100), 101 => $this->state(101), 102 => $this->state(102)];
        $b = $this->upload("subid,amount\n100,5\n102,6\n");
        self::assertSame('5.00000', self::payout(100));
        self::assertSame(['leads' => 1, 'income' => '6.00000'], $this->state(102)['report'], 'the report sees batch B');

        $api = new UpdateController(self::$db, 1);

        // A row's amount is in the currency it recorded: the sums are per currency.
        self::fixture("UPDATE 202_conversion_logs SET currency = 'USD' WHERE source_ref = 'batch:$b' AND click_id = 100");
        self::fixture("UPDATE 202_conversion_logs SET currency = 'EUR' WHERE source_ref = 'batch:$b' AND click_id = 102");

        // The dry run lists exactly the rows the undo removes, and writes nothing.
        $preview = $api->undoUpload($b, [], true)['data'];
        self::assertTrue($preview['dry_run']);
        self::assertSame($this->liveRows($b), array_column($preview['rows'], 'conv_id'));
        self::assertSame([2, 3], array_column($preview['rows'], 'line'), 'each row names the CSV line it came from');
        self::assertSame(2, $preview['would_remove']);
        self::assertSame(2, $preview['clicks']);
        self::assertSame([['currency' => 'EUR', 'amount' => '6.00000'], ['currency' => 'USD', 'amount' => '5.00000']], $preview['amounts']);
        self::assertSame(['USD', 'EUR'], array_column($preview['rows'], 'currency'));
        self::assertSame(0, $preview['rows_unlisted']);
        self::assertSame([
            ['click_id' => 100, 'before' => ['lead' => true, 'value' => '5.00000'], 'after' => ['lead' => true, 'value' => '3.00000']],
            ['click_id' => 102, 'before' => ['lead' => true, 'value' => '6.00000'], 'after' => ['lead' => false, 'value' => null]],
        ], $preview['click_values']);
        self::assertSame('5.00000', self::payout(100), 'the dry run wrote nothing');
        self::assertCount(2, $this->liveRows($b));

        $done = $api->undoUpload($b, [], false)['data'];
        self::assertSame(2, $done['removed']);
        self::assertSame(2, $done['clicks']);
        self::assertNotNull($done['batch']['undone_at'], 'the undo is logged on the batch');
        self::assertSame(2, $done['batch']['undone_rows']);
        self::assertSame([], $this->liveRows($b));
        self::assertSame($afterA, [100 => $this->state(100), 101 => $this->state(101), 102 => $this->state(102)],
            'undoing batch B leaves the clicks and reports as batch A left them');
        self::assertSame('3.00000', self::payout(100), 'batch A counts again, summed within its file');

        // Again: nothing left to remove, and the log keeps what the first undo did.
        $again = $api->undoUpload($b, [], false)['data'];
        self::assertSame(0, $again['removed']);
        self::assertSame(2, $again['batch']['undone_rows']);
        self::assertSame(0, $api->undoUpload($b, [], true)['data']['would_remove']);

        $api->undoUpload($a, [], false);
        self::assertSame($beforeAny, [100 => $this->state(100), 101 => $this->state(101), 102 => $this->state(102)],
            'undoing both leaves every click and report row as it was before any upload');
        self::assertSame('9.00000', self::payout(100), 'the postback the uploads replaced counts again');

        $listed = $api->listUploads([])['data'];
        self::assertSame([$b, $a], array_column($listed, 'batch_id'), 'newest first');
        self::assertSame([2, 3], array_column($listed, 'undone_rows'));
    }

    public function testAnotherAccountsBatchIsNotFoundAndItsRowsAreLeftAlone(): void
    {
        $this->campaign(7);
        $this->campaign(8, 2);
        $this->click(100, 7);
        $this->click(200, 8, 2);
        $mine = $this->upload("subid,amount\n100,5\n");
        $theirs = $this->upload("subid,amount\n200,7\n", 2);

        $api = new UpdateController(self::$db, 1);
        foreach ([true, false] as $dryRun) {
            try {
                $api->undoUpload($theirs, [], $dryRun);
                self::fail('another account\'s batch was ' . ($dryRun ? 'previewed' : 'undone'));
            } catch (NotFoundException $e) {
                self::assertStringContainsString('Upload batch ' . $theirs . ' not found', $e->getMessage());
            }
        }
        self::assertCount(1, $this->liveRows($theirs));
        self::assertSame('7.00000', self::payout(200));
        self::assertSame([$mine], array_column($api->listUploads([])['data'], 'batch_id'), 'the list is the account\'s');

        try {
            $api->undoUpload(999999, [], false);
            self::fail('a batch that does not exist was undone');
        } catch (NotFoundException) {
        }
    }

    public function testTheUndoSettlesReversalsAndRefusesABody(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $batch = $this->upload("subid,amount\n100,5\n");
        $row = $this->liveRows($batch)[0];
        // A refund recorded against the uploaded line.
        self::fixture("INSERT INTO 202_conversion_logs SET click_id=100, user_id=1, campaign_id=7, click_payout=-2.00000, payable=1,
            click_time=1, conv_time=2, time_difference='', ip='', pixel_type=2, user_agent='', deleted=0, reverses_conv_id=$row,
            source='postback', source_ref='conv:$row', dedupe_key='rev:$row:1'");

        $api = new UpdateController(self::$db, 1);
        self::assertSame(1, $api->undoUpload($batch, [], true)['data']['reversals_stop_netting']);

        try {
            $api->undoUpload($batch, ['dry_run' => true], false);
            self::fail('a body was accepted');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertStringContainsString('dry_run', json_encode($e->getFieldErrors(), JSON_THROW_ON_ERROR));
        }
        self::assertCount(1, $this->liveRows($batch), 'the refused request wrote nothing');

        $api->undoUpload($batch, [], false);
        self::assertSame(0, $this->state(100)['lead'], 'the reversal stops netting with its sale gone: no lead');
        $rev = self::$db->query("SELECT deleted FROM 202_conversion_logs WHERE reverses_conv_id=$row")->fetch_assoc();
        self::assertSame('0', (string) $rev['deleted'], 'the reversal row stays');
    }

    public function testAClickRemovedSinceTheUploadIsListedAndItsRowsStillGo(): void
    {
        $this->campaign(7);
        $this->click(100, 7);
        $this->click(101, 7);
        $batch = $this->upload("subid,amount\n100,5\n101,6\n");
        // Click retention removes clicks and keeps their conversions.
        self::$db->query('DELETE FROM 202_clicks WHERE click_id = 101');
        self::$db->query('DELETE FROM 202_clicks_spy WHERE click_id = 101');

        $api = new UpdateController(self::$db, 1);
        $preview = $api->undoUpload($batch, [], true)['data'];
        self::assertSame(2, $preview['would_remove']);
        self::assertSame(0, $preview['click_values_unlisted']);
        self::assertSame([100, 101], array_column($preview['click_values'], 'click_id'), 'the removed click is listed, not dropped');
        self::assertNull($preview['click_values'][1]['before'], 'a removed click has no value for a report to count');
        self::assertSame(['lead' => false, 'value' => null], $preview['click_values'][0]['after']);

        $done = $api->undoUpload($batch, [], false)['data'];
        self::assertSame(2, $done['removed']);
        self::assertSame([], $this->liveRows($batch), 'the removed click\'s row went too');
        self::assertSame(0, $this->state(100)['lead']);
    }
}
