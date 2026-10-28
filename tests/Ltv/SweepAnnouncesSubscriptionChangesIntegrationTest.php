<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\LtvMaintenance;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * The subscription sweep queues the subscription.changed event for each
 * status it writes, as the API's change of that status does: compared on
 * twin subscriptions, one moved by the sweep and one by the API, through
 * the real LtvController. Queued in the sweep's own transaction (a batch
 * rolled back queues nothing), and only for a change that took effect in
 * the last EVENT_WINDOW_SECONDS. Every API status change queued it and the
 * sweep queued none, so a subscriber never heard that a subscription lapsed.
 *
 * The sweep reads every account's rows, so its tables are emptied for each
 * test.
 *
 * @group integration
 */
final class SweepAnnouncesSubscriptionChangesIntegrationTest extends TestCase
{
    private const USER = 990911;
    private const DAY = 86400;

    private const TABLES = [
        '202_customers', '202_subscriptions', '202_revenue_events', '202_ltv_webhooks', '202_ltv_webhook_deliveries',
    ];

    private static ?\mysqli $db = null;

    private int $hook = 0;

    private string $log = '';
    private string $errorLogBefore = '';

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
            self::$db->query('DROP TRIGGER IF EXISTS p202_test_refuse_rollup');
            foreach (self::TABLES as $table) {
                self::$db->query('TRUNCATE TABLE ' . $table);
            }
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        self::q('DROP TRIGGER IF EXISTS p202_test_refuse_rollup');
        foreach (self::TABLES as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
        $webhooks = new MysqlWebhookRepository(new Connection(self::$db));
        $this->hook = $webhooks->create(self::USER, 'https://hooks.example/all', ['*'])['webhookId'];
        // Subscribed to another event: the sweep's events are not its.
        $webhooks->create(self::USER, 'https://hooks.example/revenue', ['revenue.recorded']);
        $log = tempnam(sys_get_temp_dir(), 'p202-sweep-events-log');
        self::assertIsString($log);
        $this->log = $log;
        $this->errorLogBefore = (string) ini_get('error_log');
        ini_set('error_log', $log);
    }

    protected function tearDown(): void
    {
        if ($this->log !== '') {
            ini_set('error_log', $this->errorLogBefore);
            if (is_file($this->log)) {
                unlink($this->log);
            }
        }
    }

