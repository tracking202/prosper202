<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Lifecycle\UnknownState;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\MysqlCustomerRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;
use Prosper202\Ltv\MysqlWebhookRepository;
use Prosper202\Ltv\WebhookDispatcher;

/**
 * The moves the LTV lifecycles refuse and the ones they declare, on a real
 * server: a delivery's outcome recorded after someone else moved it, a
 * webhook whose stored status no case names, a repeat cancel, and a
 * canceled subscription the billing system reactivates.
 *
 * @group integration
 */
final class LifecycleWritesIntegrationTest extends TestCase
{
    private const USER = 990871;
    private const URL = 'https://hooks.example.com/ltv';

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) {'
                . ' return $sql === null ? null : $dbOrSql->query($sql); }');
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
        $db->query("SET SESSION sql_mode='ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,"
            . "NO_ENGINE_SUBSTITUTION'");
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::clean();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::clean();
    }

    private static function clean(): void
    {
        self::q('DROP TRIGGER IF EXISTS p202_test_duplicate_once');
        foreach ([
            '202_ltv_webhooks', '202_ltv_webhook_deliveries', '202_subscriptions', '202_customers',
            '202_customer_aliases', '202_revenue_events',
        ] as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
    }

    private static function q(string $sql): void
    {
        self::assertNotNull(self::$db);
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);
    }

    /** @return array<string, string|null> */
    private static function row(string $sql): array
    {
        $result = self::$db?->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, 'no row: ' . $sql);

        return $row;
    }

    private static function conn(): Connection
    {
        self::assertNotNull(self::$db);

        return new Connection(self::$db);
    }

    private static function webhooks(): MysqlWebhookRepository
    {
        return new MysqlWebhookRepository(self::conn());
    }

    /** A webhook with one pending delivery: [webhook id, delivery id]. */
    private static function queued(): array
    {
        $hook = self::webhooks()->create(self::USER, self::URL, ['revenue.recorded']);
        self::assertSame(1, self::webhooks()->enqueue(self::USER, 'revenue.recorded', ['revenue_event_id' => 1]));
        $delivery = self::row('SELECT delivery_id FROM 202_ltv_webhook_deliveries');

        return [$hook['webhookId'], (int) $delivery['delivery_id']];
    }

    /** @return array<string, string|null> */
    private static function delivery(int $id): array
    {
        return self::row('SELECT status, attempts, last_response_body FROM 202_ltv_webhook_deliveries'
            . ' WHERE delivery_id = ' . $id);
    }

    private static function webhookStatus(int $id): ?string
    {
        return self::row('SELECT status FROM 202_ltv_webhooks WHERE webhook_id = ' . $id)['status'];
    }

    public function testAFailureRecordedLateDoesNotTakeADeliveredDeliveryBack(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::webhooks()->recordAttempt($deliveryId, $webhookId, true, 200, 'ok');
        // A run whose claim lapsed while another delivered it, recording its own timeout.
        self::webhooks()->recordAttempt($deliveryId, $webhookId, false, null, 'curl: timed out');

        self::assertSame(
            ['status' => 'delivered', 'attempts' => '1', 'last_response_body' => 'ok'],
            self::delivery($deliveryId),
            'delivered is final: pending again, it would be sent a second time'
        );
    }

    public function testAFailureRecordedLateOnAnExpiredDeliveryNeitherRequeuesItNorKillsItsWebhook(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::q('UPDATE 202_ltv_webhook_deliveries SET attempts = ' . (MysqlWebhookRepository::MAX_ATTEMPTS - 1)
            . ', created_at = ' . (time() - 8 * 86400) . ' WHERE delivery_id = ' . $deliveryId);
        self::assertSame(1, self::webhooks()->expireUnsent(time(), time() - 7 * 86400, WebhookDispatcher::EXPIRED_REASON, 10));

        self::webhooks()->recordAttempt($deliveryId, $webhookId, false, 500, 'late');

        self::assertSame(
            [
                'status' => 'failed',
                'attempts' => (string) (MysqlWebhookRepository::MAX_ATTEMPTS - 1),
                'last_response_body' => WebhookDispatcher::EXPIRED_REASON,
            ],
            self::delivery($deliveryId)
        );
        self::assertSame('active', self::webhookStatus($webhookId), 'the expiry says the endpoint did nothing wrong');
    }

    public function testASuccessRecordedLateLeavesAHeldDeliveryForItsRevival(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::q("UPDATE 202_ltv_webhooks SET status = 'dead' WHERE webhook_id = " . $webhookId);
        self::assertTrue(self::webhooks()->holdDelivery($deliveryId, time()));

        self::webhooks()->recordAttempt($deliveryId, $webhookId, true, 200, 'ok');

        self::assertSame('failed', self::delivery($deliveryId)['status']);
        self::assertSame(MysqlWebhookRepository::HELD_REASON, self::delivery($deliveryId)['last_response_body']);
    }

    public function testAnAttemptRecordedOnAPendingDeliverySaysItWasRecorded(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::assertTrue(self::webhooks()->recordAttempt($deliveryId, $webhookId, false, 500, 'no'));
        self::assertSame(['status' => 'pending', 'attempts' => '1', 'last_response_body' => 'no'], self::delivery($deliveryId));
        self::assertTrue(self::webhooks()->recordAttempt($deliveryId, $webhookId, true, 200, 'ok'));
        self::assertFalse(self::webhooks()->recordAttempt($deliveryId, $webhookId, true, 200, 'again'));
        self::assertSame(['status' => 'delivered', 'attempts' => '2', 'last_response_body' => 'ok'], self::delivery($deliveryId));
    }

    public function testTheLastFailedAttemptStillMarksItsWebhookDead(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::q('UPDATE 202_ltv_webhook_deliveries SET attempts = ' . (MysqlWebhookRepository::MAX_ATTEMPTS - 1)
            . ' WHERE delivery_id = ' . $deliveryId);
        self::webhooks()->recordAttempt($deliveryId, $webhookId, false, 500, 'no');

        self::assertSame('failed', self::delivery($deliveryId)['status']);
        self::assertSame('dead', self::webhookStatus($webhookId));
    }

    public function testARunThatComesBackLateLeavesTheRowAndSaysSo(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        $log = tempnam(sys_get_temp_dir(), 'p202-lifecycle-log');
        self::assertIsString($log);
        $before = (string) ini_get('error_log');
        ini_set('error_log', $log);
        try {
            // Another run delivers it while this one's request is out.
            $report = WebhookDispatcher::runExclusive(
                self::conn(),
                time() + 30,
                static function (array $delivery) use ($webhookId): array {
                    self::webhooks()->recordAttempt((int) $delivery['delivery_id'], $webhookId, true, 200, 'ok');

                    return [false, null, 'curl: timed out'];
                }
            );
        } finally {
            ini_set('error_log', $before);
        }
        $logged = (string) file_get_contents($log);
        unlink($log);

        self::assertNotNull($report);
        self::assertSame([0, 1], [$report['delivered'], $report['failed']], 'counted by what this run\'s request did');
        self::assertSame(
            ['status' => 'delivered', 'attempts' => '1', 'last_response_body' => 'ok'],
            self::delivery($deliveryId)
        );
        self::assertSame('active', self::webhookStatus($webhookId));
        self::assertStringContainsString(
            'delivery ' . $deliveryId . ' to webhook ' . $webhookId . ' was tried but was no longer pending',
            $logged
        );
    }

    public function testARevivalRefusesAStatusTheLifecycleDoesNotName(): void
    {
        [$webhookId, $deliveryId] = self::queued();
        self::q("UPDATE 202_ltv_webhooks SET status = 'disabled' WHERE webhook_id = " . $webhookId);

        try {
            self::webhooks()->revive(self::USER, $webhookId, true, time());
            self::fail('a webhook in no known state was revived');
        } catch (UnknownState $e) {
            self::assertStringContainsString('webhook ' . $webhookId, $e->getMessage());
            self::assertStringContainsString('"disabled"', $e->getMessage());
        }
        self::assertSame('disabled', self::webhookStatus($webhookId));
        self::assertSame('pending', self::delivery($deliveryId)['status']);
        self::assertSame(
            0,
            self::webhooks()->enqueue(self::USER, 'revenue.recorded', ['revenue_event_id' => 2]),
            'and nothing is queued for it'
        );
    }

    private static function subscriptions(): MysqlSubscriptionRepository
    {
        return new MysqlSubscriptionRepository(self::conn(), new MysqlCustomerRepository(self::conn()));
    }

    private static function customer(): int
    {
        $customers = new MysqlCustomerRepository(self::conn());

        return (new MysqlCustomerCrmRepository(self::conn(), $customers, new MysqlCustomerFieldRepository(self::conn())))
            ->upsert(self::USER, ['customer_ref' => 'lifecycle-1']);
    }

    /** @return array<string, string|null> */
    private static function subscription(string $externalId): array
    {
        return self::row("SELECT status, canceled_at, updated_at, mrr FROM 202_subscriptions WHERE external_sub_id = '"
            . $externalId . "' AND user_id = " . self::USER);
    }

    public function testARepeatCancelWritesNothing(): void
    {
        $customerId = self::customer();
        self::subscriptions()->upsert(self::USER, ['external_sub_id' => 'S1', 'amount' => 10.0, 'customer_id' => $customerId]);
        $first = self::subscriptions()->recordEvent(self::USER, 'S1', 'cancel', ['occurred_at' => 1700000000]);
        self::assertTrue($first['changed']);
        self::q("UPDATE 202_subscriptions SET updated_at = 1700000001 WHERE external_sub_id = 'S1'");

        $again = self::subscriptions()->recordEvent(self::USER, 'S1', 'cancel', ['occurred_at' => 1700009999]);

        self::assertFalse($again['changed']);
        self::assertSame(
            ['status' => 'canceled', 'canceled_at' => '1700000000', 'updated_at' => '1700000001', 'mrr' => '10.00000'],
            self::subscription('S1'),
            'changed: false means the row is as it was, its place in the list (newest change first) too'
        );
    }

    public function testACanceledSubscriptionIsReactivatedByARenewalAndByAnUpsert(): void
    {
        $customerId = self::customer();
        foreach (['R1', 'U1'] as $id) {
            self::subscriptions()->upsert(self::USER, ['external_sub_id' => $id, 'amount' => 10.0, 'customer_id' => $customerId]);
            self::subscriptions()->recordEvent(self::USER, $id, 'cancel', []);
            self::assertNotNull(self::subscription($id)['canceled_at']);
        }

        $renewal = self::subscriptions()->recordEvent(self::USER, 'R1', 'renewal', ['transaction_id' => 'ch_1']);
        self::subscriptions()->upsert(self::USER, [
            'external_sub_id' => 'U1', 'amount' => 10.0, 'customer_id' => $customerId, 'status' => 'paused',
        ]);

        self::assertTrue($renewal['changed']);
        self::assertSame(['active', null], [self::subscription('R1')['status'], self::subscription('R1')['canceled_at']]);
        self::assertSame(['paused', null], [self::subscription('U1')['status'], self::subscription('U1')['canceled_at']]);
        self::assertSame(
            '1',
            self::row('SELECT active_subscription_count AS n FROM 202_customers WHERE customer_id = ' . $customerId)['n']
        );
    }

    public function testAnUpsertKeepsWhatItDoesNotStateAndStampsACancelOnce(): void
    {
        $customerId = self::customer();
        $created = self::subscriptions()->upsert(self::USER, [
            'external_sub_id' => 'K1', 'amount' => 10.0, 'customer_id' => $customerId, 'started_at' => 1600000000,
            'status' => 'canceled',
        ]);
        self::q("UPDATE 202_subscriptions SET canceled_at = 1650000000, created_at = 1600000005 WHERE external_sub_id = 'K1'");

        $updated = self::subscriptions()->upsert(self::USER, [
            'external_sub_id' => 'K1', 'amount' => 30.0, 'customer_id' => $customerId, 'started_at' => 1700000000,
            'status' => 'canceled', 'plan_name' => 'Pro',
        ]);

        self::assertSame($created['subscriptionId'], $updated['subscriptionId']);
        self::assertSame(
            [
                'status' => 'canceled', 'canceled_at' => '1650000000', 'started_at' => '1600000000',
                'created_at' => '1600000005', 'plan_name' => 'Pro', 'amount' => '30.00000', 'mrr' => '30.00000',
            ],
            self::row("SELECT status, canceled_at, started_at, created_at, plan_name, amount, mrr FROM 202_subscriptions"
                . " WHERE external_sub_id = 'K1'")
        );
        self::assertSame('1', self::row('SELECT COUNT(*) AS n FROM 202_subscriptions')['n']);
    }

    /**
     * Under READ COMMITTED the locking read of a subscription not there yet
     * holds no gap, so another request can create it before this one's
     * INSERT: the duplicate is this request's cue to read again and update.
     */
    public function testAnUpsertThatLosesTheRaceToCreateRunsAgain(): void
    {
        $customerId = self::customer();
        self::q("CREATE TRIGGER p202_test_duplicate_once BEFORE INSERT ON 202_subscriptions FOR EACH ROW
                 BEGIN
                     IF @p202_test_duplicate = 1 THEN
                         SET @p202_test_duplicate = 0;
                         SIGNAL SQLSTATE '23000' SET MYSQL_ERRNO = 1062, MESSAGE_TEXT = 'Duplicate entry (test)';
                     END IF;
                 END");
        self::q('SET @p202_test_duplicate = 1');

        $result = self::subscriptions()->upsert(self::USER, [
            'external_sub_id' => 'D1', 'amount' => 10.0, 'customer_id' => $customerId, 'status' => 'trialing',
        ]);

        self::assertSame('0', self::row('SELECT @p202_test_duplicate AS n')['n'], 'the first INSERT met the duplicate');
        self::assertGreaterThan(0, $result['subscriptionId']);
        self::assertSame('trialing', self::subscription('D1')['status']);
        self::assertSame('1', self::row('SELECT COUNT(*) AS n FROM 202_subscriptions')['n']);
    }
}
