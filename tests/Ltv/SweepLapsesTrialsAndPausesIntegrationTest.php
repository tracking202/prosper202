<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\LtvMaintenance;
use Prosper202\Ltv\MysqlLtvRepository;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * A trial whose period ended with no renewal, and a pause past its period
 * end with none, lapse as an active subscription does: past_due once the
 * period end and grace are over, canceled 30 days later, dated by the rule,
 * announced as the active one's lapse is. The sweep read only active and
 * past_due rows, so both stayed trialing or paused for good and counted
 * there in GET /ltv/mrr. A renewal or an upsert from the billing system
 * still decides: the sweep moves only a row whose period it has not moved.
 *
 * The sweep reads every account's rows, so its tables are emptied for each
 * test.
 *
 * @group integration
 */
final class SweepLapsesTrialsAndPausesIntegrationTest extends TestCase
{
    private const USER = 990921;
    private const DAY = 86400;
    private const GRACE = 3 * self::DAY;

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
        foreach (self::TABLES as $table) {
            self::q('TRUNCATE TABLE ' . $table);
        }
        $this->hook = (new MysqlWebhookRepository(new Connection(self::$db)))
            ->create(self::USER, 'https://hooks.example/all', ['*'])['webhookId'];
        $log = tempnam(sys_get_temp_dir(), 'p202-sweep-lapses-log');
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

    public function testATrialOrAPauseEndedUnpaidLapsesAsAnActiveSubscriptionDoes(): void
    {
        $now = self::startOfASecond();
        $c = self::customer();
        $rows = [];
        foreach (['active', 'trialing', 'paused'] as $status) {
            $rows[$status] = [
                // Its period and grace over a second from now, and a second ago.
                'before' => self::subscription($c, $status, $now - self::GRACE + 1),
                'after' => self::subscription($c, $status, $now - self::GRACE - 1),
                // The cancel point (period end, grace, 30 days) 2 days ago.
                'gone' => self::subscription($c, $status, $now - self::GRACE - 32 * self::DAY),
            ];
        }
        $inTrial = self::subscription($c, 'trialing', $now + 10 * self::DAY);
        $pausedOn = self::subscription($c, 'paused', $now + 10 * self::DAY);

        $report = self::sweep();
        self::assertSame($now, time(), 'the sweep ran in the second the rows were written about');

        self::assertSame(
            [3, 3, 2, 2, 6, 0],
            [
                $report['past_due'], $report['canceled'], $report['lapsed_trials'], $report['lapsed_pauses'],
                $report['announced'], $report['unannounced'],
            ],
            LtvMaintenance::summary($report)
        );
        self::assertStringContainsString(
            'subscriptions 3 to past_due, 3 to canceled (2 from trialing, 2 from paused)',
            LtvMaintenance::summary($report)
        );
        $queued = $this->queued();
        foreach ($rows as $status => $ids) {
            self::assertSame([$status, null, '1'], self::sub($ids['before']), $status . ': a second before it lapses');
            self::assertSame(['past_due', null, (string) $now], self::sub($ids['after']), $status . ': a second after');
            self::assertSame(
                ['canceled', (string) ($now - 2 * self::DAY), (string) $now],
                self::sub($ids['gone']),
                $status . ': canceled when the rule canceled it, not when the sweep ran'
            );
            self::assertSame('upsert', $queued[$ids['after']] ?? null, $status . ': announced as the API\'s past_due');
            self::assertSame('cancel', $queued[$ids['gone']] ?? null, $status . ': announced as the API\'s cancel');
            self::assertArrayNotHasKey($ids['before'], $queued);
        }
        self::assertSame(['trialing', 'paused'], [self::sub($inTrial)[0], self::sub($pausedOn)[0]]);

        $mrr = (new MysqlLtvRepository(new Connection(self::db())))->mrr(self::USER);
        self::assertSame(
            [1, 2, 2, 3, 3],
            [$mrr['active_subscriptions'], $mrr['trialing'], $mrr['paused'], $mrr['past_due'], $mrr['canceled_total']],
            'the reports count a lapsed trial or pause where they count a lapsed active subscription'
        );

        $again = self::sweep();
        self::assertSame([0, 0, 0], [$again['past_due'], $again['canceled'], $again['announced']]);
        self::assertFalse(LtvMaintenance::didWork($again));
    }

