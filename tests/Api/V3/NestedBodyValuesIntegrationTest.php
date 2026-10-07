<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\LtvController;
use Api\V3\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Database\SchemaInstaller;
use Prosper202\Ltv\MysqlCustomerRepository;
use Prosper202\Ltv\MysqlSubscriptionRepository;

/**
 * The nested objects of the conversion and LTV writes, through the real
 * repositories: a body whose line item or customer_crm cannot be read writes
 * nothing at all (no conversion, customer, revenue event, line item or
 * product), and one that can is stored exactly as sent — numeric strings
 * included, which the strict reading must not turn away.
 *
 * Before the change, executed on a live instance: a line item's `unit_pirce`
 * stored the line at 0 with a 201, "abc" stored 0, `qty` stored one unit, a
 * customer_crm country of "United States" was a 500 under strict mode, and a
 * custom text field given an object stored "Array". NestedBodyFieldsTest
 * holds every handler to the refusal without a database; this holds the
 * write path.
 *
 * Skips unless a scratch database is configured (P202_TEST_DB_HOST,
 * P202_TEST_DB_PORT, P202_TEST_DB_USER, P202_TEST_DB_PASS, P202_TEST_DB_NAME);
 * it installs the schema there and writes user 5931's rows and clicks
 * 977001-977004.
 *
 * @group integration
 */
final class NestedBodyValuesIntegrationTest extends TestCase
{
    private const USER = 5931;
    private const CLICKS = [977001, 977002, 977003, 977004];

    /** The click tables the fixture writes, by click id. */
    private const CLICK_TABLES = [
        '202_clicks', '202_clicks_spy', '202_clicks_tracking', '202_clicks_record', '202_dataengine',
    ];

    /** The account's tables the tests write, by user id. */
    private const USER_TABLES = [
        '202_conversion_logs', '202_revenue_events', '202_revenue_line_items', '202_products', '202_customers',
        '202_customer_aliases', '202_customer_fields', '202_customer_field_values', '202_subscriptions',
        '202_ltv_integrations', '202_aff_campaigns', '202_companies', '202_notification_pending',
    ];

    /** What a refused body must leave as it was. */
    private const WRITTEN = [
        '202_conversion_logs', '202_customers', '202_customer_aliases', '202_revenue_events',
        '202_revenue_line_items', '202_products', '202_customer_field_values', '202_subscriptions',
    ];

    private static ?\mysqli $db = null;

    public static function setUpBeforeClass(): void
    {
        $host = getenv('P202_TEST_DB_HOST');
        if ($host === false || $host === '') {
            return;
        }
        if (!function_exists('_mysqli_query')) {
            eval('function _mysqli_query($dbOrSql, $sql = null) '
                . '{ return $sql === null ? null : $dbOrSql->query($sql); }');
        }
        if (!class_exists('DataEngine', false)) {
            eval('class DataEngine { public function setDirtyHour($id) {} '
                . 'public function getSummary($s,$e,$p,$u=1,$up=false,$n=false){ return ""; } }');
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
        (new SchemaInstaller($db))->install();
        self::$db = $db;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
        }
        self::$db = null;
    }