    public function testEachStatusTheSweepWritesIsQueuedAsTheApisChangeOfItIs(): void
    {
        $now = time();
        $sweepCustomer = self::customer();
        $apiCustomer = self::customer();
        // Twins: lapsed 2 days ago (period end 5 days ago, 3 days' grace)...
        $toPastDue = self::subscription($sweepCustomer, 'active', $now - 5 * self::DAY);
        $twinPastDue = self::subscription($apiCustomer, 'active', $now - 5 * self::DAY);
        // ...and past the cancel point 2 days ago.
        $toCanceled = self::subscription($sweepCustomer, 'active', $now - 35 * self::DAY);
        $twinCanceled = self::subscription($apiCustomer, 'active', $now - 35 * self::DAY);
        // A past_due one past it too.
        $dueToCanceled = self::subscription($sweepCustomer, 'past_due', $now - 34 * self::DAY);

        // The API's change of each status: past_due through POST
        // /ltv/subscriptions (the only API path to it), canceled through the
        // cancel event.
        $api = new LtvController(self::$db ?? throw new \LogicException(), self::USER);
        $twin = self::row('SELECT external_sub_id, current_period_start, current_period_end FROM 202_subscriptions'
            . ' WHERE subscription_id = ' . $twinPastDue);
        $api->upsertSubscription([
            'external_sub_id' => $twin['external_sub_id'], 'customer_id' => $apiCustomer, 'amount' => 10,
            'status' => 'past_due', 'current_period_start' => (int) $twin['current_period_start'],
            'current_period_end' => (int) $twin['current_period_end'],
        ]);
        $api->subscriptionEvent(self::externalId($twinCanceled), ['event_type' => 'cancel']);
        self::assertSame('past_due', self::status($twinPastDue));
        self::assertSame('canceled', self::status($twinCanceled));

        $report = self::sweep();
        self::assertSame([1, 2, 3, 0], [
            $report['past_due'], $report['canceled'], $report['announced'], $report['unannounced'],
        ], LtvMaintenance::summary($report));
        self::assertStringContainsString('3 announced as subscription.changed', LtvMaintenance::summary($report));

        $queued = $this->queued();
        self::assertSame(
            [$twinPastDue, $twinCanceled, $toCanceled, $toPastDue, $dueToCanceled],
            array_keys($queued),
            'one event for each status written, the API\'s two and the sweep\'s three, to the hook that takes them'
        );
        $pairs = [[$toPastDue, $twinPastDue, 'upsert'], [$toCanceled, $twinCanceled, 'cancel']];
        foreach ($pairs as [$swept, $twin, $action]) {
            [$sweepBody, $apiBody] = [$queued[$swept], $queued[$twin]];
            self::assertSame(self::shape($apiBody), self::shape($sweepBody), 'the same event, in the same shape');
            self::assertSame($action, $sweepBody['data']['action']);
            self::assertSame($apiBody['data']['action'], $sweepBody['data']['action']);
            self::assertSame(
                ['subscription_id' => $swept, 'customer_id' => $sweepCustomer, 'action' => $action],
                $sweepBody['data']
            );
            self::assertSame(
                ['subscription_id' => $twin, 'customer_id' => $apiCustomer],
                array_slice($apiBody['data'], 0, 2)
            );
            self::assertEqualsWithDelta($now, $sweepBody['occurred_at'], 120);
        }
        self::assertSame('cancel', $queued[$dueToCanceled]['data']['action']);

        $again = self::sweep();
        self::assertSame(0, $again['announced'], 'nothing moved, nothing announced');
        self::assertCount(5, $this->queued());
    }

    public function testAChangeThatTookEffectBeforeTheWindowIsWrittenAndNotAnnounced(): void
    {
        $now = time();
        $c = self::customer();
        $window = LtvMaintenance::EVENT_WINDOW_SECONDS;
        // Lapsed (period end + 3 days' grace) an hour inside the window, and an hour before it.
        $inside = self::subscription($c, 'active', $now - $window - 3 * self::DAY + 3600);
        $before = self::subscription($c, 'active', $now - $window - 3 * self::DAY - 3600);
        // Canceled by the rule a year ago, as an upgraded install's backlog is.
        $yearAgo = self::subscription($c, 'active', $now - 400 * self::DAY);

        $report = self::sweep();

        self::assertSame([2, 1, 1, 2], [
            $report['past_due'], $report['canceled'], $report['announced'], $report['unannounced'],
        ], LtvMaintenance::summary($report));
        self::assertSame(['past_due', 'past_due', 'canceled'], [
            self::status($inside), self::status($before), self::status($yearAgo),
        ], 'every status is written');
        self::assertSame([$inside], array_keys($this->queued()), 'only the change inside the window is announced');
        self::assertStringContainsString(
            '1 announced as subscription.changed (2 that took effect more than 7 days ago not announced)',
            LtvMaintenance::summary($report)
        );
    }

