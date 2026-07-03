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
            '202_companies', '202_personalization_tokens',
        ] as $table) {
            self::$db->query('TRUNCATE TABLE ' . $table);
        }
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

        $customers = new MysqlCustomerRepository(self::$conn);
        $crm = new MysqlCustomerCrmRepository(self::$conn, $customers, new MysqlCustomerFieldRepository(self::$conn));
        $crm->merge(1, $srcId, $dstId);

        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_engagement_events WHERE customer_id=$dstId"), 'engagement history follows the merge');
        self::assertSame(1, (int) $this->scalar("SELECT COUNT(*) FROM 202_personalization_tokens WHERE customer_id=$dstId"), 'personalization tokens follow the merge');

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
}