    protected function setUp(): void
    {
        if (self::$db === null) {
            self::markTestSkipped('No test database configured (set P202_TEST_DB_HOST).');
        }
        self::cleanUp();
        // The fixture rows leave columns to their defaults, as the click
        // path's inserts do on an install without strict mode.
        self::q("SET SESSION sql_mode=''");
        $user = self::USER;
        $campaign = self::q("INSERT INTO 202_aff_campaigns SET user_id = $user, aff_network_id = 1,
            aff_campaign_name = 'Nested', aff_campaign_url = 'https://nested.example/offer', aff_campaign_payout = 10,
            aff_campaign_foreign_payout = 0, aff_campaign_time = 0, payout_mode = 'replace'");
        foreach (self::CLICKS as $click) {
            foreach (['202_clicks', '202_clicks_spy'] as $table) {
                self::q("INSERT INTO $table SET click_id = $click, user_id = $user, aff_campaign_id = $campaign,
                    click_payout = 10, click_cpc = 0, click_lead = 0, click_time = 1700000000");
            }
        }
        // The writes under test run strict, as this server's own default is:
        // a value past its column is then an error rather than a silent cut,
        // so a check that let one through shows as a database exception.
        self::q("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ENGINE_SUBSTITUTION'");
    }

    private static function cleanUp(): void
    {
        $clicks = implode(',', self::CLICKS);
        foreach (self::CLICK_TABLES as $table) {
            self::$db->query("DELETE FROM $table WHERE click_id IN ($clicks)");
        }
        foreach (self::USER_TABLES as $table) {
            self::$db->query("DELETE FROM $table WHERE user_id = " . self::USER);
        }
    }

    private static function q(string $sql): int
    {
        self::assertTrue(self::$db->query($sql), self::$db->error . ' in ' . $sql);

        return (int) self::$db->insert_id;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(string $sql): array
    {
        $result = self::$db->query($sql);
        self::assertInstanceOf(\mysqli_result::class, $result, $sql);

        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /** @return array<string, int> how many rows of each LTV write this user has */
    private static function written(): array
    {
        $counts = [];
        foreach (self::WRITTEN as $table) {
            $rows = self::rows("SELECT COUNT(*) AS c FROM $table WHERE user_id = " . self::USER);
            $counts[$table] = (int) $rows[0]['c'];
        }

        return $counts;
    }

    /**
     * @param callable(): mixed $call
     * @param list<string> $fields
     */
    private static function assertRefusedWritingNothing(callable $call, array $fields, string $what): void
    {
        $before = self::written();
        try {
            $call();
            self::fail("$what was accepted");
        } catch (ValidationException $e) {
            $named = array_keys($e->getFieldErrors());
            sort($named);
            self::assertSame($fields, $named, "$what: " . json_encode($e->getFieldErrors()));
        }
        self::assertSame($before, self::written(), "$what: nothing was written");
    }

    public function testAConversionWhoseLineItemOrCrmCannotBeReadWritesNothing(): void
    {
        $conversions = new ConversionsController(self::$db, self::USER);
        $base = ['click_id' => self::CLICKS[0], 'payout' => 50, 'customer_ref' => 'nested-1'];

        self::assertRefusedWritingNothing(
            fn () => $conversions->create($base + [
                'transaction_id' => 'N-1',
                'items' => [['sku' => 'A', 'unit_pirce' => 25, 'quantity' => 2]],
            ]),
            ['items.0.unit_pirce'],
            'a misspelled unit price'
        );
        self::assertRefusedWritingNothing(
            fn () => $conversions->create($base + [
                'transaction_id' => 'N-2',
                'items' => [['sku' => 'A', 'unit_price' => 'abc']],
            ]),
            ['items.0.unit_price'],
            'a unit price that is not a number'
        );
        // Under strict mode this was a 500 (and "Un" without it).
        self::assertRefusedWritingNothing(
            fn () => $conversions->create($base + [
                'transaction_id' => 'N-3',
                'customer_crm' => ['first_name' => 'Ann', 'country' => 'United States'],
            ]),
            ['customer_crm.country'],
            'a country past its column'
        );
    }

    /**
     * Line items live on the customer's revenue event and CRM fields on the
     * customer: with no customer resolved (none named, the click linked to
     * none, no customer c-param) they were dropped and the conversion
     * answered 201. They are refused, naming the field and the identity to
     * send, and nothing is written; a click already linked to a customer is
     * enough.
     */
    public function testLineItemsOrCrmThatFindNoCustomerAreRefusedWritingNothing(): void
    {
        $conversions = new ConversionsController(self::$db, self::USER);
        $base = ['click_id' => self::CLICKS[2], 'payout' => 30];
        $items = [['sku' => 'NC-1', 'unit_price' => 15, 'quantity' => 2]];

        self::assertRefusedWritingNothing(
            fn () => $conversions->create($base + ['transaction_id' => 'NC-1', 'items' => $items]),
            ['items'],
            'line items with no customer'
        );
        self::assertRefusedWritingNothing(
            fn () => $conversions->create($base + [
                'transaction_id' => 'NC-2',
                'customer_crm' => ['first_name' => 'Ann'],
            ]),
            ['customer_crm'],
            'CRM fields with no customer'
        );
        try {
            $conversions->create($base + ['transaction_id' => 'NC-3', 'items' => $items]);
            self::fail('line items with no customer were accepted');
        } catch (ValidationException $e) {
            self::assertStringContainsString('customer_ref', $e->getFieldErrors()['items']);
            self::assertStringContainsString('customer_id', $e->getFieldErrors()['items']);
        }

        // A click an earlier conversion linked to a customer needs no name.
        $linked = ['click_id' => self::CLICKS[3], 'payout' => 30];
        $conversions->create($linked + ['transaction_id' => 'NC-L1', 'customer_ref' => 'nested-nc']);
        $conversions->create($linked + ['transaction_id' => 'NC-L2', 'items' => $items]);
        $user = self::USER;
        self::assertSame(
            [['sku' => 'NC-1', 'quantity' => '2.000', 'primary_ref' => 'nested-nc']],
            self::rows("SELECT li.sku, li.quantity, c.primary_ref FROM 202_revenue_line_items li
                JOIN 202_revenue_events e ON e.event_id = li.event_id
                JOIN 202_customers c ON c.customer_id = e.customer_id
                WHERE li.user_id = $user")
        );
    }

    /**
     * A pixel has nobody to answer and refusing would lose its conversion
     * too: without ltv_requires_customer the conversion is recorded, the
     * product is not, and the result names what was not stored.
     */
    public function testAWriterThatCannotBeAnsweredKeepsTheConversionAndIsToldWhatWasNotStored(): void
    {
        $repo = new \Prosper202\Conversion\MysqlConversionRepository(new \Prosper202\Database\Connection(self::$db));
        $result = $repo->record(self::USER, [
            'click_id' => self::CLICKS[2], 'transaction_id' => 'PX-1', 'payout' => '30', 'source' => 'pixel',
            'items' => [['sku' => 'PX-SKU', 'unit_price' => 30]],
        ]);

        self::assertGreaterThan(0, $result['convId'], 'the conversion stands');
        self::assertSame(['items'], $result['ltvDropped'] ?? null);
        self::assertSame(0, self::written()['202_revenue_line_items']);
    }

    public function testAConversionWhoseNestedValuesCanBeReadIsStoredAsSent(): void
    {
        $conversions = new ConversionsController(self::$db, self::USER);
        $conversions->create([
            'click_id' => self::CLICKS[1],
            'payout' => 62,
            'transaction_id' => 'N-OK',
            'customer_ref' => 'nested-2',
            'customer_crm' => [
                'first_name' => 'Ann', 'email' => 'ann@example.com', 'country' => 'US', 'postal_code' => 2134,
            ],
            'items' => [
                ['sku' => 'SKU-A', 'unit_price' => '25', 'quantity' => '2'],
                ['external_product_id' => 'P-1', 'name' => 'Widget', 'amount' => 12, 'price' => '12.5'],
            ],
        ]);

        $user = self::USER;
        self::assertSame(
            [['first_name' => 'Ann', 'email' => 'ann@example.com', 'country' => 'US', 'postal_code' => '2134']],
            self::rows("SELECT first_name, email, country, postal_code FROM 202_customers
                WHERE user_id = $user AND primary_ref = 'nested-2'")
        );
        self::assertSame([
            ['sku' => 'SKU-A', 'product_name' => null, 'quantity' => '2.000', 'unit_price' => '25.00000',
                'amount' => '50.00000'],
            ['sku' => null, 'product_name' => 'Widget', 'quantity' => '1.000', 'unit_price' => null,
                'amount' => '12.00000'],
        ], self::rows("SELECT sku, product_name, quantity, unit_price, amount FROM 202_revenue_line_items
            WHERE user_id = $user ORDER BY line_item_id"));
        self::assertSame(
            [['external_product_id' => 'P-1', 'price' => '12.50000']],
            self::rows("SELECT external_product_id, price FROM 202_products
                WHERE user_id = $user AND external_product_id = 'P-1'")
        );
    }

    public function testARevenueEventWhoseLineItemCannotBeReadWritesNothingAndOneThatCanIsStored(): void
    {
        $ltv = new LtvController(self::$db, self::USER);
        $event = ['amount' => 30, 'customer_ref' => 'nested-3'];
        self::assertRefusedWritingNothing(
            fn () => $ltv->recordRevenue($event + [
                'idempotency_key' => 'nested-r1',
                'items' => [['external_product_id' => 'P-9', 'qty' => 3, 'unit_price' => 10]],
            ]),
            ['items.0.qty'],
            'qty for quantity'
        );

        $ltv->recordRevenue($event + [
            'idempotency_key' => 'nested-r2',
            'items' => [['external_product_id' => 'P-9', 'quantity' => 3, 'unit_price' => 10]],
        ]);
        self::assertSame(
            [['quantity' => '3.000', 'unit_price' => '10.00000', 'amount' => '30.00000']],
            self::rows('SELECT quantity, unit_price, amount FROM 202_revenue_line_items WHERE user_id = ' . self::USER)
        );
    }

    public function testACustomFieldValueThatIsAnObjectIsRefusedNotStoredAsArray(): void
    {
        $ltv = new LtvController(self::$db, self::USER);
        $ltv->createField(['field_key' => 'notes', 'field_type' => 'text']);
        $customer = (int) $ltv->upsertCustomer(['customer_ref' => 'nested-4'])['data']['customer_id'];

        $before = self::written();
        try {
            $ltv->patchCustomer($customer, ['custom_fields' => ['notes' => ['a' => 1]]]);
            self::fail('an object was accepted as a text value');
        } catch (ValidationException $e) {
            self::assertStringContainsString('expects one value', $e->getMessage());
        }
        self::assertSame($before, self::written(), 'nothing was written');

        $ltv->patchCustomer($customer, ['custom_fields' => ['notes' => 'VIP']]);
        self::assertSame(
            [['value_text' => 'VIP']],
            self::rows('SELECT value_text FROM 202_customer_field_values WHERE user_id = ' . self::USER)
        );
    }

    public function testEmptyIntegrationSettingsAreServedAsAnObject(): void
    {
        $ltv = new LtvController(self::$db, self::USER);
        $ltv->createIntegration(['provider' => 'klaviyo', 'config' => []]);
        $ltv->createIntegration(['provider' => 'mailchimp', 'config' => ['list' => 'L1', 'tags' => []]]);

        $served = json_encode($ltv->listIntegrations()['data']);
        self::assertIsString($served);
        self::assertStringContainsString('"provider":"klaviyo","name":"klaviyo","config":{}', $served);
        self::assertStringContainsString('"config":{"list":"L1","tags":[]}', $served);
    }

    /**
     * The repositories' own floor, for a caller that did not check: a nested
     * value that is not an array is an error, not "none".
     */
    public function testTheRepositoriesRefuseANestedValueThatIsNotAnArray(): void
    {
        $conn = new Connection(self::$db);
        $subscriptions = new MysqlSubscriptionRepository($conn, new MysqlCustomerRepository($conn));
        try {
            $subscriptions->upsert(self::USER, [
                'external_sub_id' => 'nested-s1', 'amount' => 9, 'customer_ref' => 'nested-5', 'customer_crm' => 'Ann',
            ]);
            self::fail('a customer_crm string was read as none');
        } catch (\RuntimeException $e) {
            self::assertSame('customer_crm must be an object of CRM fields', $e->getMessage());
        }

        $before = self::written();
        try {
            (new MysqlConversionRepository($conn))->record(self::USER, [
                'click_id' => self::CLICKS[2], 'transaction_id' => 'N-FLOOR', 'payout' => 5, 'conv_time' => 1700000100,
                'customer_ref' => 'nested-6', 'items' => 'SKU-A',
            ]);
            self::fail('items that are not a list were skipped');
        } catch (\RuntimeException $e) {
            self::assertSame('items must be a list of line items', $e->getMessage());
        }
        self::assertSame($before, self::written(), 'the conversion rolled back with its items');
    }
}
