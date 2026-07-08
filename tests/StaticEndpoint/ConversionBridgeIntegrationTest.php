<?php

declare(strict_types=1);

namespace Tests\StaticEndpoint;

use PHPUnit\Framework\TestCase;
use Prosper202\Bridge\EventBridge;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * End-to-end verification of the conversion.recorded bridge emit (E.3)
 * against a REAL MySQL/MariaDB database: the emit rides
 * p202RecordConversion()'s post-commit block, so replay idempotency and the
 * subscribe-all ('') wildcard matching can only be proven with the actual
 * transactional writer and delivery queue.
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class ConversionBridgeIntegrationTest extends TestCase
{
    private const INSTALL_HASH = 'ih-bridge-test';

    private static ?\mysqli $db = null;
    private static ?Connection $conn = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return; // no DB configured; individual tests will skip
        }

        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
        }

        require_once __DIR__ . '/../../202-config/static-endpoint-helpers.php';

        // Match production error mode (connect2.php) so behaviour is realistic.
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
        self::$db = $db;
        self::$conn = new Connection($db);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db) {
            self::$db->close();
            self::$db = null;
            self::$conn = null;
        }
    }

    protected function setUp(): void
    {
        if (!self::$db) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        foreach ([
            '202_conversion_logs', '202_clicks', '202_clicks_spy', '202_clicks_tracking',
            '202_customers', '202_revenue_events', '202_revenue_line_items',
            '202_ltv_webhooks', '202_ltv_webhook_deliveries', '202_users',
        ] as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
        self::$db->query("INSERT INTO 202_users SET user_id=1, user_name='bridge-test', user_pass='x', install_hash='" . self::INSTALL_HASH . "'");
    }

    private function insertClick(int $clickId, float $payout = 10.0): void
    {
        $db = self::$db;
        $db->query("INSERT INTO 202_clicks SET click_id=$clickId, user_id=1, aff_campaign_id=7, click_payout=$payout, click_cpc=0, click_lead=0, click_time=1700000000");
        $db->query("INSERT INTO 202_clicks_spy SET click_id=$clickId, user_id=1, aff_campaign_id=7, click_payout=$payout, click_cpc=0, click_lead=0, click_time=1700000000");
    }

    /** @return array<string, mixed> */
    private function log(int $clickId): array
    {
        return [
            'click_id' => $clickId, 'campaign_id' => '7', 'user_id' => '1',
            'click_time' => 1700000000, 'conv_time' => 1700000100,
            'time_difference' => '0 days, 0 hours, 1 min and 40 sec',
            'ip' => '203.0.113.9', 'pixel_type' => 3,
            'user_agent' => 'BridgeIntegrationTest/1.0', 'click_payout' => '10.0',
        ];
    }

    /** @return list<array<string, mixed>> */
    private function deliveries(): array
    {
        $rows = [];
        $res = self::$db->query('SELECT * FROM 202_ltv_webhook_deliveries ORDER BY delivery_id ASC');
        if ($res instanceof \mysqli_result) {
            while ($row = $res->fetch_assoc()) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    private function subscribeWildcard(int $userId = 1): int
    {
        $created = (new MysqlWebhookRepository(self::$conn))
            ->create($userId, 'https://8.8.8.8/v1/hooks/p202?site=pk_live_test', ['*']);

        return $created['webhookId'];
    }

    public function testNewConversionQueuesExactlyOneVersionedEnvelopeDelivery(): void
    {
        $webhookId = $this->subscribeWildcard();

        // create(['*']) must have stored the '' subscribe-all sentinel.
        $hook = self::$db->query('SELECT subscribed_events FROM 202_ltv_webhooks WHERE webhook_id=' . $webhookId)->fetch_assoc();
        self::assertSame('', $hook['subscribed_events']);

        $this->insertClick(9000);
        $result = p202RecordConversion(self::$db, $this->log(9000), '', true, '10.0', 'TX-1');
        self::assertFalse($result['duplicate']);

        $deliveries = $this->deliveries();
        self::assertCount(1, $deliveries, 'exactly one delivery row per new conversion');
        self::assertSame('conversion.recorded', $deliveries[0]['event_name']);
        self::assertSame($webhookId, (int) $deliveries[0]['webhook_id']);
        self::assertSame(1, (int) $deliveries[0]['user_id']);
        self::assertSame('pending', $deliveries[0]['status']);

        $body = (string) $deliveries[0]['payload'];
        $wire = json_decode($body, true);
        self::assertSame('conversion.recorded', $wire['event'], 'wire body keeps the dispatcher contract {event, occurred_at, data}');
        self::assertIsInt($wire['occurred_at']);

        $envelope = $wire['data'];
        self::assertSame(EventBridge::BRIDGE_VERSION, $envelope['bridge_version']);
        self::assertSame(self::INSTALL_HASH, $envelope['install']['install_hash']);
        self::assertSame(1, $envelope['install']['user_id']);
        self::assertIsString($envelope['install']['p202_version']);
        self::assertStringContainsString('"ext":{}', $body, 'ext must be a JSON object even when empty');

        $payload = $envelope['payload'];
        self::assertSame('9000:TX-1', $payload['idempotency_key']);
        self::assertSame(9000, $payload['click_id']);
        self::assertSame('TX-1', $payload['transaction_id']);
        self::assertSame((int) $result['conv_id'], $payload['conv_id']);
        self::assertSame(7, $payload['campaign_id']);
        self::assertSame(10.0, (float) $payload['payout']);
        self::assertSame(1700000100, $payload['conv_time']);
        self::assertSame(1700000000, $payload['click_time']);
        self::assertSame(3, $payload['pixel_type']);
        self::assertSame('203.0.113.9', $payload['ip']);
    }

    public function testDuplicateReplayDoesNotQueueASecondDelivery(): void
    {
        $this->subscribeWildcard();
        $this->insertClick(9100);

        $first = p202RecordConversion(self::$db, $this->log(9100), '', true, '10.0', 'TX-DUP');
        $replay = p202RecordConversion(self::$db, $this->log(9100), '', true, '10.0', 'TX-DUP');

        self::assertFalse($first['duplicate']);
        self::assertTrue($replay['duplicate']);
        self::assertCount(1, $this->deliveries(), 'a replayed postback must not re-emit');
    }

    public function testNoSubscriberMeansNoDeliveryRow(): void
    {
        $this->insertClick(9200);
        $result = p202RecordConversion(self::$db, $this->log(9200), '', true, '10.0', 'TX-2');

        self::assertFalse($result['duplicate']);
        self::assertGreaterThan(0, $result['conv_id'], 'the conversion itself must still record');
        self::assertCount(0, $this->deliveries());
    }

    public function testOtherUsersSubscriptionDoesNotReceiveTheEvent(): void
    {
        $this->subscribeWildcard(2); // a different tenant's wildcard endpoint
        $this->insertClick(9300);

        p202RecordConversion(self::$db, $this->log(9300), '', true, '10.0', 'TX-3');

        self::assertCount(0, $this->deliveries(), 'enqueue is tenant-scoped even for wildcard endpoints');
    }
}
