<?php

declare(strict_types=1);

namespace Tests\Api\V3;

use Api\V3\Controllers\ConversionsController;
use Api\V3\Controllers\LtvController;
use Api\V3\Controllers\SyncController;
use Api\V3\Exception\ValidationException;
use Api\V3\Support\LtvBody;
use Api\V3\Support\PayloadKeys;
use Api\V3\Support\ServerStateStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeMysqliConnection;

/**
 * The objects and lists inside a request body are read as strictly as the
 * body itself (CLAUDE.md #4).
 *
 * Every top-level key was already refused by name (PayloadKeys::
 * refuseUnknown()), but the objects inside were read by hand, for the keys
 * the reader knew, and cast: executed on a live instance before this
 * change, `{"items": [{"sku": "A", "unit_pirce": 25, "quantity": 2}]}` on
 * POST /conversions answered 201 and stored the line at an amount of 0,
 * `"unit_price": "abc"` stored 0, `qty` on POST /ltv/revenue stored one
 * unit, `customer_crm.frist_name` and `aliases.0.tpye` were dropped (the
 * alias stored as a custom one), `"events": "revenue.recorded"` subscribed a
 * webhook to every event, a select field's `{"gold": "Gold", "silver":
 * ["Silver"]}` became the choices "Gold" and "Array", and
 * `"prune_denylist": "campaigns"` protected nothing from a prune.
 *
 * Each is now a 422 naming the field with its place in the body
 * (`items.0.unit_pirce`, `customer_crm.country`), every refusal at once,
 * before anything is read or written: the fake connection prepares nothing.
 */
final class NestedBodyFieldsTest extends TestCase
{
    /** @var list<string> */
    private array $scratch = [];