    public function testABatchRolledBackQueuesNothingAndItsRetryQueuesOnce(): void
    {
        $now = time();
        $c = self::customer(10.0, 1);
        $s = self::subscription($c, 'active', $now - 5 * self::DAY);
        // The rollup refresh, the batch's last write, after its event is queued.
        self::q("CREATE TRIGGER p202_test_refuse_rollup BEFORE UPDATE ON 202_customers FOR EACH ROW
            IF NEW.customer_id = {$c} THEN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rollup refused by the test';
            END IF");

        $failed = self::sweep();
        self::assertArrayHasKey(LtvMaintenance::SWEEP, $failed['failed'], LtvMaintenance::summary($failed));
        self::assertSame('active', self::status($s), 'the batch rolled back');
        self::assertSame([], $this->queued(), 'and its event with it');

        self::q('DROP TRIGGER p202_test_refuse_rollup');
        $report = self::sweep();
        self::assertSame([1, 1], [$report['past_due'], $report['announced']]);
        self::assertSame('past_due', self::status($s));
        self::assertSame([$s], array_keys($this->queued()));
    }

    /** @return array<string, mixed> */
    private static function sweep(): array
    {
        $report = LtvMaintenance::runExclusive(
            new Connection(self::$db ?? throw new \LogicException()),
            time() + 60,
            [LtvMaintenance::SWEEP]
        );
        self::assertNotNull($report, 'no other run holds the lock');

        return $report;
    }

    /**
     * The subscription.changed bodies queued for the hook that takes every
     * event, by subscription, in the order queued; the other hook gets none.
     *
     * @return array<int, array<string, mixed>>
     */
    private function queued(): array
    {
        $other = self::row('SELECT COUNT(*) AS n FROM 202_ltv_webhook_deliveries WHERE webhook_id <> ' . $this->hook);
        self::assertSame('0', $other['n'], 'a hook not subscribed to subscription.changed was queued one');
        $rows = self::q('SELECT event_name, payload FROM 202_ltv_webhook_deliveries WHERE webhook_id = '
            . $this->hook . ' ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $rows);
        $bodies = [];
        foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
            self::assertSame('subscription.changed', $row['event_name']);
            $body = json_decode((string) $row['payload'], true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $id = $body['data']['subscription_id'] ?? null;
            self::assertIsInt($id, (string) $row['payload']);
            self::assertArrayNotHasKey($id, $bodies, 'one event per change');
            $bodies[$id] = $body;
        }

        return $bodies;
    }

    /**
     * A body's keys and value types, nested.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private static function shape(array $body): array
    {
        return array_map(
            static fn (mixed $v): mixed => is_array($v) ? self::shape($v) : get_debug_type($v),
            $body
        );
    }

    private static function customer(float $mrr = 0.0, int $activeSubs = 0): int
    {
        $now = time();
        self::q('INSERT INTO 202_customers SET user_id = ' . self::USER . ", primary_ref = 'ref-" . uniqid('', true)
            . "', first_seen_time = " . ($now - 90 * self::DAY) . ', last_activity_time = ' . ($now - 30 * self::DAY)
            . ', mrr = ' . $mrr . ', active_subscription_count = ' . $activeSubs . ', created_at = 1, updated_at = 1');

        return (int) self::$db?->insert_id;
    }

    private static function subscription(int $customerId, string $status, int $periodEnd): int
    {
        self::q('INSERT INTO 202_subscriptions SET user_id = ' . self::USER . ', customer_id = ' . $customerId
            . ", external_sub_id = 'sub-" . uniqid('', true) . "', amount = 10, status = '" . $status . "'"
            . ', mrr = 10, started_at = ' . ($periodEnd - 60 * self::DAY)
            . ', current_period_start = ' . ($periodEnd - 30 * self::DAY) . ', current_period_end = ' . $periodEnd
            . ', grace_days = 3, created_at = 1, updated_at = 1');

        return (int) self::$db?->insert_id;
    }

    private static function status(int $subscriptionId): string
    {
        return (string) self::row('SELECT status FROM 202_subscriptions WHERE subscription_id = '
            . $subscriptionId)['status'];
    }

    private static function externalId(int $subscriptionId): string
    {
        return (string) self::row('SELECT external_sub_id FROM 202_subscriptions WHERE subscription_id = '
            . $subscriptionId)['external_sub_id'];
    }

    private static function q(string $sql): \mysqli_result|bool
    {
        self::assertNotNull(self::$db);
        $result = self::$db->query($sql);
        self::assertNotFalse($result, $sql);

        return $result;
    }

    /** @return array<string, string|null> */
    private static function row(string $sql): array
    {
        $result = self::q($sql);
        self::assertInstanceOf(\mysqli_result::class, $result);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, $sql);

        return $row;
    }
}