    public function testATrialOrPauseLapsedToPastDueIsCanceledWhenItsWindowIsOver(): void
    {
        $now = self::startOfASecond();
        $c = self::customer();
        // Lapsed as trials and pauses 29 days ago, and a second either side of the cancel point.
        $ids = [];
        foreach (['trialing', 'paused'] as $status) {
            $ids[$status] = [
                self::subscription($c, $status, $now - self::GRACE - 30 * self::DAY + 1),
                self::subscription($c, $status, $now - self::GRACE - 30 * self::DAY - 1),
            ];
        }
        $first = self::sweep();
        self::assertSame([2, 2], [$first['past_due'], $first['canceled']], LtvMaintenance::summary($first));
        foreach ($ids as $status => [$before, $after]) {
            self::assertSame('past_due', self::sub($before)[0], $status);
            self::assertSame(['canceled', (string) ($now - 1)], array_slice(self::sub($after), 0, 2), $status);
        }

        // Its cancel point is two seconds on: the past_due phase cancels it then.
        sleep(2);
        $later = self::sweep();
        self::assertSame([0, 2], [$later['past_due'], $later['canceled']], LtvMaintenance::summary($later));
        foreach ($ids as $status => [$before]) {
            self::assertSame(['canceled', (string) ($now + 1)], array_slice(self::sub($before), 0, 2), $status);
        }
    }

    public function testTheBillingSystemsLaterWordDecides(): void
    {
        $now = time();
        $c = self::customer();
        $api = new LtvController(self::db(), self::USER);
        $trial = self::subscription($c, 'trialing', $now - self::GRACE - self::DAY, 0.0);
        $pause = self::subscription($c, 'paused', $now - self::GRACE - self::DAY);
        $extended = self::subscription($c, 'paused', $now - self::GRACE - self::DAY);
        $converted = self::subscription($c, 'trialing', $now - self::DAY, 0.0);

        // The pause extended, and the trial converted, before the sweep.
        $api->upsertSubscription([
            'external_sub_id' => self::externalId($extended), 'customer_id' => $c, 'amount' => 10,
            'status' => 'paused', 'current_period_start' => $now - self::DAY,
            'current_period_end' => $now + 20 * self::DAY,
        ]);
        $api->subscriptionEvent(self::externalId($converted), ['event_type' => 'renewal', 'transaction_id' => 'ch_1']);

        self::sweep();
        self::assertSame(
            ['past_due', 'past_due', 'paused', 'active'],
            [self::sub($trial)[0], self::sub($pause)[0], self::sub($extended)[0], self::sub($converted)[0]]
        );

        // The lapsed trial's first charge, and the pause resumed by an upsert.
        $api->subscriptionEvent(self::externalId($trial), ['event_type' => 'renewal', 'transaction_id' => 'ch_2']);
        $api->upsertSubscription([
            'external_sub_id' => self::externalId($pause), 'customer_id' => $c, 'amount' => 10,
            'status' => 'active', 'current_period_start' => $now, 'current_period_end' => $now + 30 * self::DAY,
        ]);
        $report = self::sweep();
        self::assertSame([0, 0], [$report['past_due'], $report['canceled']], LtvMaintenance::summary($report));
        self::assertSame(['active', 'active'], [self::sub($trial)[0], self::sub($pause)[0]]);
        $rollups = self::row('SELECT mrr, active_subscription_count FROM 202_customers WHERE customer_id = ' . $c);
        self::assertSame(['30.00000', '3'], [$rollups['mrr'], $rollups['active_subscription_count']]);
    }

