<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\LtvQuery;
use Prosper202\Ltv\MysqlLtvRepository;

/**
 * Verifies the per-product LTV breakdown/prediction arithmetic against a REAL
 * database: aov, repeat_rate, and the even-split subscriber-MRR attribution
 * that productBreakdown() computes, plus the end-to-end predict() wiring that
 * previously reported $0 for the best-selling products.
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class LtvProductPredictionIntegrationTest extends TestCase
{
    private static ?\mysqli $db = null;
    private static ?Connection $conn = null;
    private int $eventSeq = 0;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) { return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        mysqli_report(MYSQLI_REPORT_STRICT);

        $db = @mysqli_connect(
            $host,
            (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
            (string) (getenv('P202_TEST_DB_PASS') ?: ''),
            (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
            (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
        );
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
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (P202_TEST_DB_HOST).');
        }
        foreach (['202_revenue_events', '202_revenue_line_items', '202_products', '202_subscriptions', '202_customers'] as $t) {
            self::$db->query("TRUNCATE TABLE {$t}");
        }
        $this->eventSeq = 0;
    }

    // ── Fixture helpers ─────────────────────────────────────────────────

    private function product(int $id, string $name): void
    {
        self::$db->query(
            "INSERT INTO 202_products SET product_id={$id}, user_id=1, external_product_id='ext-{$id}', name='" .
            self::$db->real_escape_string($name) . "', created_at=1, updated_at=1"
        );
    }

    /** One purchase order: an event with a single product line item. */
    private function order(int $customerId, int $productId, float $amount, float $qty = 1.0, string $type = 'purchase'): int
    {
        $eventId = ++$this->eventSeq + 100000;
        self::$db->query(
            "INSERT INTO 202_revenue_events SET event_id={$eventId}, user_id=1, customer_id={$customerId}, " .
            "event_type='{$type}', amount={$amount}, occurred_at=1700000000, source='api', created_at=1"
        );
        self::$db->query(
            "INSERT INTO 202_revenue_line_items SET user_id=1, event_id={$eventId}, product_id={$productId}, " .
            "quantity={$qty}, amount={$amount}, created_at=1"
        );
        return $eventId;
    }

    /**
     * An active subscription whose renewal event bills for the given products
     * (a bundle when more than one), each as its own line item.
     *
     * @param list<int> $productIds
     */
    private function activeSubscription(int $customerId, float $mrr, array $productIds, string $status = 'active'): void
    {
        $subId = ++$this->eventSeq + 500000;
        self::$db->query(
            "INSERT INTO 202_subscriptions SET subscription_id={$subId}, user_id=1, customer_id={$customerId}, " .
            "external_sub_id='sub-{$subId}', amount={$mrr}, status='{$status}', mrr={$mrr}, started_at=1, " .
            "current_period_start=1, current_period_end=2, created_at=1, updated_at=1"
        );
        $eventId = ++$this->eventSeq + 100000;
        self::$db->query(
            "INSERT INTO 202_revenue_events SET event_id={$eventId}, user_id=1, customer_id={$customerId}, " .
            "event_type='renewal', amount={$mrr}, occurred_at=1700000000, source='subscription', " .
            "subscription_id={$subId}, created_at=1"
        );
        foreach ($productIds as $pid) {
            self::$db->query(
                "INSERT INTO 202_revenue_line_items SET user_id=1, event_id={$eventId}, product_id={$pid}, " .
                "quantity=1, amount=" . ($mrr / count($productIds)) . ", created_at=1"
            );
        }
    }

    private function repo(): MysqlLtvRepository
    {
        return new MysqlLtvRepository(self::$conn);
    }

    /** @return array<string, mixed>|null */
    private function productRow(int $productId): ?array
    {
        foreach ($this->repo()->breakdown(new LtvQuery(1), 'product', 100, 0) as $row) {
            if ((int) $row['id'] === $productId) {
                return $row;
            }
        }
        return null;
    }

    // ── Tests ───────────────────────────────────────────────────────────

    public function testBreakdownComputesAovAndRepeatRate(): void
    {
        $this->product(1, 'Widget');
        // customer 1001 buys twice ($10 + $10), 1002 and 1003 once each ($10).
        $this->order(1001, 1, 10.0);
        $this->order(1001, 1, 10.0);
        $this->order(1002, 1, 10.0);
        $this->order(1003, 1, 10.0);

        $row = $this->productRow(1);
        self::assertNotNull($row);
        self::assertSame(3, (int) $row['customers']);
        self::assertSame(4, (int) $row['orders']);
        self::assertEqualsWithDelta(40.0, (float) $row['total_revenue'], 1e-6);
        self::assertEqualsWithDelta(10.0, (float) $row['aov'], 1e-6);          // 40 / 4
        // 1 of 3 customers repeat. MySQL division carries div_precision_increment
        // (default 4) decimals, so 1/3 -> 0.3333 — the same precision the
        // existing acquisition breakdown produces for this expression.
        self::assertEqualsWithDelta(1 / 3, (float) $row['repeat_rate'], 1e-4);
        self::assertEqualsWithDelta(0.0, (float) $row['mrr'], 1e-6);
    }

    public function testSubscriberMrrAttributedToSingleProduct(): void
    {
        $this->product(1, 'Widget');
        $this->order(1001, 1, 10.0);
        $this->activeSubscription(1001, 30.0, [1]);

        $row = $this->productRow(1);
        self::assertNotNull($row);
        self::assertEqualsWithDelta(30.0, (float) $row['mrr'], 1e-6);
    }

    public function testBundleSubscriberMrrSplitEvenlyAndReconciles(): void
    {
        $this->product(1, 'Widget');
        $this->product(2, 'Gadget');
        $this->order(1001, 1, 10.0);
        $this->order(1002, 2, 10.0);
        // One $50/mo subscription billing for both products -> $25 each.
        $this->activeSubscription(1001, 50.0, [1, 2]);

        $mrr1 = (float) $this->productRow(1)['mrr'];
        $mrr2 = (float) $this->productRow(2)['mrr'];
        self::assertEqualsWithDelta(25.0, $mrr1, 1e-6);
        self::assertEqualsWithDelta(25.0, $mrr2, 1e-6);
        // Additive: product MRR reconciles to the subscription's total.
        self::assertEqualsWithDelta(50.0, $mrr1 + $mrr2, 1e-6);
    }

    public function testCanceledSubscriptionContributesNoProductMrr(): void
    {
        $this->product(1, 'Widget');
        $this->order(1001, 1, 10.0);
        $this->activeSubscription(1001, 30.0, [1], 'canceled');

        self::assertEqualsWithDelta(0.0, (float) $this->productRow(1)['mrr'], 1e-6);
    }

    public function testTimeWindowScopesRevenueButNotSubscriberState(): void
    {
        $this->product(1, 'Widget');
        // In-window order and an out-of-window one for the same customer.
        $this->order(1001, 1, 10.0); // occurred_at = 1700000000
        self::$db->query(
            "INSERT INTO 202_revenue_events SET event_id=999001, user_id=1, customer_id=1002, " .
            "event_type='purchase', amount=999, occurred_at=1600000000, source='api', created_at=1"
        );
        self::$db->query(
            "INSERT INTO 202_revenue_line_items SET user_id=1, event_id=999001, product_id=1, quantity=1, amount=999, created_at=1"
        );

        $rows = $this->repo()->breakdown(new LtvQuery(1, 1699999999, 1700000001), 'product', 100, 0);
        $row = null;
        foreach ($rows as $r) {
            if ((int) $r['id'] === 1) {
                $row = $r;
            }
        }
        self::assertNotNull($row);
        self::assertSame(1, (int) $row['customers']);                       // out-of-window customer excluded
        self::assertEqualsWithDelta(10.0, (float) $row['total_revenue'], 1e-6);
    }

    public function testPredictUsesRealCohortProjectionForLargeProduct(): void
    {
        $this->product(1, 'Bestseller');
        // 20 customers, one $10 order each; 5 of them buy a second time.
        for ($c = 1; $c <= 20; $c++) {
            $this->order(2000 + $c, 1, 10.0);
        }
        for ($c = 1; $c <= 5; $c++) {
            $this->order(2000 + $c, 1, 10.0);
        }

        $result = $this->repo()->predict(new LtvQuery(1), 'product');
        $productRow = null;
        foreach ($result['breakdown'] as $r) {
            if ((int) $r['id'] === 1) {
                $productRow = $r;
            }
        }
        self::assertNotNull($productRow);
        $prediction = $productRow['prediction'];

        // The regression: this used to be 'account_fallback' with a $0 (or
        // account-average) number. It must now be a real per-product cohort.
        self::assertSame('cohort', $prediction['basis']);
        self::assertEqualsWithDelta(0.25, (float) $prediction['inputs']['repeat_rate'], 1e-6);
        self::assertEqualsWithDelta(10.0, (float) $prediction['inputs']['aov'], 1e-6);
        // aov / (1 - repeat_rate) = 10 / 0.75 = 13.3333...
        self::assertEqualsWithDelta(13.33333, (float) $prediction['predicted_ltv_per_customer'], 1e-4);
    }
}