    protected function tearDown(): void
    {
        foreach ($this->scratch as $dir) {
            exec('rm -rf ' . escapeshellarg($dir));
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{string, array<string, mixed>, list<string>}>
     */
    public static function refused(): iterable
    {
        $long = static fn (int $n): string => str_repeat('x', $n);

        // POST /conversions
        yield 'conversion: a misspelled line item key' => [
            'conversion',
            ['items' => [['sku' => 'A', 'unit_pirce' => 25, 'quantity' => 2]]],
            ['items.0.unit_pirce'],
        ];
        yield 'conversion: a unit price that is not a number' => [
            'conversion',
            ['items' => [['sku' => 'A', 'unit_price' => 'abc']]],
            ['items.0.unit_price'],
        ];
        yield 'conversion: an amount that is not a number' => [
            'conversion',
            ['items' => [['sku' => 'A', 'amount' => true]]],
            ['items.0.amount'],
        ];
        yield 'conversion: qty for quantity' => [
            'conversion',
            ['items' => [['sku' => 'A', 'qty' => 3]]],
            ['items.0.qty'],
        ];
        yield 'conversion: a quantity of 0' => [
            'conversion',
            ['items' => [['sku' => 'A', 'quantity' => 0]]],
            ['items.0.quantity'],
        ];
        yield 'conversion: a quantity the column stores as 0' => [
            'conversion',
            ['items' => [['sku' => 'A', 'quantity' => '0.0004']]],
            ['items.0.quantity'],
        ];
        yield 'conversion: a line with no product' => [
            'conversion',
            ['items' => [['name' => 'Widget']]],
            ['items.0.external_product_id'],
        ];
        yield 'conversion: a sku that is a list' => ['conversion', ['items' => [['sku' => ['A']]]], ['items.0.sku']];
        yield 'conversion: a sku key past its column' => [
            'conversion',
            ['items' => [['sku' => $long(188)]]],
            ['items.0.sku'],
        ];
        yield 'conversion: a price past its column' => [
            'conversion',
            ['items' => [['sku' => 'A', 'price' => 1e9]]],
            ['items.0.price'],
        ];
        yield 'conversion: a negative list price' => [
            'conversion',
            ['items' => [['sku' => 'A', 'price' => -1]]],
            ['items.0.price'],
        ];
        yield 'conversion: a computed amount past its column' => [
            'conversion',
            ['items' => [['sku' => 'A', 'unit_price' => 999999999, 'quantity' => 1000]]],
            ['items.0.amount'],
        ];
        yield 'conversion: items that are not a list' => ['conversion', ['items' => ['sku' => 'A']], ['items']];
        yield 'conversion: a line that is not an object' => [
            'conversion',
            ['items' => [['sku' => 'A'], 'B']],
            ['items.1'],
        ];
        yield 'conversion: a misspelled CRM key' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => ['frist_name' => 'Ann']],
            ['customer_crm.frist_name'],
        ];
        yield 'conversion: customer_crm that is not an object' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => 'Ann'],
            ['customer_crm'],
        ];
        yield 'conversion: customer_crm that is a list' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => ['Ann']],
            ['customer_crm'],
        ];
        yield 'conversion: a country past its column' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => ['country' => 'United States']],
            ['customer_crm.country'],
        ];
        yield 'conversion: an email that is not one' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => ['email' => 'nope']],
            ['customer_crm.email'],
        ];
        yield 'conversion: a CRM value that is an object' => [
            'conversion',
            ['customer_ref' => 'c1', 'customer_crm' => ['first_name' => ['a' => 1]]],
            ['customer_crm.first_name'],
        ];
        yield 'conversion: every refusal at once' => ['conversion', [
            'customer_ref' => 'c1',
            'customer_crm' => ['frist_name' => 'Ann', 'phone' => $long(51)],
            'items' => [['sku' => 'A', 'unit_pirce' => 1], ['external_product_id' => 'P', 'quantity' => 'two']],
        ], ['customer_crm.frist_name', 'customer_crm.phone', 'items.0.unit_pirce', 'items.1.quantity']];

        // POST /ltv/revenue, /ltv/events, /ltv/subscriptions
        yield 'revenue: qty for quantity' => [
            'revenue',
            ['amount' => 30, 'customer_ref' => 'c1', 'items' => [['external_product_id' => 'P', 'qty' => 3]]],
            ['items.0.qty'],
        ];
        yield 'revenue: a misspelled CRM key' => [
            'revenue',
            ['amount' => 30, 'customer_ref' => 'c1', 'customer_crm' => ['lastname' => 'Zed']],
            ['customer_crm.lastname'],
        ];
        yield 'engagement: customer_crm that is a string' => [
            'engagement',
            ['event' => 'demo', 'customer_ref' => 'c1', 'customer_crm' => 'x'],
            ['customer_crm'],
        ];
        yield 'subscription: a misspelled CRM key' => [
            'subscription',
            ['external_sub_id' => 's1', 'amount' => 9, 'customer_ref' => 'c1', 'customer_crm' => ['emial' => 'a@b.co']],
            ['customer_crm.emial'],
        ];
        yield 'subscription: a customer id that is not one' => [
            'subscription',
            ['external_sub_id' => 's1', 'amount' => 9, 'customer_id' => '12abc'],
            ['customer_id'],
        ];

        // POST /ltv/customers, PATCH /ltv/customers/{id}, POST …/aliases
        yield 'customer: a misspelled alias key' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => [['tpye' => 'esp_id', 'value' => 'E-1']]],
            ['aliases.0.tpye'],
        ];
        yield 'customer: an alias value that is an object' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => [['value' => ['a' => 1]]]],
            ['aliases.0.value'],
        ];
        yield 'customer: an alias with no value' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => [['type' => 'esp_id']]],
            ['aliases.0.value'],
        ];
        yield 'customer: an alias type that is not one' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => [['type' => 'espid', 'value' => 'E']]],
            ['aliases.0.type'],
        ];
        yield 'customer: an email digest alias that is not one' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => [['type' => 'email_md5', 'value' => 'nothex']]],
            ['aliases.0.value'],
        ];
        yield 'customer: aliases that are not a list' => [
            'customer',
            ['customer_ref' => 'c1', 'aliases' => 'E-1'],
            ['aliases'],
        ];
        yield 'customer: a record country past its column' => [
            'customer',
            ['customer_ref' => 'c1', 'country' => 'USA'],
            ['country'],
        ];
        yield 'customer: a record field that is a list' => [
            'customer',
            ['customer_ref' => 'c1', 'city' => ['Boston']],
            ['city'],
        ];
        yield 'customer: a customer id that is not one' => ['customer', ['customer_id' => '12abc'], ['customer_id']];
        yield 'customer patch: a misspelled alias key' => [
            'patchCustomer',
            ['aliases' => [['type' => 'esp_id', 'vaule' => 'E']]],
            ['aliases.0.value', 'aliases.0.vaule'],
        ];
        yield 'customer patch: an email that is not one' => ['patchCustomer', ['email' => 'nope'], ['email']];
        yield 'alias: a value that is an object' => ['alias', ['value' => ['a' => 1], 'type' => 'esp_id'], ['value']];
        yield 'alias: a type that is not one' => ['alias', ['value' => 'E', 'type' => 'bogus'], ['type']];
        yield 'alias: a blank value' => ['alias', ['value' => '  '], ['value']];

        // POST /ltv/webhooks, /ltv/integrations, /ltv/fields, PATCH /ltv/fields/{id}, POST /ltv/products
        yield 'webhook: events that are a string' => [
            'webhook',
            ['url' => 'https://hooks.example.com/x', 'events' => 'revenue.recorded'],
            ['events'],
        ];
        yield 'webhook: an event that is not a name' => [
            'webhook',
            ['url' => 'https://hooks.example.com/x', 'events' => ['revenue.recorded', ['x' => 1]]],
            ['events.1'],
        ];
        yield 'integration: config that is a list' => [
            'integration',
            ['provider' => 'klaviyo', 'config' => [1, 2]],
            ['config'],
        ];
        yield 'integration: config that is a string' => [
            'integration',
            ['provider' => 'klaviyo', 'config' => 'x'],
            ['config'],
        ];
        yield 'field: options that are an object' => [
            'field',
            ['field_key' => 'tier', 'field_type' => 'select', 'options' => ['gold' => 'Gold']],
            ['options'],
        ];
        yield 'field: an option that is a list' => [
            'field',
            ['field_key' => 'tier', 'field_type' => 'select', 'options' => ['Gold', ['Silver']]],
            ['options.1'],
        ];
        yield 'field: options on a text field' => ['field', ['field_key' => 'notes', 'options' => ['a']], ['options']];
        yield 'field update: a blank option' => ['fieldUpdate', ['options' => ['Gold', ' ']], ['options.1']];
        yield 'product: a price that is not a number' => ['product', ['sku' => 'A', 'price' => 'abc'], ['price']];
        yield 'product: a name past its column' => ['product', ['sku' => 'A', 'name' => $long(256)], ['name']];
        yield 'product: no key' => ['product', ['name' => 'Widget'], ['external_product_id']];

        // POST /sync/plan, /sync/jobs; GET /sync/status
        $profile = ['url' => 'https://a.example.com', 'api_key' => 'k'];
        yield 'sync job: a misspelled profile key' => [
            'syncJob',
            ['source' => $profile + ['nmae' => 'prod'], 'target' => $profile],
            ['source.nmae'],
        ];
        yield 'sync job: a profile value that is not a string' => [
            'syncJob',
            ['source' => ['url' => ['x'], 'api_key' => 'k'], 'target' => $profile],
            ['source.url'],
        ];
        yield 'sync job: a side named twice' => [
            'syncJob',
            ['source' => $profile, 'from' => $profile, 'target' => $profile],
            ['from'],
        ];
        yield 'sync job: a deny list that is a string' => [
            'syncJob',
            ['source' => $profile, 'target' => $profile, 'prune_denylist' => 'campaigns'],
            ['prune_denylist'],
        ];
        yield 'sync job: a deny list naming no entity' => [
            'syncJob',
            ['source' => $profile, 'target' => $profile, 'prune_denylist' => ['campains']],
            ['prune_denylist.0'],
        ];
        yield 'sync job: an allow list entry that is not a name' => [
            'syncJob',
            ['source' => $profile, 'target' => $profile, 'prune_allowlist' => ['campaigns', 7]],
            ['prune_allowlist.1'],
        ];
        yield 'sync plan: a misspelled profile key' => [
            'syncPlan',
            ['source' => $profile, 'target' => $profile + ['apikey' => 'k']],
            ['target.apikey'],
        ];
        yield 'sync status: a misspelled profile key in the query' => [
            'syncStatus',
            ['source' => ['url' => 'https://a', 'nmae' => 'x'], 'target' => ['url' => 'https://b']],
            ['source.nmae'],
        ];
    }

    /**
     * @dataProvider refused
     * @param array<string, mixed> $payload
     * @param list<string> $fields
     */
    public function testANestedFieldThatCannotBeReadIsRefusedNamingItsPlace(
        string $handler,
        array $payload,
        array $fields
    ): void {
        $conn = new FakeMysqliConnection();
        try {
            $this->send($handler, $conn, $payload);
            self::fail("$handler answered instead of refusing " . json_encode($payload));
        } catch (ValidationException $e) {
            $named = array_keys($e->getFieldErrors());
            sort($named);
            $shown = json_encode($e->getFieldErrors());
            self::assertSame($fields, $named, "$handler: the 422 names " . implode(', ', $fields) . "\n" . $shown);
        }
        self::assertSame([], $conn->preparedSql, "$handler: nothing was read or written");
    }

    /**
     * What the checks accept: every value the repository reads the way it
     * was meant, so the same body is written as before.
     */
    public function testTheValuesTheRepositoryReadsAreAccepted(): void
    {
        $items = [
            ['sku' => 'A'],
            ['sku' => 'A', 'quantity' => 2, 'unit_price' => 25],
            ['sku' => 'A', 'quantity' => '2', 'unit_price' => '25.50', 'amount' => '51'],
            ['external_product_id' => 'P-1', 'name' => 'Widget', 'amount' => 10.5, 'price' => 12],
            ['external_product_id' => 12345, 'sku' => str_repeat('s', 191)],
            ['sku' => str_repeat('s', 187)],
            ['sku' => 'A', 'unit_price' => null, 'amount' => null, 'price' => null, 'name' => null],
            ['sku' => 'A', 'unit_price' => -5, 'amount' => -5],
            ['sku' => 'A', 'quantity' => 0.001, 'unit_price' => 999999999.99999],
        ];
        foreach ($items as $item) {
            self::assertSame([], LtvBody::lineItem($item), 'accepted: ' . json_encode($item));
        }
        $crms = [
            [],
            ['first_name' => 'Ann', 'email' => 'ann@example.com', 'country' => 'US'],
            ['postal_code' => 2134, 'phone' => null, 'city' => ''],
            ['company' => 'Acme  ' . str_repeat(' ', 300) . 'Inc'],
        ];
        foreach ($crms as $crm) {
            self::assertSame([], LtvBody::crm($crm), 'accepted: ' . json_encode($crm));
        }
        $aliases = [
            ['value' => 'E-1'],
            ['value' => 48213, 'type' => 'esp_id'],
            ['value' => 'E-1', 'type' => ''],
            ['value' => 'E-1', 'type' => ' Merchant_ID '],
            ['value' => md5('a@b.co'), 'type' => 'email_md5'],
        ];
        foreach ($aliases as $alias) {
            self::assertSame([], LtvBody::alias($alias), 'accepted: ' . json_encode($alias));
        }
    }

    public function testAnAbsentNullOrEmptyNestedValueIsNothingToCheck(): void
    {
        $refused = static fn (): string => 'refused';
        foreach ([[], ['items' => null], ['items' => []]] as $payload) {
            self::assertSame([], PayloadKeys::listErrors($payload, 'items', ['sku'], 'a line item', $refused));
        }
        // JSON's {} decodes to [], as [] does: an empty object, whose values
        // are checked and are none.
        foreach ([[], ['customer_crm' => null], ['customer_crm' => []]] as $payload) {
            $errors = PayloadKeys::objectErrors(
                $payload,
                'customer_crm',
                LtvBody::crmKeys(),
                'customer_crm',
                LtvBody::crm(...)
            );
            self::assertSame([], $errors);
        }
        foreach ([[], ['events' => null], ['events' => []]] as $payload) {
            self::assertSame([], PayloadKeys::valueListErrors($payload, 'events', 'event names', $refused));
        }
    }

    /**
     * The accepted keys and the keys MysqlCustomerRepository reads are one
     * list, in both directions: a key the list lacks is refused although the
     * repository writes it, and one the repository does not read is
     * accepted and dropped.
     */
    public function testTheCheckedKeysAreTheOnesTheRepositoryReads(): void
    {
        $repo = (string) file_get_contents(dirname(__DIR__, 3) . '/202-config/Ltv/MysqlCustomerRepository.php');
        // insertLineItems() reads $item, and hands it to upsertProduct() as $product.
        preg_match_all('/\$(?:item|product)\[\'([a-z_]+)\'\]/', $repo, $m);
        $read = array_values(array_unique($m[1]));
        sort($read);
        $listed = LtvBody::LINE_ITEM_KEYS;
        sort($listed);
        self::assertSame($listed, $read, 'LtvBody::LINE_ITEM_KEYS is what insertLineItems() and upsertProduct() read');

        // insertCustomer() reads customer_crm through $str('key'), and the email and company directly.
        preg_match_all('/\$str\(\'([a-z_0-9]+)\'\)|\$crm\[\'([a-z_0-9]+)\'\]/', $repo, $m);
        $named = array_filter([...$m[1], ...$m[2]], static fn (string $k): bool => $k !== '');
        $read = array_values(array_unique($named));
        sort($read);
        $listed = LtvBody::crmKeys();
        sort($listed);
        self::assertSame($listed, $read, 'LtvBody::CRM_MAX_LENGTH is what insertCustomer() reads from customer_crm');
    }

    /** @param array<string, mixed> $payload */
    private function send(string $handler, FakeMysqliConnection $conn, array $payload): void
    {
        $ltv = static fn (): LtvController => new LtvController($conn, 7);
        match ($handler) {
            'conversion' => (new ConversionsController($conn, 7))->create(['click_id' => 5] + $payload),
            'revenue' => $ltv()->recordRevenue($payload),
            'engagement' => $ltv()->recordEngagementEvent($payload),
            'subscription' => $ltv()->upsertSubscription($payload),
            'customer' => $ltv()->upsertCustomer($payload),
            'patchCustomer' => $ltv()->patchCustomer(3, $payload),
            'alias' => $ltv()->addAlias(3, $payload),
            'webhook' => $ltv()->createWebhook($payload),
            'integration' => $ltv()->createIntegration($payload),
            'field' => $ltv()->createField($payload),
            'fieldUpdate' => $ltv()->updateField(3, $payload),
            'product' => $ltv()->upsertProduct($payload),
            'syncJob' => $this->sync($conn)->createJob(['entity' => 'campaigns'] + $payload),
            'syncPlan' => $this->sync($conn)->plan(['entity' => 'campaigns'] + $payload),
            'syncStatus' => $this->sync($conn)->status($payload),
        };
    }

    private function sync(FakeMysqliConnection $conn): SyncController
    {
        $dir = sys_get_temp_dir() . '/p202-nested-body-' . bin2hex(random_bytes(6));
        $this->scratch[] = $dir;

        return new SyncController($conn, 7, new ServerStateStore($dir, 'nested-body-test'));
    }
}
