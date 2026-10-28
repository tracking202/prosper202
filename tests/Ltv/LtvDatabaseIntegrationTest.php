<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Identity\IdentityGraph;
use Prosper202\Identity\IdentityKeys;
use Prosper202\Identity\IdentitySignal;
use Prosper202\Identity\SignalType;
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

    private function insertClick(int $clickId, float $payout = 10.0, float $cpc = 0.0, int $campaignId = 7): void
    {
        $db = self::$db;
        $db->query("INSERT INTO 202_clicks SET click_id=$clickId, user_id=1, aff_campaign_id=$campaignId, click_payout=$payout, click_cpc=$cpc, click_lead=0, click_time=1700000000");
        $db->query("INSERT INTO 202_clicks_spy SET click_id=$clickId, user_id=1, aff_campaign_id=$campaignId, click_payout=$payout, click_cpc=$cpc, click_lead=0, click_time=1700000000");
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
            'once_per_click' => false,
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

        $result = p202RecordConversion(self::$db, $this->log(2000), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'ORD-LTV-1', [
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

        $first = p202RecordConversion(self::$db, $this->log(2000), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'ORD-A', $customer, []);
        // Exact replay: no new conversion, no new ledger event, rollups unchanged.
        $replay = p202RecordConversion(self::$db, $this->log(2000), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'ORD-A', $customer, []);
        self::assertTrue($replay['duplicate']);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'));

        // A second purchase on the same subid WITHOUT a customer ref resolves
        // through the cached click link to the same customer. The ledger
        // amount comes from the log's payout.
        $secondLog = $this->log(2000);
        $secondLog['click_payout'] = '15.0';
        $second = p202RecordConversion(self::$db, $secondLog, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '15.0', 'ORD-B', [], []);
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
        p202RecordConversion(self::$db, $this->log(3000), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'M-1', ['customer_ref' => 'merge-src', 'customer_crm' => ['company' => 'Solo Inc']], []);
        $dstLog = $this->log(3001);
        $dstLog['click_payout'] = '20.0';
        p202RecordConversion(self::$db, $dstLog, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '20.0', 'M-2', ['customer_ref' => 'merge-dst'], []);

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
        p202RecordConversion(self::$db, $log, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '-7.5', 'NEG-1', ['customer_ref' => 'neg-1'], []);

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
        p202RecordConversion(self::$db, $this->log(5001), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'NEG-REAL', ['customer_ref' => 'neg-1'], []);
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
        $res = p202RecordConversion(self::$db, $this->log(5100), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'DEL-1', ['customer_ref' => 'del-1'], [
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

    /**
     * A caller's idempotency key is compared exactly: `Order-A` and
     * `order-a` are two requests. Under the table's case-insensitive
     * collation the second was a replay of the first and its revenue was
     * dropped (CLAUDE.md #17).
     */
    public function testIdempotencyKeysThatDifferOnlyInCaseAreTwoEvents(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $customerId = $customers->resolveOrCreateByAlias(1, 'custom', 'case-keys', [], null, 1700000000);
        $event = static fn (string $key, float $amount): array => [
            'event_type' => 'purchase', 'amount' => $amount, 'currency' => 'USD',
            'occurred_at' => 1700000000, 'source' => 'api', 'idempotency_key' => $key,
        ];

        $upper = $customers->insertRevenueEvent(1, $customerId, $event('Order-A', 10.0), 1700000000);
        $lower = $customers->insertRevenueEvent(1, $customerId, $event('order-a', 7.0), 1700000000);

        self::assertTrue($upper['inserted']);
        self::assertTrue($lower['inserted'], 'order-a is not a replay of Order-A');
        self::assertNotSame($upper['eventId'], $lower['eventId']);
        $found = $customers->findEventByIdempotencyKey(1, 'order-a');
        self::assertNotNull($found);
        self::assertSame(
            ['event_id' => $lower['eventId'], 'customer_id' => $customerId, 'amount' => '7.00000'],
            array_intersect_key($found, ['event_id' => true, 'customer_id' => true, 'amount' => true])
        );
        self::assertSame(17.0, (float) $this->scalar("SELECT SUM(amount) FROM 202_revenue_events WHERE user_id=1 AND idempotency_key IN ('Order-A', 'order-a')"));

        $replay = $customers->insertRevenueEvent(1, $customerId, $event('order-a', 7.0), 1700000000);
        self::assertFalse($replay['inserted'], 'each still deduplicates against itself');
        self::assertSame($lower['eventId'], $replay['eventId']);
    }

    /** External ids the merchant or billing system chose are compared exactly, like idempotency keys. */
    public function testExternalIdsThatDifferOnlyInCaseAreTwoRecords(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $customerId = $customers->resolveOrCreateByAlias(1, 'custom', 'case-ext', [], null, 1700000000);
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $upper = $subs->upsert(1, ['external_sub_id' => 'sub_Ab1', 'amount' => 30.0, 'customer_id' => $customerId]);
        $lower = $subs->upsert(1, ['external_sub_id' => 'sub_ab1', 'amount' => 10.0, 'customer_id' => $customerId]);
        self::assertNotSame($upper['subscriptionId'], $lower['subscriptionId'], 'sub_ab1 is not an update of sub_Ab1');
        self::assertSame(2, (int) $this->scalar("SELECT COUNT(*) FROM 202_subscriptions WHERE user_id=1 AND external_sub_id IN ('sub_Ab1', 'sub_ab1')"));
        self::assertSame($lower['subscriptionId'], $subs->upsert(1, ['external_sub_id' => 'sub_ab1', 'amount' => 12.0, 'customer_id' => $customerId])['subscriptionId'], 'and each still upserts itself');

        $a = $customers->upsertProduct(1, ['external_product_id' => 'Var-9'], 'USD', 1700000000);
        $b = $customers->upsertProduct(1, ['external_product_id' => 'var-9'], 'USD', 1700000000);
        self::assertNotSame($a, $b, 'var-9 is not Var-9');
        self::assertSame($b, $customers->upsertProduct(1, ['external_product_id' => 'var-9'], 'USD', 1700000000));
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

    public function testSubscriptionIdempotencyKeysAreScopedPerSubscriptionAndEvent(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $customerId = $crm->upsert(1, ['customer_ref' => 'scoped-key-1']);

        // The account already spent this key on the /ltv/revenue surface
        // (stored verbatim, account-wide uniqueness on (user_id, key)).
        $customers->insertRevenueEvent(1, $customerId, [
            'event_type' => 'one_time', 'amount' => 5.0, 'currency' => 'USD',
            'occurred_at' => 1700000000, 'source' => 'api', 'idempotency_key' => 'shared-key-1',
        ], 1700000000);

        $subs->upsert(1, ['external_sub_id' => 'SUB-SCOPED', 'amount' => 20.0, 'status' => 'active', 'customer_id' => $customerId]);

        // A renewal reusing the same caller key must NOT read as a replay of
        // the unrelated revenue event: the period advances and MRR flows.
        $renewal = $subs->recordEvent(1, 'SUB-SCOPED', 'renewal', ['idempotency_key' => 'shared-key-1']);
        self::assertTrue($renewal['inserted'], 'a revenue-surface key must not swallow a subscription renewal');
        self::assertTrue($renewal['changed']);
        $stored = (string) $this->scalar('SELECT idempotency_key FROM 202_revenue_events WHERE event_id=' . (int) $renewal['eventId']);
        self::assertSame('sub:' . $renewal['subscriptionId'] . ':renewal:key:shared-key-1', $stored, 'caller keys are stored scoped');

        // Replaying the SAME renewal with the same key still dedupes.
        $replay = $subs->recordEvent(1, 'SUB-SCOPED', 'renewal', ['idempotency_key' => 'shared-key-1']);
        self::assertFalse($replay['inserted']);
        self::assertFalse($replay['changed']);
        self::assertSame($renewal['eventId'], $replay['eventId']);

        // A refund with the same key is a DIFFERENT event, not a replay of
        // the renewal (per-event scoping), and oversized keys hash instead of
        // overflowing the varchar(191) column.
        $refund = $subs->recordEvent(1, 'SUB-SCOPED', 'refund', ['idempotency_key' => 'shared-key-1', 'amount' => 20.0]);
        self::assertTrue($refund['inserted'], 'same key on a different event type is a new event');
        $longKey = str_repeat('k', 200);
        $long = $subs->recordEvent(1, 'SUB-SCOPED', 'renewal', ['idempotency_key' => $longKey]);
        self::assertTrue($long['inserted']);
        $storedLong = (string) $this->scalar('SELECT idempotency_key FROM 202_revenue_events WHERE event_id=' . (int) $long['eventId']);
        self::assertSame('sub:' . $long['subscriptionId'] . ':renewal:keyh:' . hash('sha256', $longKey), $storedLong);
        $longReplay = $subs->recordEvent(1, 'SUB-SCOPED', 'renewal', ['idempotency_key' => $longKey]);
        self::assertFalse($longReplay['inserted'], 'hashed long keys still dedupe deterministically');
    }

    public function testSubscriptionTransactionIdFallbackKeyHashesWhenOversized(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);

        $customerId = $crm->upsert(1, ['customer_ref' => 'txid-fallback-1']);
        $subs->upsert(1, ['external_sub_id' => 'SUB-TXID', 'amount' => 20.0, 'status' => 'active', 'customer_id' => $customerId]);

        // No explicit idempotency_key: the transaction_id is the natural key. A
        // billing id near the ledger's 255-char transaction_id limit must not
        // overflow idempotency_key's varchar(191) and fail the renewal insert.
        $longTxn = str_repeat('T', 240);
        $renewal = $subs->recordEvent(1, 'SUB-TXID', 'renewal', ['transaction_id' => $longTxn]);
        self::assertTrue($renewal['inserted'], 'an oversized transaction id must still record the renewal');
        $stored = (string) $this->scalar('SELECT idempotency_key FROM 202_revenue_events WHERE event_id=' . (int) $renewal['eventId']);
        self::assertSame('sub:' . $renewal['subscriptionId'] . ':renewal:txnh:' . hash('sha256', $longTxn), $stored);
        self::assertTrue(strlen($stored) <= 191, 'the stored key fits the varchar(191) column');

        // The same transaction id replays deterministically (still dedupes).
        $replay = $subs->recordEvent(1, 'SUB-TXID', 'renewal', ['transaction_id' => $longTxn]);
        self::assertFalse($replay['inserted'], 'hashed transaction-id keys still dedupe');
        self::assertSame($renewal['eventId'], $replay['eventId']);

        // A short transaction id keeps the plain, un-hashed scoped format.
        $short = $subs->recordEvent(1, 'SUB-TXID', 'renewal', ['transaction_id' => 'TXN-SHORT']);
        self::assertTrue($short['inserted']);
        $storedShort = (string) $this->scalar('SELECT idempotency_key FROM 202_revenue_events WHERE event_id=' . (int) $short['eventId']);
        self::assertSame('sub:' . $short['subscriptionId'] . ':renewal:TXN-SHORT', $storedShort);
    }

    public function testRequiredCustomFieldsAreEnforcedOnCustomerSaves(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $fields = new MysqlCustomerFieldRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, $fields);

        $fields->create(1, ['field_key' => 'plan_tier', 'label' => 'Plan Tier', 'field_type' => 'text', 'is_required' => true]);

        // A save that omits the required field is rejected — and because the
        // whole upsert is one transaction, the freshly created customer rolls
        // back with it (no partial record).
        try {
            $crm->upsert(1, ['customer_ref' => 'req-1', 'first_name' => 'Ada']);
            self::fail('a save omitting a required custom field must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('required', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref='req-1'"), 'rejected save leaves no partial record');

        // Supplying it succeeds and stores the value.
        $id = $crm->upsert(1, ['customer_ref' => 'req-1', 'custom_fields' => ['plan_tier' => 'gold']]);
        self::assertSame('gold', $this->scalar('SELECT value_text FROM 202_customer_field_values WHERE customer_id=' . $id));

        // A later partial update that doesn't touch the field still passes —
        // the required value is already stored.
        $crm->upsert(1, ['customer_id' => $id, 'first_name' => 'Ada']);
        self::assertSame('Ada', $this->scalar('SELECT first_name FROM 202_customers WHERE customer_id=' . $id));

        // Clearing the required field to blank is rejected (it would delete the
        // stored value), and the existing value survives the rejected save.
        try {
            $crm->upsert(1, ['customer_id' => $id, 'custom_fields' => ['plan_tier' => '']]);
            self::fail('clearing a required field must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('required', $e->getMessage());
        }
        self::assertSame('gold', $this->scalar('SELECT value_text FROM 202_customer_field_values WHERE customer_id=' . $id), 'the required value survives the rejected clear');
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

    /**
     * Erasing a customer reaches the identity graph (plan §7.2): the signal
     * the customer's id became, and every observation of it, are gone, so the
     * id no longer maps to a visitor key; the merge it caused keeps its row
     * (the worker's re-queue) but loses the hash. Another customer's signal,
     * and a signal of another kind, are untouched.
     */
    public function testEraseRemovesTheCustomersIdentitySignals(): void
    {
        foreach (['signals', 'observations', 'merges', 'keys'] as $table) {
            self::$db->query('TRUNCATE TABLE 202_identity_' . $table);
        }
        $customers = new MysqlCustomerRepository(self::$conn);
        $erased = $customers->resolveOrCreateByAlias(1, 'merchant_id', 'm-100', [], null, 1700000000);
        $customers->addAlias(1, $erased, 'email_md5', str_repeat('ab', 16), 1700000000);
        $kept = $customers->resolveOrCreateByAlias(1, 'merchant_id', 'm-200', [], null, 1700000000);

        $hashKey = (new IdentityKeys(self::$conn))->forUser(1)['hash'];
        $hash = static fn (string $canonical, SignalType $type = SignalType::CUSTOMER): string
            => IdentityGraph::hash($hashKey, new IdentitySignal($type, $canonical));
        $signals = [
            'erased-merchant' => $hash('merchant_id:m-100'),
            'erased-email' => $hash('email_md5:' . str_repeat('ab', 16)),
            'kept' => $hash('merchant_id:m-200'),
        ];
        $cookie = $hash('merchant_id:m-100', SignalType::VISITOR_COOKIE);
        $clickId = 9100;
        foreach ($signals + ['cookie' => $cookie] as $name => $h) {
            $type = $name === 'cookie' ? 'vid' : 'cust';
            self::$db->query(
                'INSERT INTO 202_identity_signals (user_id, signal_type, signal_hash, visitor_key, merges, created_at)'
                . " VALUES (1, '$type', '$h', 5, 0, 1700000000)"
            );
            self::$db->query(
                'INSERT INTO 202_identity_observations (click_id, signal_type, signal_hash, observed_at)'
                . ' VALUES (' . ($clickId++) . ", '$type', '$h', 1700000000)"
            );
        }
        self::$db->query(
            'INSERT INTO 202_identity_merges'
            . ' (user_id, from_key, into_key, click_id, signal_type, signal_hash, merged_at)'
            . " VALUES (1, 6, 5, 9100, 'cust', '{$signals['erased-merchant']}', 1700000000)"
        );

        $crm = new MysqlCustomerCrmRepository($conn = self::$conn, $customers, new MysqlCustomerFieldRepository($conn));
        $crm->erase(1, $erased);

        $count = fn (string $table, string $where): string
            => (string) $this->scalar("SELECT COUNT(*) FROM $table WHERE $where");
        foreach (['erased-merchant', 'erased-email'] as $name) {
            $h = $signals[$name];
            self::assertSame('0', $count('202_identity_signals', "signal_hash = '$h'"), "the $name signal is gone");
            self::assertSame('0', $count('202_identity_observations', "signal_hash = '$h'"), "its observations too");
        }
        $merge = $this->row('SELECT signal_hash, requeued_at FROM 202_identity_merges WHERE click_id = 9100');
        self::assertSame('erased', $merge['signal_hash'] ?? null, 'the merge row stays, without the hash');
        self::assertIsArray($merge, 'the merge row is still there');
        self::assertArrayHasKey('requeued_at', $merge);
        self::assertNull($merge['requeued_at'], 'and its re-queue is still pending');
        $signal = static fn (string $h): string => "signal_hash = '$h'";
        self::assertSame('1', $count('202_identity_signals', $signal($signals['kept'])), 'the other customer stays');
        self::assertSame('1', $count('202_identity_signals', $signal($cookie)), 'another kind of signal stays');
        self::assertSame('2', $count('202_identity_observations', '1'), 'only the erased observations went');
        self::assertSame('0', $count('202_customer_aliases', "customer_id = $erased"), 'the aliases are gone too');
        self::assertSame('1', $count('202_customer_aliases', "customer_id = $kept"));
    }

    /**
     * The DELETE previews (`?dry_run=1` on the /ltv deletes) read through
     * the same repositories the deletes write through. Each preview's answer
     * is checked here against what its delete then actually does, on real
     * rows: the counts it reports are the rows that go (or stay), and a
     * refusal it reports is the delete's own.
     */
    public function testDeletePreviewsReportWhatTheDeletesThenDo(): void
    {
        foreach (['202_ltv_webhooks', '202_ltv_webhook_deliveries'] as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
        $customers = new MysqlCustomerRepository(self::$conn);
        $fields = new MysqlCustomerFieldRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, $fields);
        $fields->create(1, ['field_key' => 'plan', 'field_type' => 'text']);

        // Customer erasure: what goes and what stays, per table.
        $id = $crm->upsert(1, ['customer_ref' => 'preview-1', 'email' => 'p@example.com', 'custom_fields' => ['plan' => 'pro']]);
        $customers->addAlias(1, $id, 'esp_id', 'E-1', 1700000000);
        $other = $crm->upsert(1, ['customer_ref' => 'preview-2', 'custom_fields' => ['plan' => 'free']]);
        $customers->insertRevenueEvent(1, $id, ['event_type' => 'purchase', 'amount' => 10.0, 'currency' => 'USD', 'occurred_at' => 1700000000, 'source' => 'api'], 1700000000);
        self::$db->query("INSERT INTO 202_personalization_tokens SET token_hash=UNHEX(SHA2('preview',256)), user_id=1, customer_id=$id, created_at=1700000000, first_use_deadline=1700003600, replay_until=1702592000");

        $preview = [];
        foreach ($crm->erasurePreview(1, $id) as $row) {
            $preview[$row['resource']] = [$row['action'], $row['count']];
        }
        self::assertSame([
            'customer-aliases' => ['delete', 2],
            'personalization-tokens' => ['delete', 1],
            'customer-field-values' => ['delete', 1],
            'revenue-events' => ['kept', 1],
            'subscriptions' => ['kept', 0],
        ], $preview);
        $crm->erase(1, $id);
        $count = fn (string $table, int $customer): int
            => (int) $this->scalar("SELECT COUNT(*) FROM $table WHERE customer_id = $customer AND user_id = 1");
        self::assertSame(0, $count('202_customer_aliases', $id));
        self::assertSame(0, $count('202_personalization_tokens', $id));
        self::assertSame(0, $count('202_customer_field_values', $id));
        self::assertSame(1, $count('202_revenue_events', $id), 'the money the preview said stays, stays');
        self::assertSame(1, $count('202_customer_field_values', $other), 'another customer is untouched');

        // Alias lookup is scoped like deleteAlias(): another customer's
        // alias id is not found through this customer.
        $otherAlias = (int) $this->scalar("SELECT alias_id FROM 202_customer_aliases WHERE customer_id = $other");
        self::assertNull($customers->findAlias(1, $id, $otherAlias));
        self::assertSame('preview-2', $customers->findAlias(1, $other, $otherAlias)['alias_value'] ?? null);

        // Company: the preview's refusal is the delete's refusal.
        $companies = new MysqlCompanyRepository(self::$conn);
        $busy = $companies->create(1, 'Busy Co');
        $crm->upsert(1, ['customer_id' => $other, 'company' => 'Busy Co']);
        $refusal = $companies->deleteRefusal(1, $busy, 'Busy Co');
        self::assertNotNull($refusal);
        try {
            $companies->delete(1, $busy);
            self::fail('a company with a customer attached must not delete');
        } catch (\RuntimeException $e) {
            self::assertSame($refusal, $e->getMessage(), 'the preview reports the delete\'s own refusal');
        }
        $empty = $companies->create(1, 'Empty Co');
        self::assertNull($companies->deleteRefusal(1, $empty, 'Empty Co'));
        $companies->delete(1, $empty);
        self::assertNull($companies->get(1, $empty));

        // Field: the values it counts are the values the delete removes.
        $plan = $fields->findByKey(1, 'plan');
        self::assertNotNull($plan);
        $fieldId = (int) $plan['field_id'];
        self::assertSame('plan', $fields->get(1, $fieldId)['field_key'] ?? null);
        self::assertNull($fields->get(2, $fieldId), 'another account does not see it');
        self::assertSame(1, $fields->valueCount(1, $fieldId), 'the erased customer\'s value is already gone');
        $fields->delete(1, $fieldId);
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_field_values WHERE field_id = $fieldId"));

        // Webhook: never the secret, and the deliveries it counts go with it.
        $webhooks = new \Prosper202\Ltv\MysqlWebhookRepository(self::$conn);
        $hook = $webhooks->create(1, 'https://hooks.example.com/p', ['revenue.recorded'])['webhookId'];
        $webhooks->enqueue(1, 'revenue.recorded', ['event_id' => 1]);
        $webhooks->enqueue(1, 'revenue.recorded', ['event_id' => 2]);
        $row = $webhooks->get(1, $hook);
        self::assertIsArray($row);
        self::assertArrayNotHasKey('webhook_secret', $row);
        self::assertSame(['pending' => 2], $webhooks->deliveryCounts(1, $hook));
        $webhooks->delete(1, $hook);
        self::assertNull($webhooks->get(1, $hook));
        self::assertSame([], $webhooks->deliveryCounts(1, $hook));
    }

    /** An account whose identity keys were never minted erases without minting them. */
    public function testEraseMintsNoIdentityKeys(): void
    {
        self::$db->query('TRUNCATE TABLE 202_identity_keys');
        $customers = new MysqlCustomerRepository(self::$conn);
        $id = $customers->resolveOrCreateByAlias(1, 'custom', 'nobody', [], null, 1700000000);
        $fields = new MysqlCustomerFieldRepository(self::$conn);
        (new MysqlCustomerCrmRepository(self::$conn, $customers, $fields))->erase(1, $id);
        self::assertSame('0', (string) $this->scalar('SELECT COUNT(*) FROM 202_identity_keys'));
    }

    public function testCompanyDomainIsUniquePerAccountAndMergeInheritsSafely(): void
    {
        $companies = new MysqlCompanyRepository(self::$conn);

        // Merge-picker search: matches name or domain, excludes the target id.
        $acme = $companies->create(1, 'Acme Widgets', 'acme.example.com');
        $other = $companies->create(1, 'Widget World');
        $found = $companies->search(1, 'widget', $other, 8);
        self::assertCount(1, $found, 'the merge target itself is excluded');
        self::assertSame($acme, (int) $found[0]['company_id']);
        $byDomain = $companies->search(1, 'acme.example', 0, 8);
        self::assertSame($acme, (int) $byDomain[0]['company_id'], 'domain matches too');

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
        p202RecordConversion(self::$db, $this->log(5200), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'CF-A', ['customer_ref' => 'cf-a'], [
            ['sku' => 'SKU-CF', 'name' => 'Widget', 'quantity' => 1, 'unit_price' => 10.0, 'amount' => 10.0],
        ]);
        $logB = $this->log(5201);
        $logB['click_payout'] = '20.0';
        p202RecordConversion(self::$db, $logB, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '20.0', 'CF-B', ['customer_ref' => 'cf-b'], [
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

        // The same request again is a replay: the original event and owner.
        $replay = $controller->recordRevenue(
            ['customer_ref' => 'idem-a', 'amount' => 10.0, 'idempotency_key' => 'IDEM-1']
        );
        self::assertSame(200, $replay['_status']);
        self::assertTrue($replay['data']['duplicate']);
        self::assertSame($first['data']['event_id'], $replay['data']['event_id']);
        self::assertSame($first['data']['customer_id'], $replay['data']['customer_id'], 'the ORIGINAL owner is reported');

        // Same key, a DIFFERENT brand-new ref: a different request, refused
        // before anything resolves, so no customer is created for it.
        try {
            $controller->recordRevenue(['customer_ref' => 'idem-b', 'amount' => 10.0, 'idempotency_key' => 'IDEM-1']);
            self::fail('a key reused for another customer must be refused');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertArrayHasKey('idempotency_key', $e->getFieldErrors());
            self::assertStringContainsString('customer', $e->getMessage());
        }
        $customers = (int) $this->scalar('SELECT COUNT(*) FROM 202_customers');
        self::assertSame(1, $customers, 'a refused replay must not mint identity');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'));
    }

    public function testConcurrentIdempotencyRaceLoserRollsBackIdentity(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $winnerId = $customers->resolveOrCreateByAlias(1, 'custom', 'race-winner', [], null, 1700000000);

        // The loser carries a BRAND-NEW ref: precheck misses (winner is
        // uncommitted), identity gets resolved, the insert blocks, loses,
        // and the whole transaction must roll back. Its body is not the
        // winner's (another customer, another amount), so it is refused
        // rather than answered with the winner's event.
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $refused = null;
        $this->whileAnotherProcessHoldsAnEvent($winnerId, 'RACE-1', function () use ($controller, &$refused): void {
            try {
                $controller->recordRevenue(
                    ['customer_ref' => 'race-loser', 'amount' => 5.0, 'idempotency_key' => 'RACE-1']
                );
            } catch (\Api\V3\Exception\ValidationException $e) {
                $refused = $e;
            }
        });

        self::assertNotNull($refused, 'the loser of the race sent a different request: it is refused');
        self::assertArrayHasKey('idempotency_key', $refused->getFieldErrors());
        self::assertStringContainsString('amount', $refused->getMessage());
        $count = fn (string $sql): int => (int) $this->scalar($sql);
        self::assertSame(
            0,
            $count("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'race-loser'"),
            'the losing identity must roll back'
        );
        self::assertSame(0, $count("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'race-loser'"));
        self::assertSame(1, $count("SELECT COUNT(*) FROM 202_revenue_events WHERE idempotency_key = 'RACE-1'"));
    }

    /** The loser of the race sent the winner's request: a replay, answered with the winner's event. */
    public function testConcurrentIdempotencyRaceLoserWithTheSameRequestReplays(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $winnerId = $customers->resolveOrCreateByAlias(1, 'custom', 'race-winner', [], null, 1700000000);

        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $result = null;
        $this->whileAnotherProcessHoldsAnEvent($winnerId, 'RACE-2', function () use ($controller, &$result): void {
            $result = $controller->recordRevenue(
                ['customer_ref' => 'race-winner', 'amount' => 20.0, 'idempotency_key' => 'RACE-2']
            );
        });

        self::assertIsArray($result);
        self::assertSame(200, $result['_status']);
        self::assertTrue($result['data']['duplicate']);
        self::assertSame($winnerId, $result['data']['customer_id'], 'the loser must report the winner as the owner');
        $events = (int) $this->scalar("SELECT COUNT(*) FROM 202_revenue_events WHERE idempotency_key = 'RACE-2'");
        self::assertSame(1, $events);
    }

    /**
     * Run $loser while a second PROCESS plays the concurrent winner: it
     * inserts a 20.00 purchase under $key for $winnerId and holds its
     * transaction open, so the loser's precheck can't see the row while the
     * unique-key insert must wait behind its lock — the only ordering that
     * reaches the lost-race branch.
     */
    private function whileAnotherProcessHoldsAnEvent(int $winnerId, string $key, callable $loser): void
    {
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
    VALUES (1, WINNER_ID, 'purchase', 20, 'USD', 1700000000, 'api', 'RACE_KEY', 1700000000)");
touch($argv[1]); // signal the parent: the row lock is held
usleep(1500000); // hold it long enough for the loser to block on the key
$db->commit();
CHILD;
        file_put_contents($script, str_replace(['WINNER_ID', 'RACE_KEY'], [(string) $winnerId, $key], $child));

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

        try {
            $loser();
        } finally {
            proc_close($proc);
            @unlink($script);
            @unlink($marker);
        }
    }

    /**
     * An idempotency key makes a retry safe; it does not make a different
     * event under the same key the first one. Each request below differs
     * from the recorded one in one thing and is refused naming the key,
     * with nothing written; the ones that state the same event replay it.
     */
    public function testRevenueKeyReplaysOnlyTheSameRequest(): void
    {
        $controller = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $otherCustomer = (new MysqlCustomerRepository(self::$conn))
            ->resolveOrCreateByAlias(1, 'custom', 'rr-other', [], null, 1700000000);
        $original = [
            'customer_ref' => 'rr-a', 'amount' => 10.0, 'idempotency_key' => 'RR-1', 'external_ref' => 'order-1',
            'occurred_at' => 1700000000, 'currency' => 'USD',
            'items' => [['sku' => 'SKU-R', 'name' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0]],
            'customer_crm' => ['first_name' => 'Ada'],
        ];
        $first = $controller->recordRevenue($original);
        self::assertSame(201, $first['_status']);
        $eventId = $first['data']['event_id'];
        $customerId = $first['data']['customer_id'];

        $same = [
            'the same body' => $original,
            'the amount as a string with zeros' => ['amount' => '10.000'] + $original,
            'currency left out (the account\'s)' => array_diff_key($original, ['currency' => true]),
            'occurred_at left out (now)' => array_diff_key($original, ['occurred_at' => true]),
            'customer_crm left out (it describes a customer the write creates)' =>
                array_diff_key($original, ['customer_crm' => true]),
            'the customer by its id' =>
                ['customer_id' => $customerId] + array_diff_key($original, ['customer_ref' => true]),
            'quantity as a string' => [
                'items' => [['sku' => 'SKU-R', 'name' => 'Widget', 'quantity' => '2', 'unit_price' => 5.0]],
            ] + $original,
        ];
        foreach ($same as $case => $body) {
            $replay = $controller->recordRevenue($body);
            self::assertSame(200, $replay['_status'], $case);
            self::assertTrue($replay['data']['duplicate'], $case);
            self::assertSame($eventId, $replay['data']['event_id'], $case);
            self::assertSame($customerId, $replay['data']['customer_id'], $case);
        }

        $different = [
            'amount' => ['amount' => 99.5] + $original,
            'event_type' => ['event_type' => 'one_time'] + $original,
            'customer' => ['customer_ref' => 'rr-b'] + $original,
            'customer by id' => ['customer_id' => $otherCustomer] + array_diff_key($original, ['customer_ref' => true]),
            'items' =>
                ['items' => [['sku' => 'SKU-R', 'name' => 'Widget', 'quantity' => 3, 'unit_price' => 5.0]]] + $original,
            'items left out' => array_diff_key($original, ['items' => true]),
            'external_ref' => ['external_ref' => 'order-2'] + $original,
            'external_ref left out' => array_diff_key($original, ['external_ref' => true]),
            'transaction_id' => ['transaction_id' => 'ch_9'] + $original,
            'occurred_at' => ['occurred_at' => 1700000001] + $original,
        ];
        foreach ($different as $case => $body) {
            try {
                $controller->recordRevenue($body);
                self::fail($case . ': a different request under a used key must be refused');
            } catch (\Api\V3\Exception\ValidationException $e) {
                $errors = $e->getFieldErrors();
                self::assertArrayHasKey('idempotency_key', $errors, $case);
                self::assertStringContainsString('revenue event ' . $eventId, $errors['idempotency_key'], $case);
                self::assertStringContainsString(explode(' ', $case)[0], $e->getMessage(), $case);
            }
        }

        $count = fn (string $sql): int => (int) $this->scalar($sql);
        self::assertSame(1, $count('SELECT COUNT(*) FROM 202_revenue_events'), 'nothing was recorded');
        self::assertSame(1, $count('SELECT COUNT(*) FROM 202_revenue_line_items'));
        self::assertSame(
            10.0,
            (float) $this->scalar('SELECT amount FROM 202_revenue_events WHERE event_id = ' . (int) $eventId)
        );
        self::assertSame(
            0,
            $count("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'rr-b'"),
            'no customer was created'
        );
        self::assertSame(
            10.0,
            (float) $this->scalar('SELECT total_revenue FROM 202_customers WHERE customer_id = ' . (int) $customerId)
        );
    }

    /** A renewal's key (its idempotency_key, else its transaction id) replays only the same renewal. */
    public function testSubscriptionEventKeyReplaysOnlyTheSameEvent(): void
    {
        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $subs = new \Prosper202\Ltv\MysqlSubscriptionRepository(self::$conn, $customers);
        $customerId = $crm->upsert(1, ['customer_ref' => 'sub-rr']);
        $subs->upsert(
            1,
            ['external_sub_id' => 'SUB-RR', 'amount' => 25.0, 'status' => 'active', 'customer_id' => $customerId]
        );
        $events = fn (): int => (int) $this->scalar(
            "SELECT COUNT(*) FROM 202_revenue_events WHERE source = 'subscription'"
        );
        $renewal = ['idempotency_key' => 'inv-1', 'amount' => 25.0, 'occurred_at' => 1700000100];

        $first = $subs->recordEvent(1, 'SUB-RR', 'renewal', $renewal);
        self::assertTrue($first['inserted']);
        $replay = $subs->recordEvent(1, 'SUB-RR', 'renewal', $renewal);
        self::assertFalse($replay['inserted']);
        self::assertSame($first['eventId'], $replay['eventId']);
        $omitted = $subs->recordEvent(1, 'SUB-RR', 'renewal', ['idempotency_key' => 'inv-1']);
        self::assertFalse($omitted['inserted'], 'left out, the amount and time are not compared');

        $different = [
            'amount' => ['idempotency_key' => 'inv-1', 'amount' => 30.0],
            'occurred_at' => ['idempotency_key' => 'inv-1', 'occurred_at' => 1700000200],
            'transaction_id' => ['idempotency_key' => 'inv-1', 'transaction_id' => 'ch_1'],
        ];
        foreach ($different as $case => $payload) {
            try {
                $subs->recordEvent(1, 'SUB-RR', 'renewal', $payload);
                self::fail($case . ': a different renewal under a used key must be refused');
            } catch (\Prosper202\Ltv\LtvInputException $e) {
                self::assertSame('idempotency_key', $e->field, $case);
                self::assertStringContainsString($case, $e->getMessage(), $case);
            }
        }

        $byTransaction = $subs->recordEvent(1, 'SUB-RR', 'renewal', ['transaction_id' => 'ch_2', 'amount' => 25.0]);
        self::assertTrue($byTransaction['inserted']);
        try {
            $subs->recordEvent(1, 'SUB-RR', 'renewal', ['transaction_id' => 'ch_2', 'amount' => 30.0]);
            self::fail('a different renewal under a used transaction id must be refused');
        } catch (\Prosper202\Ltv\LtvInputException $e) {
            self::assertSame('transaction_id', $e->field);
        }

        self::assertSame(2, $events(), 'nothing was recorded for the refused renewals');
    }

    /**
     * A transaction id locates a recorded conversion; a request carrying it
     * is that sale again only when what it states is what was recorded.
     */
    public function testConversionDuplicateReplaysOnlyTheSameSale(): void
    {
        $this->insertClick(6100);
        self::$db->query('INSERT INTO 202_clicks_tracking SET click_id=6100, c1_id=0, c2_id=0, c3_id=0, c4_id=0');
        $controller = new \Api\V3\Controllers\ConversionsController(self::$db, 1);
        $original = [
            'click_id' => 6100, 'transaction_id' => 'TX-RR', 'payout' => 10, 'conv_time' => 1700000100,
            'customer_ref' => 'cv-a', 'items' => [['sku' => 'SKU-C', 'quantity' => 1, 'unit_price' => 10.0]],
        ];
        $first = $controller->create($original);
        self::assertArrayNotHasKey('duplicate', $first);
        $convId = (int) $this->scalar('SELECT conv_id FROM 202_conversion_logs WHERE click_id = 6100');

        $same = [
            'the same body' => $original,
            'the payout as text' => ['payout' => '10.00'] + $original,
            'payout left out' => array_diff_key($original, ['payout' => true]),
            'conv_time left out' => array_diff_key($original, ['conv_time' => true]),
        ];
        foreach ($same as $case => $body) {
            $again = $controller->create($body);
            self::assertTrue($again['duplicate'] ?? false, $case);
        }

        $different = [
            'payout' => ['payout' => 20] + $original,
            'customer' => ['customer_ref' => 'cv-b'] + $original,
            'items' => ['items' => [['sku' => 'SKU-C', 'quantity' => 2, 'unit_price' => 10.0]]] + $original,
            'conv_time' => ['conv_time' => 1700000200] + $original,
        ];
        foreach ($different as $case => $body) {
            try {
                $controller->create($body);
                self::fail($case . ': a different sale under a recorded transaction id must be refused');
            } catch (\Api\V3\Exception\ValidationException $e) {
                $errors = $e->getFieldErrors();
                self::assertArrayHasKey('transaction_id', $errors, $case);
                self::assertStringContainsString('conversion ' . $convId, $errors['transaction_id'], $case);
                self::assertStringContainsString($case, $e->getMessage(), $case);
            }
        }

        // The repository refuses an unknown type on a new conversion; a
        // duplicate never reaches it, and the comparison refuses it the same.
        try {
            $controller->create(['customer_ref_type' => 'nonsense'] + $original);
            self::fail('an unknown customer_ref_type on a duplicate must be refused, as on a new conversion');
        } catch (\Api\V3\Exception\ValidationException $e) {
            self::assertArrayHasKey('customer_ref_type', $e->getFieldErrors());
        }

        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_conversion_logs'), 'nothing was recorded');
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_line_items'));
        $aliases = (int) $this->scalar("SELECT COUNT(*) FROM 202_customer_aliases WHERE alias_value = 'cv-b'");
        self::assertSame(0, $aliases);
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

        // Email digests are case-insensitive hex: the same md5 uppercased by
        // an ESP and lowercased by a pixel must converge on ONE customer,
        // stored in canonical lowercase.
        $md5Upper = strtoupper(md5('person@example.com'));
        $c = $customers->resolveOrCreateByAlias(1, 'email_md5', $md5Upper, [], null, 1700000000);
        $d = $customers->resolveOrCreateByAlias(1, 'email_md5', strtolower($md5Upper), [], null, 1700000000);
        self::assertSame($c, $d, 'digest case variants must resolve to the same customer');
        self::assertSame(strtolower($md5Upper), (string) $this->scalar(
            "SELECT alias_value FROM 202_customer_aliases WHERE alias_type = 'email_md5'"
        ), 'digests are stored lowercased');

        // addAlias applies the same canonical form: an uppercase sha256 added
        // by hand matches a later lowercase resolution.
        $sha = strtoupper(hash('sha256', 'person@example.com'));
        $customers->addAlias(1, $c, 'email_sha256', $sha, 1700000000);
        self::assertSame($c, $customers->resolveOrCreateByAlias(1, 'email_sha256', strtolower($sha), [], null, 1700000000));

        // Malformed digests are rejected explicitly — not stored as an
        // identity that nothing can ever match again.
        try {
            $customers->resolveOrCreateByAlias(1, 'email_md5', 'not-a-digest', [], null, 1700000000);
            self::fail('malformed email_md5 must be rejected');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('hex digest', $e->getMessage());
        }
        self::assertSame(0, (int) $this->scalar("SELECT COUNT(*) FROM 202_customers WHERE primary_ref = 'not-a-digest'"));
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
        p202RecordConversion(self::$db, $log, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '20.0', 'CAC-1', [
            'customer_ref' => 'cac-1', 'customer_crm' => ['first_name' => 'Jane', 'last_name' => 'Doe'],
        ], []);
        p202RecordConversion(self::$db, $this->log(8001), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', 'CAC-2', ['customer_ref' => 'cac-2'], []);

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

    /**
     * A cohort is a calendar month of the account's zone, and the window
     * starts at that zone's first of the month. 03:00 UTC on 1 November is
     * 23:00 on 31 October in New York: the window used to start at date()'s
     * midnight and the grouping to use the database session's zone, so a New
     * York account counted this customer in November.
     */
    public function testCohortMonthsAreTheAccountsCalendarMonths(): void
    {
        $this->insertClick(8100);
        p202RecordConversion(self::$db, $this->log(8100), \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '12.0', 'TZ-1', ['customer_ref' => 'tz-1'], []);
        self::assertSame(1, (int) $this->scalar('SELECT COUNT(*) FROM 202_revenue_events'), 'the conversion recorded its revenue');
        self::$db->query('UPDATE 202_customers SET first_seen_time = ' . gmmktime(3, 0, 0, 11, 1, 2023));
        // 00:00 on 1 November in New York, an hour after it was first seen there.
        self::$db->query('UPDATE 202_revenue_events SET occurred_at = ' . gmmktime(4, 0, 0, 11, 1, 2023));
        $ltv = new \Prosper202\Ltv\MysqlLtvRepository(self::$conn);
        $now = gmmktime(12, 0, 0, 12, 10, 2023);

        $utc = $ltv->cohorts(1, 2, $now, 'UTC');
        self::assertSame(['2023-11'], array_column($utc, 'cohort_month'), 'in UTC the customer arrived in November');
        self::assertSame(12.0, (float) $utc[0]['m0']);

        self::assertSame([], $ltv->cohorts(1, 2, $now, 'America/New_York'), 'in New York October is outside November and December');
        $ny = $ltv->cohorts(1, 3, $now, 'America/New_York');
        self::assertSame(['2023-10'], array_column($ny, 'cohort_month'), 'and is its own cohort when the window reaches it');
        self::assertSame(12.0, $ny[0]['m0'], 'bought within the month it arrived, on its wall clock');
        self::assertSame([1, 12.0, 0.0, 12.0], [$ny[0]['customers'], $ny[0]['total_revenue'], $ny[0]['m5_plus'], $ny[0]['ltv_per_customer']], 'numbers, not DECIMAL strings');
    }

    /**
     * The fields of an LTV answer that hold text, the caller's own or a
     * name, which may be all digits.
     */
    private const LTV_TEXT_FIELDS = [
        'primary_ref', 'first_name', 'last_name', 'email', 'phone', 'company', 'address_line1', 'address_line2',
        'city', 'region', 'postal_code', 'country', 'status', 'alias_type', 'alias_value', 'external_sub_id',
        'plan_name', 'currency', 'billing_interval', 'event_type', 'source', 'external_ref', 'transaction_id',
        'sku', 'product_name', 'event_name', 'campaign_name', 'landing_page', 'name', 'url', 'basis',
        'dry_run', 'action', 'resource', 'mode', 'effect',
        'top_campaign_name', 'top_event_name', 'external_product_id', 'domain', 'normalized_name', 'webhook_url',
        'subscribed_events', 'webhook_status', 'last_response_body', 'field_key', 'label', 'field_type', 'provider',
        'cohort_month', 'timezone', 'breakdown', 'refused', 'fallback_reason',
    ];

    /**
     * Values typed by something other than the column: a custom field's by
     * its field, a select field's choices (text), an integration's config
     * (stored as sent).
     */
    private const LTV_FREE_FORM_FIELDS = ['custom_fields', 'options', 'config'];

    /**
     * Every customer read answers money as numbers and counts as integers,
     * as GET /ltv/summary has since 1.9.77. GET /ltv/customers and
     * /ltv/customers/{id} (its subscriptions, revenue events and line items)
     * answered mysqli's DECIMAL strings ("54.12500", a quantity of "1.500"),
     * and the engagement SUM()'s "1" for conversions, so one field read as
     * a number from one endpoint and as text from another. Through the
     * controller, on a real server: the types are the server's, which a fake
     * connection would only repeat. Each answer is walked whole, so a
     * DECIMAL column added later is seen as well as the ones named here.
     */
    public function testCustomerReadsServeNumbers(): void
    {
        [$ltv, $id] = $this->seedCustomerReads();
        $answers = [
            'summary' => $ltv->summary([]),
            'customers' => $ltv->customers([]),
            'customer' => $ltv->customerDetail($id),
            'engagement' => $ltv->customerEngagement($id, []),
            'next-offer' => $ltv->customerNextOffer($id),
            'erase preview' => $ltv->deleteCustomerPreview($id),
        ];
        foreach ($answers as $read => $answer) {
            self::assertNoNumericText($answer, $read);
        }

        $rollups = [54.125, 5.0, 29.99, 2, 1];
        $of = static fn (array $c, string $orders, string $subs): array
            => [$c['total_revenue'], $c['refunded_amount'], $c['mrr'], $c[$orders], $c[$subs]];
        $summary = $answers['summary']['data'];
        self::assertSame($rollups, $of($summary, 'total_orders', 'active_subscriptions'), 'the summary');
        $customers = [
            'list row' => $answers['customers']['data'][0],
            'detail' => $answers['customer']['data'],
            'erase preview' => $answers['erase preview']['data']['record'],
        ];
        foreach ($customers as $read => $customer) {
            self::assertSame($rollups, $of($customer, 'order_count', 'active_subscription_count'), $read);
            self::assertSame('0042', $customer['primary_ref'], "$read: text stays text");
        }

        $detail = $answers['customer']['data'];
        self::assertSame(['5551234', '02134'], [$detail['phone'], $detail['postal_code']]);
        self::assertSame(42.5, $detail['custom_fields']['score']);
        self::assertSame('98765', $detail['aliases'][1]['alias_value']);
        $sub = $detail['subscriptions'][0];
        self::assertSame(
            ['7002', 29.99, 29.99, 1],
            [$sub['external_sub_id'], $sub['amount'], $sub['mrr'], $sub['billing_interval_count']]
        );
        self::assertSame([-5.0, 10.125, 49.0], array_column($detail['recent_events'], 'amount'));
        self::assertSame('1001', $detail['recent_events'][2]['transaction_id']);
        $items = array_merge(...array_column($detail['recent_events'], 'items'));
        self::assertSame(
            [['ADDON', 1.5, null, 10.125], ['7001', 2.0, 24.5, 49.0]],
            array_map(
                static fn (array $i): array => [$i['sku'], $i['quantity'], $i['unit_price'], $i['amount']],
                $items
            ),
            'a quantity is a measure (1.5), and a unit price never sent stays null'
        );

        $engagement = $answers['engagement']['data'];
        self::assertSame([[7, 1, 1]], array_map(
            static fn (array $b): array => [$b['campaign_id'], $b['clicks'], $b['conversions']],
            $engagement['browsing']
        ));
        self::assertSame([['signed_up', null], ['scroll_depth', 75.0]], array_map(
            static fn (array $e): array => [$e['event_name'], $e['event_value']],
            $engagement['events']
        ), 'an event with no value stays null');

        $offer = $answers['next-offer']['data'];
        self::assertSame(
            [7, 'engagement', 1],
            [$offer['campaign_id'], $offer['why']['basis'], $offer['why']['clicks']]
        );
    }

    /**
     * docs/openapi.yaml's customer schemas name every key the answers carry,
     * each with the JSON type it is served as, and no key they do not carry.
     * A customer with no custom field value answered custom_fields as [], a
     * list, where one with a value answered an object.
     */
    public function testCustomerAnswersHaveTheSpecsKeysAndTypes(): void
    {
        [$ltv, $id] = $this->seedCustomerReads();
        $plain = (int) $ltv->upsertCustomer(['customer_ref' => '0043'])['data']['customer_id'];
        $schemas = self::specSchemas();

        foreach ($ltv->customers([])['data'] as $row) {
            self::assertMatchesSchema($schemas, 'LtvCustomer', $row, 'GET /ltv/customers');
        }
        $plainDetail = $ltv->customerDetail($plain)['data'];
        self::assertMatchesSchema($schemas, 'LtvCustomerDetail', $plainDetail, 'a customer with no custom field value');
        $detail = $ltv->customerDetail($id)['data'];
        self::assertMatchesSchema($schemas, 'LtvCustomerDetail', $detail, 'GET /ltv/customers/{id}');
        $rows = [
            'LtvCustomerAlias' => $detail['aliases'],
            'LtvCustomerSubscription' => $detail['subscriptions'],
            'LtvCustomerRevenueEvent' => $detail['recent_events'],
            'LtvCustomerLineItem' => array_merge(...array_column($detail['recent_events'], 'items')),
        ];
        $engagement = $ltv->customerEngagement($id, [])['data'];
        self::assertMatchesSchema($schemas, 'LtvCustomerEngagement', $engagement, 'GET /ltv/customers/{id}/engagement');
        $rows['LtvBrowsedCampaign'] = $engagement['browsing'];
        $rows['LtvEngagementEvent'] = $engagement['events'];
        foreach ($rows as $schema => $list) {
            self::assertNotSame([], $list, "$schema has a row to compare");
            foreach ($list as $row) {
                self::assertMatchesSchema($schemas, $schema, $row, $schema);
            }
        }
    }

    /**
     * One customer with every part the customer reads answer: a number
     * custom field, an alias, two sales with line items (one line with no
     * unit price, a fractional quantity), a refund, a subscription, two
     * engagement events (one with no value) and a converted click on
     * campaign 7. Its references, phone, postal code and one sku are all
     * digits, as a caller's may be.
     *
     * @return array{0: \Api\V3\Controllers\LtvController, 1: int}
     */
    private function seedCustomerReads(): array
    {
        self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id=7, user_id=1,
            aff_campaign_name='Offer A', aff_campaign_url='https://example.com/a'");
        $ltv = new \Api\V3\Controllers\LtvController(self::$db, 1);
        $ltv->createField(['field_key' => 'score', 'field_type' => 'number']);
        $id = (int) $ltv->upsertCustomer([
            'customer_ref' => '0042', 'phone' => '5551234', 'postal_code' => '02134',
            'aliases' => [['type' => 'esp_id', 'value' => '98765']], 'custom_fields' => ['score' => 42.5],
        ])['data']['customer_id'];
        $ltv->recordRevenue([
            'customer_id' => $id, 'amount' => 49, 'idempotency_key' => 'num-1', 'transaction_id' => '1001',
            'items' => [['sku' => '7001', 'quantity' => 2, 'unit_price' => 24.5]],
        ]);
        $ltv->recordRevenue([
            'customer_id' => $id, 'amount' => 10.125, 'idempotency_key' => 'num-2',
            'items' => [['sku' => 'ADDON', 'quantity' => 1.5, 'amount' => 10.125]],
        ]);
        $ltv->recordRevenue([
            'customer_id' => $id, 'amount' => -5, 'event_type' => 'refund', 'idempotency_key' => 'num-3',
        ]);
        $ltv->upsertSubscription([
            'customer_id' => $id, 'external_sub_id' => '7002', 'plan_name' => 'Pro', 'amount' => 29.99,
        ]);
        $ltv->recordEngagementEvent(['customer_id' => $id, 'event' => 'scroll_depth', 'value' => 75]);
        $ltv->recordEngagementEvent(['customer_id' => $id, 'event' => 'signed_up']);
        self::$db->query('INSERT INTO 202_clicks SET click_id=9700, user_id=1, aff_campaign_id=7, click_payout=12.5,
            click_cpc=0.25, click_lead=1, click_time=UNIX_TIMESTAMP() - 600');
        self::$db->query("INSERT INTO 202_clicks_tracking SET click_id=9700, c1_id=0, c2_id=0, c3_id=0, c4_id=0,
            customer_id=$id");

        return [$ltv, $id];
    }

    /**
     * Every /ltv read answers money and measures as JSON numbers and counts
     * and times as integers, as the customer reads above do. The rest of
     * /ltv answered mysqli's text: GET /ltv/subscriptions a subscription's
     * amount ("29.99000") where the customer detail answered 29.99, the
     * breakdowns every amount and their order counts ("4"), the products a
     * price, the companies and ABM reads their money, averages and times.
     * The reads are the router's own list, so a GET route added to the
     * group fails here until it is held to the same.
     */
    public function testEveryLtvReadServesNumbers(): void
    {
        $seed = $this->seedEveryRead();
        $answers = [];
        foreach (self::everyLtvRead($seed) as $label => [$route, $read]) {
            $answers[$label] = $read();
            self::assertNoNumericText($answers[$label], $label);
        }

        $first = static fn (string $label): array => $answers[$label]['data'][0];
        $sub = $first('GET /ltv/subscriptions');
        self::assertSame([29.99, 29.99, 54.125, 1], [$sub['amount'], $sub['mrr'], $sub['customer_ltv'], $sub['billing_interval_count']]);
        self::assertSame('7002', $sub['external_sub_id'], 'text stays text');

        // The click-acquired customer: $20 on one order, $0.25 a click.
        $acquisition = static fn (array $r): array => [
            $r['customers'], $r['total_revenue'], $r['avg_ltv'], $r['total_orders'], $r['aov'], $r['repeat_rate'],
            $r['mrr'], $r['spend'], $r['cac'], $r['ltv_cac'],
        ];
        self::assertSame([1, 20.0, 20.0, 1, 20.0, 0.0, 0.0, 0.5, 0.5, 40.0], $acquisition($first('GET /ltv/breakdown?by=campaign')));
        $byAccount = $first('GET /ltv/breakdown?by=ppc_account');
        self::assertSame([1, 20.0, 20.0, 1, 20.0, 0.0, 0.0, 0.25, 0.25, 80.0], $acquisition($byAccount));
        self::assertSame('4455', $byAccount['name'], 'a name of digits stays text');
        self::assertSame([['7001', 1, 1, 2.0, 49.0, 49.0, 49.0, 0.0], ['ADDON', 1, 1, 1.5, 10.125, 10.125, 10.125, 0.0]], array_map(
            static fn (array $r): array => [
                $r['sku'], $r['customers'], $r['orders'], $r['units'], $r['total_revenue'], $r['avg_revenue_per_customer'],
                $r['aov'], $r['repeat_rate'],
            ],
            $answers['GET /ltv/breakdown?by=product']['data']
        ), 'units is a measure (1.5)');

        self::assertSame([12.5, null, null], array_column($answers['GET /ltv/products']['data'], 'price'), 'a product with no price stays null');
        self::assertSame(12.5, $answers['PATCH /ltv/products/{id}']['data']['price']);
        self::assertSame(12.5, $answers['DELETE /ltv/products/{id}?dry_run=1']['data']['record']['price']);

        $rollup = static fn (array $r): array => [$r['contacts'], $r['order_count'], $r['total_revenue'], $r['mrr']];
        [$acme, $beta] = $answers['GET /ltv/companies']['data'];
        self::assertSame([1, 1, 20.0, 0.0], $rollup($acme));
        self::assertGreaterThan(0, $acme['last_activity_time']);
        self::assertSame([0, 0, 0.0, 0.0, 0], [...$rollup($beta), $beta['last_activity_time']], 'a company with no customer');

        $account = $first('GET /ltv/abm');
        self::assertSame(['Acme', 1, 20.0, 0.0, 1, 1, 30.0, 0.0], [
            $account['company'], $account['contacts'], $account['total_revenue'], $account['mrr'],
            $account['engagements'], $account['custom_events'], $account['avg_time_on_page'], $account['avg_scroll_depth'],
        ]);
        $contact = $first('GET /ltv/abm/company');
        self::assertSame([1, 20.0, 0.0, 1, 30.0], [
            $contact['order_count'], $contact['total_revenue'], $contact['mrr'], $contact['engagements'], $contact['avg_time_on_page'],
        ]);
        self::assertIsInt($contact['last_activity'], 'a time is an integer (GREATEST() answered text)');
        self::assertGreaterThan(0, $contact['last_activity']);

        $fields = array_column($answers['GET /ltv/fields']['data'], 'options', 'field_key');
        self::assertSame(['10', '20'], $fields['tier'], "a select field's choices stay text");
        self::assertSame(['plan' => '7'], $answers['GET /ltv/integrations']['data'][0]['config'], 'a config is as sent');
    }

    /**
     * docs/openapi.yaml types every /ltv read: each one's 200 answer refers
     * to a components schema, and the answer has that schema's keys, each of
     * the JSON type it gives, into every schema it refers to. Until 1.9.79
     * all but the customer reads were `additionalProperties: true`, and
     * GET /ltv/abm's list was documented as an object.
     */
    public function testEveryLtvAnswerHasTheSpecsKeysAndTypes(): void
    {
        $seed = $this->seedEveryRead();
        $schemas = self::specSchemas();
        foreach (self::everyLtvRead($seed) as $label => [$route, $read, $schema, $shape]) {
            [$method, $path] = explode(' ', (string) preg_replace('/\?.*/', '', $route), 2);
            if ($shape !== 'record') {
                // A dry run's answer is documented by its description alone, as every DELETE's is.
                self::assertStringContainsString(
                    "#/components/schemas/$schema",
                    self::specOperation($path, strtolower($method)),
                    "$label: docs/openapi.yaml's 200 answer for $method $path refers to $schema"
                );
            }
            $data = $read()['data'];
            $rows = match ($shape) {
                'list' => $data,
                'record' => [$data['record']],
                default => [$data],
            };
            self::assertNotSame([], $rows, "$label has a row to compare");
            foreach ($rows as $i => $row) {
                self::assertIsArray($row, "$label: a $schema");
                self::assertMatchesSchema($schemas, $schema, $row, "$label.$i");
            }
        }
    }

    /**
     * Every /ltv read and the writes that answer a record the reads list,
     * by label: [route, the answer, the schema of its data, and whether the
     * data is that schema, a list of it, or a dry run's record]. The GET
     * routes are held to the router's /ltv read group.
     *
     * @param array{ltv: \Api\V3\Controllers\LtvController, customer: int, product: int, company: int, webhook: int} $seed
     * @return array<string, array{0: string, 1: callable(): array<string, mixed>, 2: string, 3: string}>
     */
    private static function everyLtvRead(array $seed): array
    {
        ['ltv' => $ltv, 'customer' => $id, 'product' => $product, 'company' => $company, 'webhook' => $webhook] = $seed;
        $reads = [
            'GET /ltv/summary' => [fn () => $ltv->summary([]), 'LtvSummary', 'object'],
            'GET /ltv/customers' => [fn () => $ltv->customers([]), 'LtvCustomer', 'list'],
            'GET /ltv/customers/{id}' => [fn () => $ltv->customerDetail($id), 'LtvCustomerDetail', 'object'],
            'GET /ltv/customers/{id}/engagement' => [fn () => $ltv->customerEngagement($id, []), 'LtvCustomerEngagement', 'object'],
            'GET /ltv/customers/{id}/next-offer' => [fn () => $ltv->customerNextOffer($id), 'LtvNextOffer', 'object'],
            'GET /ltv/abm' => [fn () => $ltv->abm([]), 'LtvAbmAccount', 'list'],
            'GET /ltv/abm/company' => [fn () => $ltv->abmCompany(['name' => 'Acme']), 'LtvAbmContact', 'list'],
            'GET /ltv/breakdown?by=campaign' => [fn () => $ltv->breakdown([]), 'LtvAcquisitionBreakdownRow', 'list'],
            'GET /ltv/breakdown?by=ppc_account' => [fn () => $ltv->breakdown(['by' => 'ppc_account']), 'LtvAcquisitionBreakdownRow', 'list'],
            'GET /ltv/breakdown?by=landing_page' => [fn () => $ltv->breakdown(['by' => 'landing_page']), 'LtvAcquisitionBreakdownRow', 'list'],
            'GET /ltv/breakdown?by=product' => [fn () => $ltv->breakdown(['by' => 'product']), 'LtvProductBreakdownRow', 'list'],
            'GET /ltv/cohorts' => [fn () => $ltv->cohorts([]), 'LtvCohort', 'list'],
            'GET /ltv/mrr' => [fn () => $ltv->mrr(), 'LtvMrr', 'object'],
            'GET /ltv/predict' => [fn () => $ltv->predict([]), 'LtvPrediction', 'object'],
            'GET /ltv/predict?by=campaign' => [fn () => $ltv->predict(['by' => 'campaign']), 'LtvPredictionByGroup', 'object'],
            'GET /ltv/predict?by=product' => [fn () => $ltv->predict(['by' => 'product']), 'LtvPredictionByGroup', 'object'],
            'GET /ltv/products' => [fn () => $ltv->products([]), 'LtvProduct', 'list'],
            'GET /ltv/companies' => [fn () => $ltv->listCompanies([]), 'LtvCompanyRollup', 'list'],
            'GET /ltv/subscriptions' => [fn () => $ltv->listSubscriptions([]), 'LtvSubscription', 'list'],
            'GET /ltv/fields' => [fn () => $ltv->fieldsList(), 'LtvField', 'list'],
            'GET /ltv/webhooks' => [fn () => $ltv->listWebhooks(), 'LtvWebhook', 'list'],
            'GET /ltv/webhooks/{id}/deliveries' => [fn () => $ltv->webhookDeliveries($webhook, []), 'LtvWebhookDelivery', 'list'],
            'GET /ltv/integrations' => [fn () => $ltv->listIntegrations(), 'LtvIntegration', 'list'],
            'PATCH /ltv/products/{id}' => [fn () => $ltv->updateProduct($product, ['name' => 'Priced']), 'LtvProduct', 'object'],
            'DELETE /ltv/products/{id}?dry_run=1' => [fn () => $ltv->deleteProductPreview($product), 'LtvProduct', 'record'],
            'PATCH /ltv/companies/{id}' => [fn () => $ltv->patchCompany($company, ['name' => 'Acme']), 'LtvCompany', 'object'],
            'DELETE /ltv/companies/{id}?dry_run=1' => [fn () => $ltv->deleteCompanyPreview($company), 'LtvCompany', 'record'],
        ];

        $covered = [];
        foreach (array_keys($reads) as $label) {
            if (str_starts_with($label, 'GET ')) {
                $covered[] = preg_replace('/^GET |\?.*$/', '', $label);
            }
        }
        $covered = array_values(array_unique($covered));
        sort($covered);
        self::assertSame(self::ltvReadRoutes(), $covered, "every GET in api/v3/index.php's /ltv group is read here");

        $everyRead = [];
        foreach ($reads as $label => [$read, $schema, $shape]) {
            $everyRead[$label] = [$label, $read, $schema, $shape];
        }

        return $everyRead;
    }

    /**
     * The GET routes api/v3/index.php registers in its /ltv groups, with the
     * prefix: /ltv/customers/{id} as written there.
     *
     * @return list<string>
     */
    private static function ltvReadRoutes(): array
    {
        $index = (string) file_get_contents(dirname(__DIR__, 2) . '/api/v3/index.php');
        $routes = [];
        foreach (explode('$router->group(', $index) as $group) {
            if (!str_starts_with($group, "'/ltv',")) {
                continue;
            }
            preg_match_all('/\$r->get\(\s*\'([^\']+)\'/', $group, $gets);
            foreach ($gets[1] as $path) {
                $routes[] = '/ltv' . $path;
            }
        }
        sort($routes);
        self::assertContains('/ltv/summary', $routes, 'the /ltv read group is found in api/v3/index.php');

        return $routes;
    }

    /** One operation of a path in docs/openapi.yaml, as text: from `  $path:` its `    $method:` up to the next. */
    private static function specOperation(string $path, string $method): string
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $start = strpos($spec, "\n  $path:\n");
        self::assertNotFalse($start, "$path is in docs/openapi.yaml");
        $end = strpos($spec, "\n  /", $start + 1);
        $block = substr($spec, $start, $end === false ? null : $end - $start);
        $op = strpos($block, "\n    $method:\n");
        self::assertNotFalse($op, "$path has a $method operation");
        $next = preg_match('/\n    (get|post|put|patch|delete):\n/', $block, $m, PREG_OFFSET_CAPTURE, $op + 1) === 1
            ? $m[0][1]
            : strlen($block);

        return substr($block, $op, $next - $op);
    }

    /**
     * seedCustomerReads(), and what the rest of /ltv reads: a customer
     * acquired by a click on campaign 7 through traffic source account 3
     * (named "4455") and landing page 4, at a company with an engagement
     * event; a second company with no customer; a priced product; a select
     * field whose choices are digits; a webhook with a delivery; an
     * integration.
     *
     * @return array{ltv: \Api\V3\Controllers\LtvController, customer: int, product: int, company: int, webhook: int}
     */
    private function seedEveryRead(): array
    {
        [$ltv, $id] = $this->seedCustomerReads();
        $db = self::$db;
        foreach (['202_ppc_accounts', '202_landing_pages', '202_ltv_webhooks', '202_ltv_webhook_deliveries', '202_ltv_integrations'] as $table) {
            $db->query('TRUNCATE TABLE ' . $table);
        }
        $db->query("INSERT INTO 202_ppc_accounts SET ppc_account_id=3, user_id=1, ppc_account_name='4455'");
        $db->query("INSERT INTO 202_landing_pages SET landing_page_id=4, user_id=1, landing_page_url='https://lp.example.com/',
            landing_page_nickname='LP'");
        foreach (['202_clicks', '202_clicks_spy'] as $table) {
            $db->query("INSERT INTO $table SET click_id=9701, user_id=1, aff_campaign_id=7, ppc_account_id=3, landing_page_id=4,
                click_payout=20, click_cpc=0.25, click_lead=0, click_time=1700000000");
        }
        $log = $this->log(9701);
        $log['click_payout'] = '20.0';
        p202RecordConversion($db, $log, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '20.0', 'EVERY-1', [
            'customer_ref' => '0044', 'customer_crm' => ['company' => 'Acme'],
        ], []);
        $acquired = (int) $this->scalar("SELECT customer_id FROM 202_customers WHERE primary_ref = '0044'");
        $ltv->recordEngagementEvent(['customer_id' => $acquired, 'event' => 'time_on_page', 'value' => 30]);
        $ltv->createCompany(['name' => 'Beta']);
        $company = (int) $this->scalar("SELECT company_id FROM 202_companies WHERE name = 'Acme'");
        $product = (int) $ltv->upsertProduct(['external_product_id' => '7003', 'name' => 'Priced', 'price' => 12.5])['data']['product_id'];
        $ltv->createField(['field_key' => 'tier', 'field_type' => 'select', 'options' => ['10', '20']]);
        $db->query("INSERT INTO 202_ltv_webhooks SET webhook_id=5, user_id=1, webhook_url='https://hooks.example.com/ltv',
            webhook_secret='s', subscribed_events='', status='active', created_at=1, updated_at=1");
        $db->query("INSERT INTO 202_ltv_webhook_deliveries SET webhook_id=5, user_id=1, event_name='revenue.recorded',
            payload='{}', status='failed', attempts=6, next_attempt_at=0, last_status_code=500, last_response_body='502',
            created_at=1, updated_at=1");
        $ltv->createIntegration(['provider' => 'stripe', 'name' => '123', 'config' => ['plan' => '7']]);

        return ['ltv' => $ltv, 'customer' => $id, 'product' => $product, 'company' => $company, 'webhook' => 5];
    }

    /**
     * Each components schema of docs/openapi.yaml whose properties are
     * written one to a line in flow style (`name: {type: number, ...}`), by
     * name: each property's type, whether it is nullable and the schema it
     * refers to (`{$ref: ...}`, or an array's `items: {$ref: ...}`), with an
     * allOf's referenced schema folded in, and its `required: [...]` list
     * when it gives one. Read as text, as LedgerReadsOpenApiTest reads the
     * spec: no YAML library ships with the project.
     *
     * @return array<string, array{properties: array<string, array{0: string, 1: bool, 2: ?string}>, required: ?list<string>}>
     */
    private static function specSchemas(): array
    {
        $spec = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/openapi.yaml');
        $components = substr($spec, (int) strpos($spec, "\ncomponents:\n"));
        $blocks = preg_split('/\n(?=    [A-Za-z]\w*:\n)/', $components) ?: [];
        $schemas = [];
        $allOf = [];
        $refPattern = '\$ref: \'#\/components\/schemas\/(\w+)\'';
        foreach ($blocks as $block) {
            if (preg_match('/^    (\w+):\n/', $block, $name) !== 1) {
                continue;
            }
            $schemas[$name[1]] = ['properties' => [], 'required' => null];
            preg_match_all('/^ {8,10}(\w+): \{type: (\w+)(.*)\}$/m', $block, $props, PREG_SET_ORDER);
            foreach ($props as [, $property, $type, $rest]) {
                $items = preg_match('/items: \{' . $refPattern . '\}/', $rest, $item) === 1 ? $item[1] : null;
                $schemas[$name[1]]['properties'][$property] = [$type, str_contains($rest, 'nullable: true'), $items];
            }
            preg_match_all('/^ {8,10}(\w+): \{' . $refPattern . '\}$/m', $block, $refs, PREG_SET_ORDER);
            foreach ($refs as [, $property, $ref]) {
                $schemas[$name[1]]['properties'][$property] = ['object', false, $ref];
            }
            if (preg_match('/^      required: \[(.*)\]$/m', $block, $required) === 1) {
                $schemas[$name[1]]['required'] = array_map('trim', explode(',', $required[1]));
            }
            if (preg_match('/^      allOf:\n      - ' . $refPattern . '$/m', $block, $ref) === 1) {
                $allOf[$name[1]] = $ref[1];
            }
        }
        foreach ($allOf as $schema => $base) {
            $schemas[$schema]['properties'] = ($schemas[$base]['properties'] ?? []) + $schemas[$schema]['properties'];
        }

        return $schemas;
    }

    /**
     * $row has the keys $schema documents, each of the JSON type it gives,
     * and so on into each schema a property refers to. A schema with a
     * `required` list may leave out the keys it does not name.
     *
     * @param array<string, array{properties: array<string, array{0: string, 1: bool, 2: ?string}>, required: ?list<string>}> $schemas
     * @param array<string, mixed> $row
     */
    private static function assertMatchesSchema(array $schemas, string $schema, array $row, string $where): void
    {
        self::assertArrayHasKey($schema, $schemas, "$schema is in docs/openapi.yaml with its properties");
        self::assertNotSame([], $schemas[$schema]['properties'], "$schema has its properties in flow style");
        $properties = $schemas[$schema]['properties'];
        $documented = array_keys($properties);
        $served = array_keys($row);
        sort($documented);
        sort($served);
        $required = $schemas[$schema]['required'];
        if ($required === null) {
            self::assertSame($documented, $served, "$where: the keys $schema documents are the keys served");
        } else {
            self::assertSame([], array_values(array_diff($served, $documented)), "$where: keys $schema does not document");
            self::assertSame([], array_values(array_diff($required, $served)), "$where: keys $schema requires");
        }
        foreach ($properties as $property => [$type, $nullable, $ref]) {
            if (!array_key_exists($property, $row)) {
                continue;
            }
            $value = $row[$property];
            if ($value === null) {
                self::assertTrue($nullable, "$where: $property is null, which $schema does not say it may be");
                continue;
            }
            // A list or an object as JSON writes it: PHP's [] is a list.
            $json = (string) json_encode($value);
            $is = match ($type) {
                'integer' => is_int($value),
                'number' => is_float($value) || is_int($value),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'array' => str_starts_with($json, '['),
                'object' => str_starts_with($json, '{'),
                default => false,
            };
            self::assertTrue($is, "$where: $property is " . get_debug_type($value) . " $json, $schema says $type");
            if ($ref !== null && is_array($value)) {
                foreach ($type === 'array' ? $value : [$value] as $i => $item) {
                    self::assertIsArray($item, "$where.$property: $ref is an object");
                    self::assertMatchesSchema($schemas, $ref, $item, $type === 'array' ? "$where.$property.$i" : "$where.$property");
                }
            }
        }
    }

    /** A numeric string anywhere in $value but a text field (or a free-form one, LTV_FREE_FORM_FIELDS). */
    private static function assertNoNumericText(mixed $value, string $path): void
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (!in_array($key, self::LTV_FREE_FORM_FIELDS, true)) {
                    self::assertNoNumericText($item, $path . '.' . $key);
                }
            }

            return;
        }
        $field = substr($path, (int) strrpos($path, '.') + 1);
        if (is_string($value) && is_numeric($value) && !in_array($field, self::LTV_TEXT_FIELDS, true)) {
            self::fail("$path is the string \"$value\": money is a JSON number, and a count or a time an integer");
        }
    }

    public function testNextOfferRebuildAndScoringEndToEnd(): void
    {
        foreach ([[7, 'Offer A'], [8, 'Offer B'], [9, 'Offer C'], [10, 'Offer D']] as [$id, $name]) {
            self::$db->query("INSERT INTO 202_aff_campaigns SET aff_campaign_id={$id}, user_id=1,
                aff_campaign_name='{$name}', aff_campaign_url='https://example.com/offer-{$id}'");
        }

        // Purchase journeys (each conversion takes its click's campaign):
        //  c1: A then B            (A->B adjacent)
        //  c2: A then C then B     (A->C and C->B adjacent, A->B eventual)
        //  c3: A then D, D voided  (deleted sales must leave no transitions)
        $buy = function (int $clickId, string $ref, int $campaignId, int $convTime, string $tx): int {
            $this->insertClick($clickId, 10.0, 0.0, $campaignId);
            $log = $this->log($clickId);
            $log['conv_time'] = $convTime;
            $result = p202RecordConversion(self::$db, $log, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '10.0', $tx, ['customer_ref' => $ref], []);

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

            // Operator revocation wins over replay: clearing the allowlist
            // (personalization off) goes dark immediately; restoring it
            // brings the sealed snapshot back.
            self::$db->query("UPDATE 202_users_pref SET user_ltv_personalization_fields='' WHERE user_id=1");
            self::assertSame([], $p13n->redeem($token, $now + 120), 'turning personalization off revokes sealed snapshots');
            self::$db->query("UPDATE 202_users_pref SET user_ltv_personalization_fields='rec:next_offer' WHERE user_id=1");
            $restored = $p13n->redeem($token, $now + 180);
            self::assertSame('Offer A', $restored['next_offer_name'] ?? null, 're-enabling restores the sealed replay');

            // The winner's sealed token is usable; garbage is not.
            self::assertTrue($p13n->tokenIsUsable($token, $now + 200));
            self::assertFalse($p13n->tokenIsUsable(str_repeat('x', 43), $now + 200));
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
        // conversion onto the decision log (the reward signal), and
        // the campaign leaves fatigue consideration for good.
        $log = $this->log(9201);
        $log['click_time'] = $freshTime;
        $log['conv_time'] = $freshTime + 100; // after first_shown_at — the reward window
        p202RecordConversion(self::$db, $log, \Prosper202\Conversion\Ledger\MatchMethod::CLICK_ID, '', true, '25.0', 'FAT-1', ['customer_ref' => 'fat-1'], []);
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
