<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\CompanyConflictException;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\MysqlCustomerRepository;

/**
 * End-to-end verification of the LTV pipeline against a REAL MySQL/MariaDB
 * database — the unit tests use fakes and cannot exercise real insert_id
 * paths, the ON DUPLICATE KEY convergence, unique-key races, or multi-
 * statement flows whose intermediate reads matter (attach/detach, sweeps).
 *
 * Skips automatically unless a test database is configured via env:
 *   P202_TEST_DB_HOST, P202_TEST_DB_PORT, P202_TEST_DB_USER,
 *   P202_TEST_DB_PASS, P202_TEST_DB_NAME
 *
 * @group integration
 */
final class LtvDatabaseIntegrationTest extends TestCase
{
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
            '202_customers', '202_customer_aliases', '202_customer_fields', '202_customer_field_values',
            '202_revenue_events', '202_revenue_line_items', '202_products', '202_subscriptions',
            '202_companies', '202_personalization_tokens', '202_engagement_events',
            '202_offer_transitions', '202_offer_recommendations', '202_aff_campaigns',
        ] as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
    }

    private function insertClick(int $clickId, float $payout = 10.0, float $cpc = 0.0): void
    {
        $db = self::$db;
        $db->query("INSERT INTO 202_clicks SET click_id=$clickId, user_id=1, aff_campaign_id=7, click_payout=$payout, click_cpc=$cpc, click_lead=0, click_time=1700000000");
        $db->query("INSERT INTO 202_clicks_spy SET click_id=$clickId, user_id=1, aff_campaign_id=7, click_payout=$payout, click_cpc=$cpc, click_lead=0, click_time=1700000000");
    }

    /** @return array<string, mixed> */
    private function log(int $clickId): array
    {
        return [
            'click_id' => $clickId, 'campaign_id' => '7', 'user_id' => '1',
            'click_time' => 1700000000, 'conv_time' => 1700000100,
            'time_difference' => '0 days, 0 hours, 1 min and 40 sec',
            'ip' => '203.0.113.9', 'pixel_type' => 3,
            'user_agent' => 'LtvIntegrationTest/1.0', 'click_payout' => '10.0',
        ];
    }

    /** @return array<string, mixed>|null */
    private function row(string $sql): ?array
    {
        $res = self::$db->query($sql);

        return $res instanceof \mysqli_result ? ($res->fetch_assoc() ?: null) : null;
    }

    private function scalar(string $sql): mixed
    {
        $row = $this->row($sql);

        return $row !== null ? reset($row) : null;
    }

    public function testConversionWithCustomerBuildsFullLedgerPipeline(): void
    {
        $this->insertClick(2000);

        $result = p202RecordConversion(self::$db, $this->log(2000), '', true, '10.0', 'ORD-LTV-1', [
            'customer_ref' => 'cust-100',
            'customer_ref_type' => 'merchant_id',
            'customer_crm' => ['first_name' => 'Jane', 'email' => 'jane@acme.com', 'company' => 'Acme  Corp'],
        ], [
            ['sku' => 'SKU-1', 'name' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0, 'amount' => 10.0],
        ]);

        self::assertFalse($result['duplicate']);
        self::assertGreaterThan(0, $result['conv_id']);

        // Customer created with CRM data and a canonicalized company string.
        $customer = $this->row("SELECT * FROM 202_customers WHERE primary_ref='cust-100'");
        self::assertNotNull($customer, 'a customer must be created from the ref');
        self::assertSame('Jane', $customer['first_name']);
        self::assertSame('Acme Corp', $customer['company'], 'company string is stored canonical (whitespace collapsed)');
        self::assertNotNull($customer['company_id'], 'ingest must stamp the company entity at creation');
        self::assertSame(1, (int) $customer['order_count']);
        self::assertSame(10.0, (float) $customer['total_revenue']);

        // The company entity exists and matches the stamp.
        $company = $this->row('SELECT * FROM 202_companies WHERE company_id=' . (int) $customer['company_id']);
        self::assertNotNull($company);
        self::assertSame('Acme Corp', $company['name']);

        // Alias, ledger event (keyed to the conversion), line item, click stamp.
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_type='merchant_id' AND alias_value='cust-100'"));
        $event = $this->row('SELECT * FROM 202_revenue_events WHERE conv_id=' . (int) $result['conv_id']);
        self::assertNotNull($event, 'exactly one ledger event per conversion');
        self::assertSame(10.0, (float) $event['amount']);
        self::assertSame('conversion', $event['source']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_line_items WHERE event_id=' . (int) $event['event_id']));
        self::assertSame((int) $customer['customer_id'], (int) $this->scalar('SELECT customer_id FROM 202_clicks_tracking WHERE click_id=2000'));
    }

    public function testReplayAndSubidReuseKeepLedgerConsistent(): void
    {
        $this->insertClick(2000);
        $customer = ['customer_ref' => 'cust-200', 'customer_ref_type' => 'custom'];

        $first = p202RecordConversion(self::$db, $this->log(2000), '', true, '10.0', 'ORD-A', $customer, []);
        // Exact replay: no new conversion, no new ledger event, rollups unchanged.
        $replay = p202RecordConversion(self::$db, $this->log(2000), '', true, '10.0', 'ORD-A', $customer, []);
        self::assertTrue($replay['duplicate']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'));

        // A second purchase on the same subid WITHOUT a customer ref resolves
        // through the cached click link to the same customer. The ledger
        // amount comes from the log's payout.
        $secondLog = $this->log(2000);
        $secondLog['click_payout'] = '15.0';
        $second = p202RecordConversion(self::$db, $secondLog, '', true, '15.0', 'ORD-B', [], []);
        self::assertFalse($second['duplicate']);
        self::assertNotSame((int) $first['conv_id'], (int) $second['conv_id']);

        $row = $this->row("SELECT customer_id, order_count, total_revenue FROM 202_customers WHERE primary_ref='cust-200'");
        self::assertSame(2, (int) $row['order_count']);
        self::assertSame(25.0, (float) $row['total_revenue']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_customers'), 'no second customer may appear');
    }

    public function testStrictCompanyCreateConflictsAndOdkuConverges(): void
    {
        $companies = new MysqlCompanyRepository(self::$conn);

        // ODKU convergence: name variants land on one row (real LAST_INSERT_ID).
        $a = $companies->resolveOrCreate(1, 'Acme  Corp');
        $b = $companies->resolveOrCreate(1, 'ACME CORP');
        self::assertGreaterThan(0, $a);
        self::assertSame($a, $b, 'normalized-name variants must converge on one company');

        // Strict create: existing name is a typed conflict.
        try {
            $companies->create(1, 'acme corp');
            self::fail('create() must conflict with the existing company');
        } catch (CompanyConflictException) {
            $this->addToAssertionCount(1);
        }

        // Single-INSERT create carries the domain.
        $withDomain = $companies->create(1, 'Initech', 'INITECH.com');
        self::assertSame('initech.com', $this->scalar('SELECT domain FROM 202_companies WHERE company_id=' . $withDomain));
    }

    public function testDetachMarkerLifecycleAgainstRealRows(): void
    {
        $companies = new MysqlCompanyRepository(self::$conn);
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));

        $companyId = $companies->create(1, 'Acme Corp', 'acme.com');

        // Fresh customer with a matching email domain auto-attaches on create.
        $autoId = $crm->upsert(1, ['customer_ref' => 'auto-1', 'email' => 'amy@acme.com']);
        $auto = $this->row('SELECT company, company_id FROM 202_customers WHERE customer_id=' . $autoId);
        self::assertSame($companyId, (int) $auto['company_id'], 'domain auto-attach must fire for a pristine customer');
        self::assertSame('Acme Corp', $auto['company'], 'the entity name is stamped, not a caller string');

        // Explicit detach writes the marker...
        $crm->upsert(1, ['customer_id' => $autoId, 'company' => '']);
        $detached = $this->row('SELECT company, company_id FROM 202_customers WHERE customer_id=' . $autoId);
        self::assertNull($detached['company_id']);
        self::assertSame('', $detached['company'], "explicit detach records the '' marker");

        // ...and a later email-bearing sync must NOT re-attach.
        $crm->upsert(1, ['customer_id' => $autoId, 'email' => 'amy@acme.com', 'first_name' => 'Amy']);
        self::assertNull($this->scalar('SELECT company_id FROM 202_customers WHERE customer_id=' . $autoId), 'detach must survive recurring syncs');

        // A pristine customer without company data stays NULL (still eligible).
        $pristineId = $crm->upsert(1, ['customer_ref' => 'pristine-1', 'first_name' => 'Bo']);
        self::assertNull($this->scalar('SELECT company FROM 202_customers WHERE customer_id=' . $pristineId));
    }

    public function testLinkSweepReconcileAndDeleteGuards(): void
    {
        $companies = new MysqlCompanyRepository(self::$conn);
        $companyId = $companies->create(1, 'Acme Corp');

        // Legacy-style row: an unstamped uncanonical string. It must block
        // deletion (uncanonical or not) BEFORE any attached rows exist, so
        // the stray guard specifically is what fires.
        self::$db->query("INSERT INTO 202_customers SET user_id=1, primary_ref='legacy-1', company='Acme  Corp', first_seen_time=1, last_activity_time=1, created_at=1, updated_at=1");
        try {
            $companies->delete(1, $companyId);
            self::fail('delete must be blocked by pending unstamped customers');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('pending entity linking', $e->getMessage());
        }

        // Sweep links the legacy row onto the SAME entity with the entity name.
        $linked = $companies->linkUnlinkedCustomers(1);
        self::assertSame(1, $linked);
        $legacy = $this->row("SELECT company, company_id FROM 202_customers WHERE primary_ref='legacy-1'");
        self::assertSame($companyId, (int) $legacy['company_id']);
        self::assertSame('Acme Corp', $legacy['company']);

        // A stamped row whose string drifted from the entity name is repaired
        // by the converge pass.
        self::$db->query("INSERT INTO 202_customers SET user_id=1, primary_ref='drift-1', company='ACME  CORP', company_id=$companyId, first_seen_time=1, last_activity_time=1, created_at=1, updated_at=1");
        self::assertSame(1, $companies->reconcileAttachedStrings());
        self::assertSame('Acme Corp', $this->scalar("SELECT company FROM 202_customers WHERE primary_ref='drift-1'"));

        // Now deletion is blocked by ATTACHED customers with the right message.
        try {
            $companies->delete(1, $companyId);
            self::fail('delete must be blocked by attached customers');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('attached customer', $e->getMessage());
        }
    }

    public function testCustomerMergeMovesLedgerAndFreesCompany(): void
    {
        $this->insertClick(3000);
        $this->insertClick(3001);
        p202RecordConversion(self::$db, $this->log(3000), '', true, '10.0', 'M-1', ['customer_ref' => 'merge-src', 'customer_crm' => ['company' => 'Solo Inc']], []);
        $dstLog = $this->log(3001);
        $dstLog['click_payout'] = '20.0';
        p202RecordConversion(self::$db, $dstLog, '', true, '20.0', 'M-2', ['customer_ref' => 'merge-dst'], []);

        $srcId = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='merge-src'");
        $dstId = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='merge-dst'");
        $companyId = (int) $this->scalar("SELECT company_id FROM 202_customers WHERE customer_id=$srcId");
        self::assertGreaterThan(0, $companyId);

        // Engagement history and personalization tokens must follow the merge.
        self::$db->query("INSERT INTO 202_engagement_events SET user_id=1, customer_id=$srcId, event_name='pricing_viewed', source='api', occurred_at=1700000000, created_at=1700000000");
        self::$db->query("INSERT INTO 202_personalization_tokens SET token_hash=UNHEX(SHA2('t',256)), user_id=1, customer_id=$srcId, created_at=1700000000, first_use_deadline=1700003600, replay_until=1702592000");

        // Pin acquisition/recency so the propagation is deterministic: the
        // SOURCE was acquired first and was active most recently.
        self::$db->query("UPDATE 202_customers SET first_seen_time=1600000000, first_click_id=3000, last_activity_time=1710000000 WHERE customer_id=$srcId");
        self::$db->query("UPDATE 202_customers SET first_seen_time=1650000000, first_click_id=3001, last_activity_time=1705000000 WHERE customer_id=$dstId");

        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $crm->merge(1, $srcId, $dstId);

        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_engagement_events WHERE customer_id=$dstId"), 'engagement history follows the merge');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_personalization_tokens WHERE customer_id=$dstId"), 'personalization tokens follow the merge');

        // The target inherits the earliest acquisition (click + time) and the
        // freshest activity, so merged revenue attributes to the real
        // acquisition campaign/cohort and recency stays live.
        $dstAcq = $this->row("SELECT first_seen_time, first_click_id, last_activity_time FROM 202_customers WHERE customer_id=$dstId");
        self::assertSame(1600000000, (int) $dstAcq['first_seen_time'], 'earliest acquisition time wins');
        self::assertSame(3000, (int) $dstAcq['first_click_id'], 'acquisition click follows the earlier first-seen');
        self::assertSame(1710000000, (int) $dstAcq['last_activity_time'], 'freshest activity wins');

        // Ledger re-parented, target reconciled from the ledger, source zeroed
        // and detached — so its (now empty) company is deletable.
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM 202_revenue_events WHERE customer_id=$dstId"));
        $dst = $this->row("SELECT order_count, total_revenue FROM 202_customers WHERE customer_id=$dstId");
        self::assertSame(2, (int) $dst['order_count']);
        self::assertSame(30.0, (float) $dst['total_revenue']);
        $src = $this->row("SELECT merged_into_customer_id, company_id, total_revenue FROM 202_customers WHERE customer_id=$srcId");
        self::assertSame($dstId, (int) $src['merged_into_customer_id']);
        self::assertNull($src['company_id']);

        (new MysqlCompanyRepository(self::$conn))->delete(1, $companyId);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_companies WHERE company_id=$companyId"));
    }

    public function testSubscriptionReassignmentReconcilesBothOwners(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $aId = $crm->upsert(1, ['customer_ref' => 'sub-owner-a']);
        $bId = $crm->upsert(1, ['customer_ref' => 'sub-owner-b']);

        $subs->upsert(1, ['external_sub_id' => 'SUB-1', 'amount' => 30.0, 'customer_id' => $aId]);
        self::assertSame(30.0, (float) $this->scalar("SELECT mrr FROM 202_customers WHERE customer_id=$aId"));

        // Reassign the same subscription to customer B: A must not keep the
        // moved MRR until a maintenance sweep happens by.
        $subs->upsert(1, ['external_sub_id' => 'SUB-1', 'amount' => 30.0, 'customer_id' => $bId]);
        $a = $this->row("SELECT mrr, active_subscription_count FROM 202_customers WHERE customer_id=$aId");
        $b = $this->row("SELECT mrr, active_subscription_count FROM 202_customers WHERE customer_id=$bId");
        self::assertSame(0.0, (float) $a['mrr'], 'previous owner is reconciled immediately');
        self::assertSame(0, (int) $a['active_subscription_count']);
        self::assertSame(30.0, (float) $b['mrr']);
        self::assertSame(1, (int) $b['active_subscription_count']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_subscriptions'), 'still one subscription');
    }

    public function testCustomerDetailEventsCarrySubscriptionPlanName(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $customerId = $crm->upsert(1, ['customer_ref' => 'plan-name-1']);
        $subs->upsert(1, ['external_sub_id' => 'SUB-PLAN', 'plan_name' => 'Scale Plan (Monthly)', 'amount' => 299.0, 'customer_id' => $customerId]);
        $subscriptionId = (int) $this->scalar("SELECT subscription_id FROM 202_subscriptions WHERE external_sub_id='SUB-PLAN'");

        // A renewal from the subscription and a direct one-off purchase.
        $customers->insertRevenueEvent(1, $customerId, [
            'event_type' => 'renewal', 'amount' => 299.0, 'currency' => 'USD',
            'occurred_at' => 1700000200, 'source' => 'subscription',
            'subscription_id' => $subscriptionId, 'idempotency_key' => 'plan-renewal-1',
        ], 1700000200);
        $customers->insertRevenueEvent(1, $customerId, [
            'event_type' => 'purchase', 'amount' => 10.0, 'currency' => 'USD',
            'occurred_at' => 1700000100, 'source' => 'api', 'idempotency_key' => 'plan-direct-1',
        ], 1700000100);

        $events = $crm->get(1, $customerId)['recent_events'];
        self::assertCount(2, $events);
        self::assertSame('Scale Plan (Monthly)', $events[0]['plan_name'], 'subscription-sourced events resolve their plan');
        self::assertNull($events[1]['plan_name'], 'direct purchases keep flowing with a NULL plan');
    }

    public function testWebhookDeliveryClaimPreventsDoubleSend(): void
    {
        self::$db->query('TRUNCATE TABLE 202_ltv_webhooks');
        self::$db->query('TRUNCATE TABLE 202_ltv_webhook_deliveries');
        self::$db->query("INSERT INTO 202_ltv_webhook_deliveries
            SET webhook_id=1, user_id=1, event_name='revenue.recorded', payload='{}',
                status='pending', attempts=0, next_attempt_at=1700000000, created_at=1700000000, updated_at=1700000000");
        $deliveryId = (int) $this->scalar('SELECT delivery_id FROM 202_ltv_webhook_deliveries LIMIT 1');

        $repo = new \Prosper202\Ltv\MysqlWebhookRepository(self::$conn);

        // First worker wins the claim; an overlapping worker must lose it.
        self::assertTrue($repo->claimDelivery($deliveryId, 1700000100));
        self::assertFalse($repo->claimDelivery($deliveryId, 1700000100), 'second claim within the window must lose');

        // After the claim window lapses (crashed worker), it is claimable again.
        self::assertTrue($repo->claimDelivery($deliveryId, 1700000100 + 301));
    }

    public function testNegativePayoutConversionLedgersAsAdjustment(): void
    {
        $this->insertClick(5000);
        $this->insertClick(5001);
        $log = $this->log(5000);
        $log['click_payout'] = '-7.5';
        p202RecordConversion(self::$db, $log, '', true, '-7.5', 'NEG-1', ['customer_ref' => 'neg-1'], []);

        $event = $this->row("SELECT conv_id, event_type, amount FROM 202_revenue_events WHERE event_type='adjustment' LIMIT 1");
        self::assertNotNull($event, 'a negative payout must not ledger as a purchase');
        self::assertSame(-7.5, (float) $event['amount']);
        $c = $this->row("SELECT customer_id, order_count, total_revenue FROM 202_customers WHERE primary_ref='neg-1'");
        self::assertSame(0, (int) $c['order_count'], 'negative money never counts an order');
        self::assertSame(-7.5, (float) $c['total_revenue']);
        $customerId = (int) $c['customer_id'];

        // A real purchase for the same customer, then void the negative
        // conversion: its 'void-nc:' marker must not subtract the real order
        // — neither on the immediate path nor when reconciled from scratch.
        p202RecordConversion(self::$db, $this->log(5001), '', true, '10.0', 'NEG-REAL', ['customer_ref' => 'neg-1'], []);
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        (new \Prosper202\Conversion\MysqlConversionRepository(self::$conn))->softDelete((int) $event['conv_id'], 1);

        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_revenue_events WHERE external_ref LIKE 'void-nc:conv:%'"), 'a non-order void carries the void-nc marker');
        self::assertSame(1, (int) $this->scalar("SELECT order_count FROM 202_customers WHERE customer_id=$customerId"), 'the real order survives the void');

        $crm->reconcileCustomer(1, $customerId, 1700001000);
        $after = $this->row("SELECT order_count, total_revenue FROM 202_customers WHERE customer_id=$customerId");
        self::assertSame(1, (int) $after['order_count'], 'ledger reconcile must agree with the live path');
        self::assertSame(10.0, (float) $after['total_revenue'], 'the voided negative payout nets out');
    }

    public function testSoftDeleteNetsLineItemsOutOfProductRevenue(): void
    {
        $this->insertClick(5100);
        $res = p202RecordConversion(self::$db, $this->log(5100), '', true, '10.0', 'DEL-1', ['customer_ref' => 'del-1'], [
            ['sku' => 'SKU-D', 'name' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0, 'amount' => 10.0],
        ]);
        $convId = (int) $res['conv_id'];

        $repo = new \Prosper202\Conversion\MysqlConversionRepository(self::$conn);
        $repo->softDelete($convId, 1);

        $c = $this->row("SELECT order_count, total_revenue FROM 202_customers WHERE primary_ref='del-1'");
        self::assertSame(0, (int) $c['order_count']);
        self::assertSame(0.0, (float) $c['total_revenue']);
        // The void event carries mirrored negated items (amount AND
        // quantity), so product revenue and units net to zero exactly like
        // the customer totals.
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_line_items'));
        self::assertSame(0.0, (float) $this->scalar('SELECT COALESCE(SUM(amount),0) FROM 202_revenue_line_items'));
        self::assertSame(0.0, (float) $this->scalar('SELECT COALESCE(SUM(quantity),0) FROM 202_revenue_line_items'));

        // The product report agrees: units/revenue netted, and the void
        // event is not counted as another order.
        $breakdown = (new \Prosper202\Ltv\MysqlLtvRepository(self::$conn))->breakdown(new \Prosper202\Ltv\LtvQuery(1), 'product', 50, 0);
        self::assertCount(1, $breakdown);
        self::assertSame(1, (int) $breakdown[0]['orders'], 'the void adjustment is not an order');
        self::assertSame(0.0, (float) $breakdown[0]['units']);
        self::assertSame(0.0, (float) $breakdown[0]['total_revenue']);

        // Idempotent: a second delete adds nothing.
        $repo->softDelete($convId, 1);
        self::assertSame(2, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_line_items'));
    }

    public function testRefundLineItemsStoreNegativeAmounts(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $customerId = $customers->resolveOrCreateByAlias(1, 'custom', 'refund-items', [], null, 1700000000);

        $event = $customers->insertRevenueEvent(1, $customerId, [
            'event_type' => 'refund', 'amount' => -12.0, 'currency' => 'USD',
            'occurred_at' => 1700000000, 'source' => 'api', 'idempotency_key' => 'ref-li-1',
        ], 1700000000);
        // The caller sent the item POSITIVE (unit price x quantity) — the
        // event's negative amount decides the stored sign of amount AND
        // quantity, so unit sums net like revenue sums.
        $customers->insertLineItems(1, $event['eventId'], [
            ['sku' => 'SKU-R', 'name' => 'Widget', 'quantity' => 1, 'unit_price' => 12.0, 'amount' => 12.0],
        ], 'USD', 1700000000, -12.0);

        self::assertSame(-12.0, (float) $this->scalar('SELECT SUM(amount) FROM 202_revenue_line_items WHERE event_id=' . (int) $event['eventId']));
        self::assertSame(-1.0, (float) $this->scalar('SELECT SUM(quantity) FROM 202_revenue_line_items WHERE event_id=' . (int) $event['eventId']));
    }

    public function testTrialSubscriptionGainsMrrOnFirstRenewal(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $customerId = $crm->upsert(1, ['customer_ref' => 'trial-1']);
        $subs->upsert(1, ['external_sub_id' => 'SUB-TRIAL', 'amount' => 30.0, 'status' => 'trialing', 'customer_id' => $customerId]);
        self::assertSame(0.0, (float) $this->scalar("SELECT mrr FROM 202_subscriptions WHERE external_sub_id='SUB-TRIAL'"), 'trials carry no MRR');

        // First paid renewal converts the trial: active AND carrying MRR.
        $first = $subs->recordEvent(1, 'SUB-TRIAL', 'renewal', ['idempotency_key' => 'trial-renew-1']);
        self::assertTrue($first['inserted']);
        self::assertTrue($first['changed']);
        $row = $this->row("SELECT status, mrr FROM 202_subscriptions WHERE external_sub_id='SUB-TRIAL'");
        self::assertSame('active', $row['status']);
        self::assertSame(30.0, (float) $row['mrr'], 'conversion from trial must set the recurring figure');
        self::assertSame(30.0, (float) $this->scalar("SELECT mrr FROM 202_customers WHERE customer_id=$customerId"), 'customer rollup sees the converted MRR');

        // A billing-system retry of the same renewal is an idempotent no-op
        // and must report changed=false so no webhook re-fires.
        $replay = $subs->recordEvent(1, 'SUB-TRIAL', 'renewal', ['idempotency_key' => 'trial-renew-1']);
        self::assertFalse($replay['inserted']);
        self::assertFalse($replay['changed'], 'a replayed renewal changes nothing downstream');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_revenue_events WHERE subscription_id IS NOT NULL"), 'one ledger event for one renewal');

        // Same for cancel: the first one changes state, the repeat does not.
        $cancel = $subs->recordEvent(1, 'SUB-TRIAL', 'cancel', []);
        self::assertTrue($cancel['changed']);
        $again = $subs->recordEvent(1, 'SUB-TRIAL', 'cancel', []);
        self::assertFalse($again['changed'], 'a repeat cancel is a no-op');
    }

    public function testPersonalizationMintRespectsAliasType(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $merchant = $customers->resolveOrCreateByAlias(1, 'merchant_id', '123', [], null, 1700000000);
        $custom = $customers->resolveOrCreateByAlias(1, 'custom', '123', [], null, 1700000000);
        self::assertNotSame($merchant, $custom, 'the same value under different types is two identities');

        $p13n = new \Prosper202\Ltv\MysqlPersonalizationRepository(self::$conn);
        self::assertSame($merchant, $p13n->resolveVisitorCustomer(1, ['cust' => '123', 'cust_type' => 'merchant_id'], 0, true));
        self::assertSame($custom, $p13n->resolveVisitorCustomer(1, ['cust' => '123'], 0, true), 'untyped refs default to custom, like ingest');
        self::assertNull($p13n->resolveVisitorCustomer(1, ['cust' => '123', 'cust_type' => 'bogus'], 0, true), 'unknown types resolve nobody');
    }

    public function testCompanyDomainIsUniquePerAccountAndMergeInheritsSafely(): void
    {
        $companies = new MysqlCompanyRepository(self::$conn);

        // The invariant lives in the schema, not just the preflight checks.
        $idx = $this->row("SHOW INDEX FROM 202_companies WHERE Key_name='uniq_user_domain'");
        self::assertNotNull($idx, 'uniq_user_domain key must exist');
        self::assertSame(0, (int) $idx['Non_unique']);

        $withDomain = $companies->create(1, 'Domain Co', 'unique-dom.example.com');
        try {
            $companies->create(1, 'Other Co', 'unique-dom.example.com');
            self::fail('a second company may not claim the same domain');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('already belongs', $e->getMessage());
        }

        // Merge into a domain-less target: the source row must be deleted
        // BEFORE the target inherits its domain, or the unique key fires.
        $target = $companies->create(1, 'Target Co');
        $companies->merge(1, $withDomain, $target);
        self::assertSame('unique-dom.example.com', $this->scalar('SELECT domain FROM 202_companies WHERE company_id=' . $target));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_companies WHERE company_id=' . $withDomain));
    }

    public function testProductBreakdownHonorsCustomFieldFilters(): void
    {
        $this->insertClick(5200);
        $this->insertClick(5201);
        p202RecordConversion(self::$db, $this->log(5200), '', true, '10.0', 'CF-A', ['customer_ref' => 'cf-a'], [
            ['sku' => 'SKU-CF', 'name' => 'Widget', 'quantity' => 1, 'unit_price' => 10.0, 'amount' => 10.0],
        ]);
        $logB = $this->log(5201);
        $logB['click_payout'] = '20.0';
        p202RecordConversion(self::$db, $logB, '', true, '20.0', 'CF-B', ['customer_ref' => 'cf-b'], [
            ['sku' => 'SKU-CF', 'name' => 'Widget', 'quantity' => 2, 'unit_price' => 10.0, 'amount' => 20.0],
        ]);

        $fields = new MysqlCustomerFieldRepository(self::$conn);
        $fieldId = $fields->create(1, ['field_key' => 'tier', 'label' => 'Tier', 'field_type' => 'number']);
        $field = $fields->findByKey(1, 'tier');
        $customerA = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='cf-a'");
        $fields->setValue(1, $customerA, $field, 5);

        $ltv = new \Prosper202\Ltv\MysqlLtvRepository(self::$conn);
        $filtered = $ltv->breakdown(new \Prosper202\Ltv\LtvQuery(1, null, null, [
            ['fieldId' => $fieldId, 'column' => 'value_number', 'op' => '=', 'value' => 5],
        ]), 'product', 50, 0);

        self::assertCount(1, $filtered);
        self::assertSame(10.0, (float) $filtered[0]['total_revenue'], "only the cohort customer's items count");
        self::assertSame(1, (int) $filtered[0]['customers']);

        // Unfiltered still sees both buyers.
        $all = $ltv->breakdown(new \Prosper202\Ltv\LtvQuery(1), 'product', 50, 0);
        self::assertSame(30.0, (float) $all[0]['total_revenue']);
        self::assertSame(2, (int) $all[0]['customers']);
    }

    public function testApiFilterValuesCoerceByFieldType(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $fields = new MysqlCustomerFieldRepository(self::$conn);

        $fields->create(1, ['field_key' => 'signup', 'label' => 'Signup', 'field_type' => 'date']);
        $fields->create(1, ['field_key' => 'vip', 'label' => 'VIP', 'field_type' => 'boolean']);
        $signup = $fields->findByKey(1, 'signup');
        $vip = $fields->findByKey(1, 'vip');

        $early = $crm->upsert(1, ['customer_ref' => 'coerce-early']);
        $late = $crm->upsert(1, ['customer_ref' => 'coerce-late']);
        $fields->setValue(1, $early, $signup, '2025-03-01');
        $fields->setValue(1, $late, $signup, '2026-06-15');
        $fields->setValue(1, $late, $vip, 'true');

        // Through the REAL controller: a date string filter must compare as a
        // Unix timestamp (a blind float cast would read '2026-01-01' as 2026
        // and match everyone), and 'true' must compare as the stored 1.0.
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $filtered = $controller->summary(['cf.signup.min' => '2026-01-01']);
        self::assertSame(1, (int) $filtered['data']['customers'], 'only the 2026 signup matches');

        $vips = $controller->summary(['cf.vip' => 'true']);
        self::assertSame(1, (int) $vips['data']['customers'], "'true' must match the stored boolean 1.0");

        try {
            $controller->summary(['cf.vip' => 'maybe']);
            self::fail('an unparseable boolean filter must be rejected, not silently coerced');
        } catch (\Api\V3\Exception\ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testConversionApiMapsLtvValidationErrorsToClientCodes(): void
    {
        $this->insertClick(6000);
        self::$db->query("INSERT INTO 202_clicks_tracking SET click_id=6000, c1_id=0, c2_id=0, c3_id=0, c4_id=0");
        $controller = new \Api\V3\Controllers\ConversionsController(self::$db, 1);

        // Client-correctable payloads get 422/404, not 500.
        try {
            $controller->create(['click_id' => 6000, 'transaction_id' => 'T-BADTYPE',
                'customer_ref' => 'x-1', 'customer_ref_type' => 'nonsense']);
            self::fail('unknown customer_ref_type must be a validation error');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertStringContainsString('customer_ref_type', $e->getMessage());
        }

        try {
            $controller->create(['click_id' => 6000, 'transaction_id' => 'T-FOREIGN', 'customer_id' => 999999]);
            self::fail('a foreign customer_id must be a 404');
        } catch (\Api\V3\Exception\NotFoundException) {
            $this->addToAssertionCount(1);
        }

        // Neither rejected attempt may leave a conversion behind.
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_conversion_logs'));
    }

    public function testRejectedRevenuePayloadRollsBackFreshIdentity(): void
    {
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);

        // A brand-new customer_ref with a malformed line item: the request
        // must fail AND the just-created customer/alias must roll back with
        // it — no orphan zero-revenue records.
        try {
            $controller->recordRevenue([
                'customer_ref' => 'orphan-check-1',
                'amount' => 25.0,
                'items' => [['sku' => 'SKU-O', 'name' => 'Widget', 'quantity' => 0, 'unit_price' => 25.0]],
            ]);
            self::fail('a non-positive line-item quantity must be rejected');
        } catch (\Api\V3\Exception\ValidationException) {
            $this->addToAssertionCount(1);
        }

        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_customers'), 'identity creation must roll back with the rejected write');
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_customer_aliases'));
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'));

        // The happy path still creates everything.
        $ok = $controller->recordRevenue(['customer_ref' => 'orphan-check-1', 'amount' => 25.0, 'idempotency_key' => 'ok-1']);
        self::assertSame(201, $ok['_status']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_customers'));
    }

    public function testIdempotentReplayDoesNotMintNewIdentity(): void
    {
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $first = $controller->recordRevenue(['customer_ref' => 'idem-a', 'amount' => 10.0, 'idempotency_key' => 'IDEM-1']);
        self::assertSame(201, $first['_status']);

        // Same key, DIFFERENT brand-new ref: the replay must return the
        // original event and its owner, and must not create a customer for
        // a write that never happens.
        $replay = $controller->recordRevenue(['customer_ref' => 'idem-b', 'amount' => 10.0, 'idempotency_key' => 'IDEM-1']);
        self::assertSame(200, $replay['_status']);
        self::assertTrue($replay['data']['duplicate']);
        self::assertSame($first['data']['event_id'], $replay['data']['event_id']);
        self::assertSame($first['data']['customer_id'], $replay['data']['customer_id'], 'the ORIGINAL owner is reported');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_customers'), 'a replay must not mint identity');
    }

    public function testConcurrentIdempotencyRaceLoserRollsBackIdentity(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $winnerId = $customers->resolveOrCreateByAlias(1, 'custom', 'race-winner', [], null, 1700000000);

        // A second PROCESS plays the concurrent winner: it inserts the RACE-1
        // event and holds its transaction open, so the loser's precheck can't
        // see the row while the unique-key insert must wait behind its lock —
        // the only ordering that reaches the lost-race branch.
        $script = tempnam(sys_get_temp_dir(), 'p202race');
        $marker = $script . '.lock';
        $child = <<<'CHILD'
<?php
mysqli_report(MYSQLI_REPORT_STRICT);
$db = mysqli_connect(
    (string) getenv('P202_TEST_DB_HOST'),
    (string) (getenv('P202_TEST_DB_USER') ?: 'root'),
    (string) (getenv('P202_TEST_DB_PASS') ?: ''),
    (string) (getenv('P202_TEST_DB_NAME') ?: 'prosper202'),
    (int) (getenv('P202_TEST_DB_PORT') ?: 3306)
);
$db->query("SET SESSION sql_mode=''");
$db->begin_transaction();
$db->query("INSERT INTO 202_revenue_events
    (user_id, customer_id, event_type, amount, currency, occurred_at, source, idempotency_key, created_at)
    VALUES (1, WINNER_ID, 'purchase', 20, 'USD', 1700000000, 'api', 'RACE-1', 1700000000)");
touch($argv[1]); // signal the parent: the row lock is held
usleep(1500000); // hold it long enough for the loser to block on the key
$db->commit();
CHILD;
        file_put_contents($script, str_replace('WINNER_ID', (string) $winnerId, $child));

        $proc = proc_open(PHP_BINARY . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($marker), [], $pipes);
        if ($proc === false) {
            self::fail('failed to spawn the winner process');
        }
        $deadline = microtime(true) + 5.0;
        while (!file_exists($marker) && microtime(true) < $deadline) {
            usleep(20000);
        }
        if (!file_exists($marker)) {
            self::fail('the winner process never took the row lock');
        }

        // The loser carries a BRAND-NEW ref: precheck misses (winner is
        // uncommitted), identity gets resolved, the insert blocks, loses,
        // and the whole transaction must roll back — reporting the winner.
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $result = $controller->recordRevenue(['customer_ref' => 'race-loser', 'amount' => 5.0, 'idempotency_key' => 'RACE-1']);
        proc_close($proc);
        @unlink($script);
        @unlink($marker);

        self::assertSame(200, $result['_status']);
        self::assertTrue($result['data']['duplicate']);
        self::assertSame($winnerId, $result['data']['customer_id'], 'the loser must report the winner as the owner');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'race-loser'"), 'the losing identity must roll back');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'race-loser'"));
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_revenue_events WHERE idempotency_key = 'RACE-1'"));
    }

    public function testRejectedCustomerUpsertRollsBackFreshIdentity(): void
    {
        $crm = new MysqlCustomerCrmRepository(self::$conn, new MysqlCustomerRepository(self::$conn), new MysqlCustomerFieldRepository(self::$conn));

        // An invalid email is validated AFTER identity resolution — the
        // freshly created customer/alias must roll back with the rejection.
        try {
            $crm->upsert(1, ['customer_ref' => 'atomic-1', 'email' => 'not-an-email']);
            self::fail('invalid email must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('email', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'atomic-1'"), 'rejected upsert must not leave a partial customer');
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'atomic-1'"));

        // Same for an unknown custom field, which is processed last.
        try {
            $crm->upsert(1, ['customer_ref' => 'atomic-2', 'custom_fields' => ['no_such_field' => 1]]);
            self::fail('unknown custom field must be rejected');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'atomic-2'"));

        // The same ref then succeeds cleanly on a valid payload.
        $id = $crm->upsert(1, ['customer_ref' => 'atomic-1', 'email' => 'jane@example.com']);
        self::assertGreaterThan(0, $id);
        self::assertSame('jane@example.com', (string) $this->scalar("SELECT email FROM 202_customers WHERE customer_id = $id"));
    }

    public function testReservedIdempotencyPrefixesRejectedOnApiSurfaces(): void
    {
        // /ltv/revenue: a caller must not be able to pre-claim the key a
        // future soft-delete void will use — that would swallow the
        // compensating adjustment and leave deleted revenue in LTV totals.
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        try {
            $controller->recordRevenue(['customer_ref' => 'reserved-1', 'amount' => 5.0, 'idempotency_key' => 'void:conv:77']);
            self::fail('reserved key must be rejected');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_customers'), 'rejected before identity resolution');

        // Subscription events take caller keys too — same guard.
        $customers = new MysqlCustomerRepository(self::$conn);
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);
        $cid = $customers->resolveOrCreateByAlias(1, 'custom', 'reserved-sub', [], null, 1700000000);
        $subs->upsert(1, ['external_sub_id' => 'SUB-RSV', 'amount' => 10.0, 'customer_id' => $cid]);
        try {
            $subs->recordEvent(1, 'SUB-RSV', 'renewal', ['idempotency_key' => 'backfill:conv:3']);
            self::fail('reserved key must be rejected on subscription events too');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('reserved', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'), 'no renewal was recorded');
    }

    public function testAliasTypesNormalizeOnEveryResolutionPath(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);

        // Case variants of an allowlisted type converge on one customer and
        // one canonical stored type — a literal 'Merchant_ID' row would be
        // unreachable by conversion and personalization lookups.
        $a = $customers->resolveOrCreateByAlias(1, 'Merchant_ID', 'norm-1', [], null, 1700000000);
        $b = $customers->resolveOrCreateByAlias(1, 'merchant_id', 'norm-1', [], null, 1700000000);
        self::assertSame($a, $b, 'case variants must resolve to the same customer');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'norm-1'"));
        self::assertSame('merchant_id', (string) $this->scalar("SELECT alias_type FROM 202_customer_aliases WHERE alias_value = 'norm-1'"));

        // Unsupported types are rejected before any identity is minted.
        try {
            $customers->resolveOrCreateByAlias(1, 'nonsense', 'norm-2', [], null, 1700000000);
            self::fail('unsupported alias type must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('customer_ref_type', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'norm-2'"), 'a rejected type must not mint identity');
    }

    public function testCacSegmentsSearchAndCohortsEndToEnd(): void
    {
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=7, user_id=1,
            aff_campaign_name='Offer A', aff_campaign_url='https://example.com/a'");

        // Four \$2.50 clicks on campaign 7; two convert into customers.
        $this->insertClick(8000, 20.0, 2.5);
        $this->insertClick(8001, 10.0, 2.5);
        $this->insertClick(8002, 0.0, 2.5);
        $this->insertClick(8003, 0.0, 2.5);
        $log = $this->log(8000);
        $log['click_payout'] = '20.0';
        p202RecordConversion(self::$db, $log, '', true, '20.0', 'CAC-1', [
            'customer_ref' => 'cac-1', 'customer_crm' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
        ], []);
        p202RecordConversion(self::$db, $this->log(8001), '', true, '10.0', 'CAC-2', ['customer_ref' => 'cac-2'], []);

        $ltv = new \Prosper202\Ltv\MysqlLtvRepository(self::$conn);

        // LTV:CAC — $10 ad spend acquired 2 customers worth $30 lifetime:
        // CAC $5, 3.0x return per ad dollar.
        $rows = $ltv->breakdown(new \Prosper202\Ltv\LtvQuery(1), 'campaign', 50, 0);
        self::assertCount(1, $rows);
        self::assertSame(10.0, (float) $rows[0]['spend']);
        self::assertSame(5.0, (float) $rows[0]['cac']);
        self::assertSame(3.0, (float) $rows[0]['ltv_cac']);

        // Search matches name (also ref/email/company); misses return empty.
        $found = $ltv->customers(new \Prosper202\Ltv\LtvQuery(1), 'total_revenue', 'DESC', 50, 0, 'jane');
        self::assertSame(1, $found['total']);
        self::assertSame('cac-1', $found['rows'][0]['primary_ref']);
        self::assertSame(0, $ltv->customers(new \Prosper202\Ltv\LtvQuery(1), 'total_revenue', 'DESC', 50, 0, 'zzz-nobody')['total']);

        // Segments: one active subscriber, one past-due (= at risk).
        $customers = new MysqlCustomerRepository(self::$conn);
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);
        $c1 = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='cac-1'");
        $c2 = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='cac-2'");
        $subs->upsert(1, ['external_sub_id' => 'SEG-ACTIVE', 'amount' => 30.0, 'customer_id' => $c1]);
        $subs->upsert(1, ['external_sub_id' => 'SEG-DUE', 'amount' => 20.0, 'status' => 'past_due', 'customer_id' => $c2]);

        self::assertSame(1, $ltv->customers(new \Prosper202\Ltv\LtvQuery(1), 'total_revenue', 'DESC', 50, 0, null, 'subscribers')['total']);
        $atRisk = $ltv->customers(new \Prosper202\Ltv\LtvQuery(1), 'total_revenue', 'DESC', 50, 0, null, 'at_risk');
        self::assertSame(1, $atRisk['total']);
        self::assertSame('cac-2', $atRisk['rows'][0]['primary_ref']);
        self::assertSame(0, $ltv->customers(new \Prosper202\Ltv\LtvQuery(1), 'total_revenue', 'DESC', 50, 0, null, 'repeat')['total']);

        // Cohorts: both acquired Nov 2023; $30 lands in month 0, a later $15
        // repeat purchase 40 days on lands in month 1.
        self::$db->query('UPDATE 202_customers SET first_seen_time=1700000000');
        $customers->insertRevenueEvent(1, $c1, [
            'event_type' => 'purchase', 'amount' => 15.0, 'currency' => 'USD',
            'occurred_at' => 1700000100 + 40 * 86400, 'source' => 'api', 'idempotency_key' => 'cohort-m1',
        ], 1700000100);
        $cohorts = $ltv->cohorts(1, 6, 1702000000);
        self::assertCount(1, $cohorts);
        self::assertSame('2023-11', $cohorts[0]['cohort_month']);
        self::assertSame(2, (int) $cohorts[0]['customers']);
        self::assertSame(30.0, (float) $cohorts[0]['m0']);
        self::assertSame(15.0, (float) $cohorts[0]['m1']);
        self::assertSame(45.0, (float) $cohorts[0]['total_revenue']);
        self::assertSame(22.5, (float) $cohorts[0]['ltv_per_customer']);
    }

    public function testNextOfferRebuildAndScoringEndToEnd(): void
    {
        foreach ([[7, 'Offer A'], [8, 'Offer B'], [9, 'Offer C'], [10, 'Offer D']] as [$id, $name]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id={$id}, user_id=1,
                aff_campaign_name='{$name}', aff_campaign_url='https://example.com/offer-{$id}'");
        }

        // Purchase journeys (campaign comes from the conversion log):
        //  c1: A then B            (A->B adjacent)
        //  c2: A then C then B     (A->C and C->B adjacent, A->B eventual)
        //  c3: A then D, D voided  (deleted sales must leave no transitions)
        $buy = function (int $clickId, string $ref, int $campaignId, int $convTime, string $tx): int {
            $this->insertClick($clickId);
            $log = $this->log($clickId);
            $log['campaign_id'] = (string) $campaignId;
            $log['conv_time'] = $convTime;
            $result = p202RecordConversion(self::$db, $log, '', true, '10.0', $tx, ['customer_ref' => $ref], []);

            return (int) $result['conv_id'];
        };
        $buy(7000, 'no-c1', 7, 1700000100, 'NO-1');
        $buy(7001, 'no-c1', 8, 1700000200, 'NO-2');
        $buy(7002, 'no-c2', 7, 1700000100, 'NO-3');
        $buy(7003, 'no-c2', 9, 1700000200, 'NO-4');
        $buy(7004, 'no-c2', 8, 1700000300, 'NO-5');
        $buy(7005, 'no-c3', 7, 1700000100, 'NO-6');
        $voidedConv = $buy(7006, 'no-c3', 10, 1700000200, 'NO-7');
        (new \Prosper202\Conversion\MysqlConversionRepository(self::$conn))->softDelete($voidedConv, 1);

        $repo = new \Prosper202\Ltv\MysqlRecommendationRepository(self::$conn);
        self::assertGreaterThan(0, $repo->rebuildTransitions(1, 1700001000));

        // A->B: c1 direct + c2 eventual (C sits between); everyone bought A.
        $ab = $this->row('SELECT * FROM 202_offer_transitions WHERE from_campaign_id=7 AND to_campaign_id=8');
        self::assertSame(2, (int) $ab['transition_count']);
        self::assertSame(1, (int) $ab['adjacent_count'], "c2's A->B went through C — eventual, not adjacent");
        self::assertSame(3, (int) $ab['from_customers']);
        self::assertSame(1700000300, (int) $ab['last_seen_at']);
        self::assertSame(0, (int) $this->scalar('SELECT COUNT(*) FROM 202_offer_transitions WHERE to_campaign_id=10'), 'voided sales feed no transitions');

        // c3 converted only on A (their D purchase was voided): both B and C
        // qualify; B wins on blended confidence x lift x value.
        $customerId = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='no-c3'");
        $offer = $repo->nextOffer(1, $customerId, 1700001000);
        self::assertNotNull($offer);
        self::assertSame(8, $offer['campaign_id']);
        self::assertSame('Offer B', $offer['name']);
        self::assertSame('https://example.com/offer-8', $offer['url']);
        self::assertSame('transition', $offer['why']['basis']);
        self::assertSame(1, $offer['why']['direct_transitions']);
        self::assertSame(1, $offer['why']['eventual_transitions']);
        self::assertSame([7], $offer['why']['based_on_campaigns']);
        self::assertSame('expected_value', $offer['why']['ranked_by'], 'purchases carry amounts, so ranking is by expected value');
        self::assertSame(10.0, (float) $offer['why']['avg_order_value']);

        // A customer who bought everything transitionable falls back to the
        // account's top campaign minus their history — here: nothing new.
        $c2 = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref='no-c2'");
        $fallback = $repo->nextOffer(1, $c2, 1700001000);
        self::assertNull($fallback, 'c2 already bought A, B and C — nothing left to suggest');
    }

    public function testNextOfferColdStartTiersForCustomersWithoutConversionHistory(): void
    {
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=7, user_id=1,
            aff_campaign_name='Offer A', aff_campaign_url='https://example.com/a'");

        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $recs = new \Prosper202\Ltv\MysqlRecommendationRepository(self::$conn);

        // The majority case: revenue arrives via API/subscriptions, so there
        // is no conversion-linked history — but the customer's stamped clicks
        // show live interest in campaign 7.
        $browser = $crm->upsert(1, ['customer_ref' => 'cold-browser']);
        $this->insertClick(9100, 0.0);
        self::$db->query("INSERT INTO 202_clicks_tracking SET click_id=9100, c1_id=0, c2_id=0, c3_id=0, c4_id=0, customer_id=$browser");

        $offer = $recs->nextOffer(1, $browser, 1700000100);
        self::assertNotNull($offer);
        self::assertSame(7, $offer['campaign_id']);
        self::assertSame('engagement', $offer['why']['basis'] ?? null, 'their own browsing beats the account average');

        // No signal at all -> the account's top campaign of the RECENT window.
        // Campaign 9 has MORE recent conversion rows, but they are negative-
        // payout corrections — they must neither rank nor qualify it.
        $blank = $crm->upsert(1, ['customer_ref' => 'cold-blank']);
        self::$db->query('INSERT INTO 202_conversion_logs SET click_id=9101, user_id=1, campaign_id=7, conv_time=1700000000, deleted=0');
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=9, user_id=1,
            aff_campaign_name='Refund Trap', aff_campaign_url='https://example.com/9'");
        self::$db->query('INSERT INTO 202_conversion_logs SET click_id=9102, user_id=1, campaign_id=9, conv_time=1700000050, deleted=0, click_payout=-10');
        self::$db->query('INSERT INTO 202_conversion_logs SET click_id=9103, user_id=1, campaign_id=9, conv_time=1700000060, deleted=0, click_payout=-10');
        $offer = $recs->nextOffer(1, $blank, 1700000100);
        self::assertNotNull($offer);
        self::assertSame(7, $offer['campaign_id'], 'corrections-only campaigns never win the fallback');
        self::assertSame('account_top_recent', $offer['why']['basis'] ?? null);

        // ...but never a stale one: when every conversion predates the
        // window, the honest answer is null ("not enough data"), not a
        // retired campaign.
        self::assertNull($recs->nextOffer(1, $blank, 1700000100 + 200 * 86400), 'no recent signal means no suggestion');

        // Deleted campaigns are invisible to every tier, browsing included.
        self::$db->query('UPDATE 202_aff_campaigns SET aff_campaign_deleted=1 WHERE aff_campaign_id=7');
        self::assertNull($recs->nextOffer(1, $browser, 1700000100), 'a deleted campaign must never be suggested');
    }

    public function testSealWinnerRecordsExactlyOneImpression(): void
    {
        $now = time();
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=7, user_id=1,
            aff_campaign_name='Offer A', aff_campaign_url='https://example.com/a'");
        self::$db->query('INSERT INTO 202_conversion_logs SET click_id=9300, user_id=1, campaign_id=7, conv_time=' . ($now - 86400) . ', deleted=0');
        self::$db->query('INSERT IGNORE INTO 202_users_pref SET user_id=1');
        self::$db->query("UPDATE 202_users_pref SET user_ltv_personalization_fields='rec:next_offer' WHERE user_id=1");

        try {
            $customers = new MysqlCustomerRepository(self::$conn);
            $cid = $customers->resolveOrCreateByAlias(1, 'custom', 'seal-imp-1', [], null, $now);
            $p13n = new \Prosper202\Ltv\MysqlPersonalizationRepository(self::$conn);
            $token = $p13n->mint(1, $cid, 0, $now);

            // Winning the seal records the impression — once.
            $payload = $p13n->redeem($token, $now);
            self::assertSame('Offer A', $payload['next_offer_name'] ?? null);
            self::assertSame(1, (int) $this->scalar("SELECT COALESCE(SUM(times_shown), 0) FROM 202_offer_recommendations WHERE customer_id=$cid"));

            // Sealed replays render the same snapshot without counting again.
            $replay = $p13n->redeem($token, $now + 60);
            self::assertSame('Offer A', $replay['next_offer_name'] ?? null);
            self::assertSame(1, (int) $this->scalar("SELECT COALESCE(SUM(times_shown), 0) FROM 202_offer_recommendations WHERE customer_id=$cid"), 'replays never add impressions');
        } finally {
            self::$db->query("UPDATE 202_users_pref SET user_ltv_personalization_fields='' WHERE user_id=1");
        }
    }

    public function testRecommendationFatigueLifecycleEndToEnd(): void
    {
        foreach ([[7, 'Offer A'], [8, 'Offer B']] as [$id, $name]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=$id, user_id=1,
                aff_campaign_name='$name', aff_campaign_url='https://example.com/$id'");
        }

        $now = 1700000000 + 40 * 86400;
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $recs = new \Prosper202\Ltv\MysqlRecommendationRepository(self::$conn);

        // The customer browses campaign 7 (stamped click) but never buys.
        $cid = $crm->upsert(1, ['customer_ref' => 'fat-1']);
        $this->insertClick(9200, 0.0);
        self::$db->query("INSERT INTO 202_clicks_tracking SET click_id=9200, c1_id=0, c2_id=0, c3_id=0, c4_id=0, customer_id=$cid");
        // Campaign 8 converts recently, so the account fallback has a target.
        self::$db->query('INSERT INTO 202_conversion_logs SET click_id=9209, user_id=1, campaign_id=8, conv_time=' . ($now - 10 * 86400) . ', deleted=0');

        $offer = $recs->nextOffer(1, $cid, $now);
        self::assertSame(7, $offer['campaign_id'] ?? null, 'browsing interest picks campaign 7');

        // Shown on three visits, first 25 days ago: default fatigue (3, 21)
        // abandons it and the runner-up (recent account top) takes over.
        foreach ([25, 24, 23] as $daysAgo) {
            $recs->recordImpression(1, $cid, 7, 'lp', 'engagement', $now - $daysAgo * 86400);
        }
        self::assertSame(3, (int) $this->scalar("SELECT times_shown FROM 202_offer_recommendations WHERE customer_id=$cid AND campaign_id=7 AND surface='lp'"));

        $offer = $recs->nextOffer(1, $cid, $now);
        self::assertSame(8, $offer['campaign_id'] ?? null, 'the fatigued offer is abandoned for the runner-up');
        self::assertSame('account_top_recent', $offer['why']['basis'] ?? null);
        self::assertSame([7], $offer['why']['suppressed_campaigns'] ?? null);

        // A fresh click on campaign 7 AFTER it was last shown resets fatigue.
        $freshTime = $now - 86400;
        self::$db->query("INSERT INTO 202_clicks SET click_id=9201, user_id=1, aff_campaign_id=7, click_payout=0, click_cpc=0, click_lead=0, click_time=$freshTime");
        self::$db->query("INSERT INTO 202_clicks_spy SET click_id=9201, user_id=1, aff_campaign_id=7, click_payout=0, click_cpc=0, click_lead=0, click_time=$freshTime");
        self::$db->query("INSERT INTO 202_clicks_tracking SET click_id=9201, c1_id=0, c2_id=0, c3_id=0, c4_id=0, customer_id=$cid");
        $offer = $recs->nextOffer(1, $cid, $now);
        self::assertSame(7, $offer['campaign_id'] ?? null, 'fresh engagement lifts the suppression');
        self::assertSame('engagement', $offer['why']['basis'] ?? null);

        // API surface: an external sender logs the delivery explicitly.
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $logged = $controller->recordNextOfferImpression($cid, []);
        self::assertSame(201, $logged['_status']);
        self::assertSame(7, $logged['data']['campaign_id']);
        self::assertSame(1, (int) $this->scalar("SELECT times_shown FROM 202_offer_recommendations WHERE customer_id=$cid AND campaign_id=7 AND surface='api'"));

        // They finally buy campaign 7: the maintenance join stamps the
        // conversion onto the decision log (the bandit reward signal), and
        // the campaign leaves fatigue consideration for good.
        $log = $this->log(9201);
        $log['click_time'] = $freshTime;
        $log['conv_time'] = $freshTime + 100; // after first_shown_at — the reward window
        p202RecordConversion(self::$db, $log, '', true, '25.0', 'FAT-1', ['customer_ref' => 'fat-1'], []);
        self::assertGreaterThan(0, $recs->stampRecommendationConversions(1));
        self::assertNotNull($this->scalar("SELECT converted_at FROM 202_offer_recommendations WHERE customer_id=$cid AND campaign_id=7 AND surface='lp'"));

        // Fatigue disabled by pref: campaign 7 is the account-top tie-winner
        // for a signal-less customer, and it carries 3 stale impressions —
        // with fatigue on it would be suppressed; with the pref at 0 it wins.
        self::$db->query('INSERT IGNORE INTO 202_users_pref SET user_id=1');
        self::$db->query("UPDATE 202_users_pref SET user_ltv_rec_fatigue='0' WHERE user_id=1");
        try {
            $cid2 = $crm->upsert(1, ['customer_ref' => 'fat-2']);
            foreach ([25, 24, 23] as $daysAgo) {
                $recs->recordImpression(1, $cid2, 7, 'lp', 'account_top_recent', $now - $daysAgo * 86400);
            }
            $offer = $recs->nextOffer(1, $cid2, $now);
            self::assertSame(7, $offer['campaign_id'] ?? null, 'pref 0 disables fatigue entirely');
            self::assertFalse(isset($offer['why']['suppressed_campaigns']), 'nothing is suppressed when fatigue is off');
        } finally {
            self::$db->query("UPDATE 202_users_pref SET user_ltv_rec_fatigue='' WHERE user_id=1");
        }
    }

    public function testCanceledSubscriptionInsertCountsTowardChurn(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);
        $customerId = $crm->upsert(1, ['customer_ref' => 'import-cancel']);

        // A first-time import of an ALREADY-canceled subscription must stamp
        // canceled_at, or the trailing-churn window never sees it.
        $subs->upsert(1, ['external_sub_id' => 'SUB-CANCELED', 'amount' => 20.0, 'status' => 'canceled', 'customer_id' => $customerId]);
        self::assertNotNull($this->scalar("SELECT canceled_at FROM 202_subscriptions WHERE external_sub_id='SUB-CANCELED'"));

        $mrr = (new \Prosper202\Ltv\MysqlLtvRepository(self::$conn))->mrr(1);
        self::assertSame(1, (int) $mrr['churn_inputs']['canceled_in_window']);
    }
}