    public function testEachStatusDrainsInBatchesWithACursor(): void
    {
        $now = time();
        $c = self::customer();
        $lapsed = [];
        foreach (['trialing', 'paused'] as $status) {
            for ($i = 0; $i < 5; $i++) {
                // Two share a period end: the cursor's tie on the id.
                $lapsed[] = self::subscription($c, $status, $now - self::GRACE - (int) floor($i / 2) * 3600 - 60);
            }
        }
        $report = LtvMaintenance::runExclusive(new Connection(self::db()), time() + 60, [LtvMaintenance::SWEEP], false, 2);
        self::assertNotNull($report);
        self::assertSame([10, 5, 5, []], [
            $report['past_due'], $report['lapsed_trials'], $report['lapsed_pauses'], $report['more'],
        ], LtvMaintenance::summary($report));
        foreach ($lapsed as $id) {
            self::assertSame('past_due', self::sub($id)[0]);
        }
    }

    /** The current second, once a new one has just begun. */
    private static function startOfASecond(): int
    {
        $start = time();
        while (time() === $start) {
            usleep(2000);
        }

        return time();
    }

    /** @return array<string, mixed> */
    private static function sweep(): array
    {
        $report = LtvMaintenance::runExclusive(new Connection(self::db()), time() + 60, [LtvMaintenance::SWEEP]);
        self::assertNotNull($report, 'no other run holds the lock');
        self::assertSame([], $report['failed'], LtvMaintenance::summary($report));

        return $report;
    }

    /**
     * The action of each subscription.changed event queued, by subscription.
     *
     * @return array<int, string>
     */
    private function queued(): array
    {
        $rows = self::q('SELECT event_name, payload FROM 202_ltv_webhook_deliveries WHERE webhook_id = '
            . $this->hook . ' ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $rows);
        $actions = [];
        foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
            self::assertSame('subscription.changed', $row['event_name']);
            $body = json_decode((string) $row['payload'], true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            $id = $body['data']['subscription_id'] ?? null;
            self::assertIsInt($id, (string) $row['payload']);
            self::assertArrayNotHasKey($id, $actions, 'one event per change');
            $actions[$id] = (string) $body['data']['action'];
        }

        return $actions;
    }

    private static function db(): \mysqli
    {
        return self::$db ?? throw new \LogicException('no test database');
    }

    private static function customer(): int
    {
        $now = time();
        self::q('INSERT INTO 202_customers SET user_id = ' . self::USER . ", primary_ref = 'ref-" . uniqid('', true)
            . "', first_seen_time = " . ($now - 90 * self::DAY) . ', last_activity_time = ' . ($now - 30 * self::DAY)
            . ', created_at = 1, updated_at = 1');

        return (int) self::db()->insert_id;
    }

    private static function subscription(int $customerId, string $status, int $periodEnd, float $mrr = 10.0): int
    {
        self::q('INSERT INTO 202_subscriptions SET user_id = ' . self::USER . ', customer_id = ' . $customerId
            . ", external_sub_id = 'sub-" . uniqid('', true) . "', amount = 10, status = '" . $status . "'"
            . ', mrr = ' . $mrr . ', started_at = ' . ($periodEnd - 60 * self::DAY)
            . ', current_period_start = ' . ($periodEnd - 30 * self::DAY) . ', current_period_end = ' . $periodEnd
            . ', grace_days = 3, created_at = 1, updated_at = 1');

        return (int) self::db()->insert_id;
    }

    /** @return list<string|null> status, canceled_at, updated_at */
    private static function sub(int $subscriptionId): array
    {
        return array_values(self::row('SELECT status, canceled_at, updated_at FROM 202_subscriptions'
            . ' WHERE subscription_id = ' . $subscriptionId));
    }

    private static function externalId(int $subscriptionId): string
    {
        return (string) self::row('SELECT external_sub_id FROM 202_subscriptions WHERE subscription_id = '
            . $subscriptionId)['external_sub_id'];
    }

    private static function q(string $sql): \mysqli_result|bool
    {
        $result = self::db()->query($sql);
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
