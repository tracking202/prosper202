<?php

declare(strict_types=1);

namespace Tests\Attribution;

use Api\V3\Controllers\AttributionController;
use Api\V3\Exception\ConflictException;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Attribution\AttributionReports;
use Prosper202\Attribution\ExportFiles;
use Prosper202\Attribution\ExportRunner;
use Prosper202\Attribution\ExportStore;
use Prosper202\Attribution\ModelType;
use Prosper202\Attribution\WebhookGuard;
use Prosper202\Attribution\WebhookSender;
use Tests\Attribution\Support\AttributionDatabase;

/**
 * The export pipeline over real credits in a scratch database: an export
 * created through the API controller, run by the real runner, written to
 * disk, read back through the download, and delivered to a webhook (a
 * recording transport behind the real guard and sender); retries and their
 * limit; a malformed job failing alone; schedules; the stale-run reclaim;
 * claims that cannot be shared; and the files going with their rows.
 *
 * @group integration
 */
final class AttributionExportsIntegrationTest extends TestCase
{
    use AttributionDatabase {
        setUpBeforeClass as private databaseSetUpBeforeClass;
    }

    private static string $dir;
    private int $now;
    /** @var list<array{options: array<int, mixed>}> */
    private array $deliveries = [];
    /** @var list<array{status?: int, error?: string|null}> */
    private array $answers = [];

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/p202-export-itest-' . bin2hex(random_bytes(4));
        if (!defined('P202_EXPORT_DIR')) {
            define('P202_EXPORT_DIR', self::$dir);
        }
        self::$dir = (string) constant('P202_EXPORT_DIR');
        self::databaseSetUpBeforeClass();
    }

    private function api(): AttributionController
    {
        return new AttributionController(self::$db, 1);
    }

    private function runner(): ExportRunner
    {
        $guard = new WebhookGuard(static fn (string $host): array => ['hooks.example.com' => ['93.184.216.34'], 'rebind.example.com' => ['10.0.0.9']][$host] ?? [], '');
        $sender = new WebhookSender($guard, function (array $options): array {
            $this->deliveries[] = ['options' => $options];
            $answer = array_shift($this->answers) ?? ['status' => 200];

            return $answer + ['status' => 200, 'error' => null, 'primary_ip' => '93.184.216.34', 'location' => null, 'body' => ''];
        });

        return new ExportRunner($this->conn, new ExportFiles(self::$dir), $sender, fn (): int => $this->now);
    }

    /** One person over two campaigns converting for $12; a stranger converting for $3. */
    private function scenario(): void
    {
        $now = time();
        $this->campaign(1);
        $this->campaign(2);
        $this->click(1, 1, $now - 3 * 86400, '0.50');
        $this->click(2, 2, $now - 2 * 86400, '0.25');
        $this->click(3, 1, $now - 3600, '0.50');
        foreach ([1, 2, 3] as $c) {
            $this->visit($c, (int) self::scalar("SELECT click_time FROM 202_clicks WHERE click_id=$c"), self::cookie('p'));
        }
        $this->click(4, 2, $now - 1800, '0.25');
        $this->visit(4, $now - 1800, self::cookie('stranger'));
        $this->convert(3, '12.00', 'A');
        $this->convert(4, '3.00', 'B');
        $this->work();
        // The runner's clock runs a little ahead of the wall clock, so a job
        // the API queues "now" is due to it whatever second the test is in.
        $this->now = time() + 5;
    }

    private static function row(int $id): array
    {
        return self::all("SELECT * FROM 202_attribution_exports WHERE export_id=$id")[0];
    }

    public function testAnExportWritesTheWholeBreakdownAndTheDownloadReturnsIt(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->work();
        $created = $this->api()->createExport(['group_by' => 'campaign', 'model_id' => $linear, 'compare_model_id' => $this->defaultModelId()])['data'];
        self::assertSame('pending', $created['status']);
        self::assertFalse($created['file_ready']);
        self::assertArrayNotHasKey('webhook_secret', $created, 'no webhook, no secret');

        $report = $this->runner()->run(10);
        self::assertSame(1, $report['completed']);

        $row = self::row($created['export_id']);
        self::assertSame('completed', $row['status']);
        self::assertTrue(ExportFiles::isName((string) $row['file_path']));
        self::assertSame('2', (string) $row['rows_exported']);
        self::assertSame([], $this->deliveries);

        $file = $this->api()->downloadExport($created['export_id'])['_file'];
        $lines = array_map(static fn (string $l): array => str_getcsv($l, ',', '"', ''), explode("\n", rtrim($file['body'], "\n")));
        self::assertSame(['key', 'name', 'clicks', 'cost', 'attributed_conversions', 'attributed_revenue', 'roi', 'assisted_conversions',
            'compare_attributed_conversions', 'compare_attributed_revenue', 'compare_roi'], $lines[0]);
        $byKey = [];
        foreach (array_slice($lines, 1) as $l) {
            $byKey[$l[0]] = array_combine($lines[0], $l);
        }
        // Linear gives campaign 1 two thirds of $12 and campaign 2 a third
        // plus the stranger's $3; last touch gives campaign 1 all $12.
        self::assertSame('8.00000', $byKey['1']['attributed_revenue']);
        self::assertSame('7.00000', $byKey['2']['attributed_revenue']);
        self::assertSame('12.00000', $byKey['1']['compare_attributed_revenue']);
        self::assertSame('3.00000', $byKey['2']['compare_attributed_revenue']);
        self::assertSame('1.00000', $byKey['1']['cost'], "campaign 1's own two clicks");
        self::assertSame('Campaign 1', $byKey['1']['name']);

        // The file is the report: the same rows breakdownAll() computes.
        $all = (new AttributionReports($this->conn))->breakdownAll(1, $linear, $this->defaultModelId(), $this->defaultModelId(), 'campaign', (int) $row['range_start'], (int) $row['range_end']);
        self::assertCount(count($all['rows']), array_slice($lines, 1));
        self::assertSame('attribution-campaign-export-' . $created['export_id'] . '.csv', $file['filename']);
    }

    public function testAWebhookReceivesTheSignedFile(): void
    {
        $this->scenario();
        $created = $this->api()->createExport(['group_by' => 'traffic_source', 'webhook_url' => 'https://93.184.216.34/p202', 'webhook_secret' => 'a-shared-secret-of-some-length'])['data'];
        self::assertSame('a-shared-secret-of-some-length', $created['webhook_secret'], 'returned once, on create');
        self::assertArrayNotHasKey('webhook_secret', $this->api()->getExport($created['export_id'])['data'], 'and never again');
        self::assertTrue($this->api()->getExport($created['export_id'])['data']['webhook_signed']);
        // A host name, as a row saved earlier would carry: the runner
        // resolves it again and pins the connection to the answer.
        self::$db->query("UPDATE 202_attribution_exports SET webhook_url='https://hooks.example.com/p202' WHERE export_id=" . (int) $created['export_id']);

        $this->answers = [['status' => 202]];
        $this->runner()->run(10);

        $row = self::row($created['export_id']);
        self::assertSame('completed', $row['status']);
        self::assertSame('202', (string) $row['webhook_status_code']);
        self::assertCount(1, $this->deliveries);
        $options = $this->deliveries[0]['options'];
        self::assertSame(['hooks.example.com:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
        $body = $options[CURLOPT_POSTFIELDS];
        self::assertSame((new ExportFiles(self::$dir))->read((string) $row['file_path']), $body, 'the webhook carries the file');
        $headers = implode("\n", $options[CURLOPT_HTTPHEADER]);
        self::assertSame(1, preg_match('/X-P202-Timestamp: (\d+)/', $headers, $t));
        self::assertStringContainsString('X-P202-Signature: sha256=' . hash_hmac('sha256', $t[1] . '.' . $body, 'a-shared-secret-of-some-length'), $headers);
    }

    public function testAGeneratedSecretIsLongAndRandom(): void
    {
        $this->scenario();
        $a = $this->api()->createExport(['webhook_url' => 'https://8.8.8.8/hook'])['data']['webhook_secret'];
        $b = $this->api()->createExport(['webhook_url' => 'https://8.8.8.8/hook'])['data']['webhook_secret'];
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        self::assertNotSame($a, $b);
    }

    public function testARetryableFailureIsRetriedThenGivesUpAndTheFileStays(): void
    {
        $this->scenario();
        $id = $this->api()->createExport(['webhook_url' => 'https://93.184.216.34/p202'])['data']['export_id'];

        $this->answers = [['status' => 503]];
        self::assertSame(1, $this->runner()->run(10)['retrying']);
        $row = self::row($id);
        self::assertSame('pending', $row['status']);
        self::assertSame('1', (string) $row['attempts']);
        self::assertSame($this->now + 60, (int) $row['queued_at'], 'tried again after a minute');
        self::assertStringContainsString('answered 503', (string) $row['last_error']);
        $firstFile = (string) $row['file_path'];

        // Not due yet: nothing runs.
        self::assertSame(0, array_sum($this->runner()->run(10)));
        $this->now += 61;
        $this->answers = [['status' => 0, 'error' => 'Connection timed out']];
        $this->runner()->run(10);
        $row = self::row($id);
        self::assertSame('2', (string) $row['attempts']);
        self::assertSame($this->now + 120, (int) $row['queued_at'], 'then after two');
        self::assertFileDoesNotExist(self::$dir . '/' . $firstFile, 'a retry replaces the file it wrote before');

        $this->now += 121;
        $this->answers = [['status' => 500]];
        $this->runner()->run(10);
        $row = self::row($id);
        self::assertSame('failed', $row['status'], 'three attempts in all');
        self::assertSame('3', (string) $row['attempts']);
        self::assertStringContainsString('The file is ready to download', (string) $row['last_error']);
        self::assertNotNull($this->api()->downloadExport($id)['_file']['body']);
        self::assertCount(3, $this->deliveries);

        // A person can queue it again.
        $again = $this->api()->retryExport($id)['data'];
        self::assertSame('pending', $again['status']);
        self::assertSame(0, $again['attempts']);
        $this->expectException(ConflictException::class);
        $this->api()->retryExport($id);
    }

    public function testARefusedDestinationFailsAtOnce(): void
    {
        $this->scenario();
        // Public when saved; it resolves to 10.0.0.9 by the time it runs.
        $store = new ExportStore($this->conn);
        $id = $store->insert(1, $this->defaultModelId(), null, 'campaign', $this->now - 86400, $this->now, 'https://rebind.example.com/h', 'secretsecretsecret', $this->now);
        $this->runner()->run(10);
        $row = self::row($id);
        self::assertSame('failed', $row['status']);
        self::assertSame('1', (string) $row['attempts']);
        self::assertStringContainsString('resolves to 10.0.0.9, which is a private address', (string) $row['last_error']);
        self::assertSame([], $this->deliveries, 'nothing was sent');
    }

    public function testAMalformedJobFailsAloneAndTheRunCarriesOn(): void
    {
        $this->scenario();
        $store = new ExportStore($this->conn);
        $bad = $store->insert(1, $this->defaultModelId(), null, 'planet', $this->now - 86400, $this->now, null, null, $this->now - 10);
        $gone = $store->insert(1, 999999, null, 'campaign', $this->now - 86400, $this->now, null, null, $this->now - 5);
        $backwards = $store->insert(1, $this->defaultModelId(), null, 'campaign', $this->now, $this->now - 86400, null, null, $this->now - 3);
        $good = $this->api()->createExport([])['data']['export_id'];

        $report = $this->runner()->run(10);
        self::assertSame(['completed' => 1, 'failed' => 3, 'retrying' => 0, 'reclaimed' => 0], $report);
        self::assertStringContainsString('"planet", which is not a report dimension', (string) self::row($bad)['last_error']);
        self::assertStringContainsString('Model 999999 no longer exists', (string) self::row($gone)['last_error']);
        self::assertStringContainsString('starts after it ends', (string) self::row($backwards)['last_error']);
        self::assertSame('completed', self::row($good)['status']);
    }

    public function testAnInactiveModelFailsItsJobWithTheReason(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $id = $this->api()->createExport(['model_id' => $linear])['data']['export_id'];
        $this->api()->updateModel($linear, ['status' => 'inactive']);
        $this->runner()->run(10);
        self::assertStringContainsString('is inactive, so it has no credits', (string) self::row($id)['last_error']);
    }

    public function testAScheduledExportWaitsForItsTime(): void
    {
        $this->scenario();
        $at = $this->now + 3600;
        $created = $this->api()->createExport(['run_at' => $at])['data'];
        self::assertSame($at, $created['run_at']);
        self::assertSame(0, $this->runner()->run(10)['completed']);
        $this->now = $at;
        self::assertSame(1, $this->runner()->run(10)['completed']);
    }

    public function testAClaimIsTakenOnceAndAStoppedRunIsReclaimed(): void
    {
        $this->scenario();
        $id = $this->api()->createExport([])['data']['export_id'];
        $store = new ExportStore($this->conn);
        self::assertTrue($store->claim($id, $this->now));
        self::assertFalse($store->claim($id, $this->now), 'a second runner cannot take it');
        self::assertSame(0, $this->runner()->run(10)['completed'], 'nor does the runner, while it is running');

        // The runner that took it died: after STALE_AFTER it is pending again.
        $this->now += ExportRunner::STALE_AFTER + 1;
        $report = $this->runner()->run(10);
        self::assertSame(1, $report['reclaimed']);
        self::assertSame(1, $report['completed']);
        self::assertSame('2', (string) self::row($id)['attempts']);
    }

    public function testDeletingAnExportOrItsModelRemovesTheFile(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $first = $this->api()->createExport([])['data']['export_id'];
        $asCompare = $this->api()->createExport(['compare_model_id' => $linear])['data']['export_id'];
        $asModel = $this->api()->createExport(['model_id' => $linear])['data']['export_id'];
        $this->work();
        $this->runner()->run(10);
        $files = [];
        foreach ([$first, $asCompare, $asModel] as $id) {
            $files[$id] = self::$dir . '/' . self::row($id)['file_path'];
            self::assertFileExists($files[$id]);
        }

        $this->api()->deleteExport($first);
        self::assertFileDoesNotExist($files[$first]);
        self::assertSame('0', (string) self::scalar("SELECT COUNT(*) FROM 202_attribution_exports WHERE export_id=$first"));

        $preview = $this->api()->deleteModelPreview($linear)['data'];
        self::assertSame(2, $preview['cascade'][1]['count'], 'the preview counts exports that compare with the model too');
        $this->api()->deleteModel($linear);
        self::assertFileDoesNotExist($files[$asCompare]);
        self::assertFileDoesNotExist($files[$asModel]);
        self::assertSame('0', (string) self::scalar('SELECT COUNT(*) FROM 202_attribution_exports'));
    }

    public function testARunningExportCannotBeDeleted(): void
    {
        $this->scenario();
        $id = $this->api()->createExport([])['data']['export_id'];
        (new ExportStore($this->conn))->claim($id, $this->now);
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('is running');
        $this->api()->deleteExport($id);
    }

    /** @return iterable<string, array{0: array<string, mixed>, 1: string}> */
    public static function refusedCreates(): iterable
    {
        yield 'model id as a string' => [['model_id' => '1'], 'model_id'];
        yield 'unknown field' => [['format' => 'xls'], 'format'];
        yield 'unknown dimension' => [['group_by' => 'planet'], 'group_by'];
        yield 'loopback webhook' => [['webhook_url' => 'https://127.0.0.1/h'], 'webhook_url'];
        yield 'metadata webhook' => [['webhook_url' => 'https://169.254.169.254/latest'], 'webhook_url'];
        yield 'private webhook' => [['webhook_url' => 'https://10.1.2.3/h'], 'webhook_url'];
        yield 'plain http webhook' => [['webhook_url' => 'http://8.8.8.8/h'], 'webhook_url'];
        yield 'decimal-spelled webhook' => [['webhook_url' => 'https://2130706433/h'], 'webhook_url'];
        yield 'secret without webhook' => [['webhook_secret' => 'abcdefghijklmnopqrstuvwxyz'], 'webhook_secret'];
        yield 'short secret' => [['webhook_url' => 'https://8.8.8.8/h', 'webhook_secret' => 'short'], 'webhook_secret'];
        yield 'run_at as a string' => [['run_at' => '1790000000'], 'run_at'];
        yield 'run_at years ahead' => [['run_at' => PHP_INT_MAX], 'run_at'];
        yield 'time as a string' => [['time_from' => '0'], 'time_from'];
        yield 'bad period' => [['period' => 'forever'], 'period'];
    }

    /**
     * @dataProvider refusedCreates
     * @param array<string, mixed> $payload
     */
    public function testACreateTheApiWouldRefuseIsRefusedByField(array $payload, string $field): void
    {
        $this->scenario();
        try {
            $this->api()->createExport($payload);
            self::fail('accepted');
        } catch (ValidationException $e) {
            self::assertArrayHasKey($field, $e->getFieldErrors(), $e->getMessage());
        }
        self::assertSame('0', (string) self::scalar('SELECT COUNT(*) FROM 202_attribution_exports'), 'and nothing was written');
    }

    public function testAnInactiveOrMissingModelIsRefusedAtCreate(): void
    {
        $this->scenario();
        $linear = $this->addModel('Linear', ModelType::LINEAR);
        $this->api()->updateModel($linear, ['status' => 'inactive']);
        try {
            $this->api()->createExport(['model_id' => $linear]);
            self::fail('accepted an inactive model');
        } catch (ConflictException $e) {
            self::assertStringContainsString('inactive', $e->getMessage());
        }
        $this->expectException(ValidationException::class);
        $this->api()->createExport(['model_id' => $this->defaultModelId(), 'compare_model_id' => $this->defaultModelId()]);
    }

    public function testASpreadsheetFormulaInADimensionIsWrittenAsText(): void
    {
        $this->scenario();
        self::$db->query("UPDATE 202_aff_campaigns SET aff_campaign_name='=HYPERLINK(\"http://evil.example\",\"x\")' WHERE aff_campaign_id=1");
        $id = $this->api()->createExport([])['data']['export_id'];
        $this->runner()->run(10);
        $body = $this->api()->downloadExport($id)['_file']['body'];
        self::assertStringContainsString("\"'=HYPERLINK(\"\"http://evil.example\"\",\"\"x\"\")\"", $body);
    }
}
