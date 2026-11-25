<?php

declare(strict_types=1);

namespace Tests\Ltv;

use Api\V3\Controllers\LtvController;
use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Database\Exceptions\QueryException;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlWebhookRepository;

/**
 * Every write that rewrites customers' company queues customer.updated for
 * each customer it changed, as a change of the customer through the API
 * does: a rename (the attached customers' strings follow it), a merge (the
 * source's customers move), and the maintenance sweep's link, blank clear
 * and string repair. None queued one, so a subscriber's copy of those
 * customers kept the old company. Queued in the write's transaction (a
 * write rolled back queues nothing), and the sweep's only for customers last
 * written within EVENT_WINDOW_SECONDS, as its subscription changes are.
 *
 * @group integration
 */
final class CompanyChangesAnnounceCustomersIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990921;
    private const OTHER = 990922;

    /** The hook taking every event, and the one taking customer.updated. */
    private int $all = 0;
    private int $customers = 0;

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DROP TRIGGER IF EXISTS p202_test_refuse_delivery');
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DROP TRIGGER IF EXISTS p202_test_refuse_delivery');
        self::cleanUp();
        $webhooks = new MysqlWebhookRepository(new Connection(self::$db));
        $this->all = $webhooks->create(self::USER, 'https://hooks.example/all', ['*'])['webhookId'];
        $this->customers = $webhooks->create(self::USER, 'https://hooks.example/customers', ['customer.updated'])
            ['webhookId'];
        // Subscribed to another event: a company change is not its.
        $webhooks->create(self::USER, 'https://hooks.example/revenue', ['revenue.recorded']);
        // Another account's hook hears nothing of this account's customers.
        $webhooks->create(self::OTHER, 'https://hooks.example/other', ['*']);
    }

    private static function cleanUp(): void
    {
        $users = self::USER . ', ' . self::OTHER;
        foreach (['202_customers', '202_companies', '202_ltv_webhooks', '202_ltv_webhook_deliveries'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN ($users)");
        }
    }

    public function testARenameAnnouncesTheCustomersWhoseStringItChanges(): void
    {
        $acme = $this->newCompany('Acme');
        $now = time();
        $a = self::customer(self::USER, 'Acme', $acme, 1);
        $b = self::customer(self::USER, 'Acme', $acme, $now - 60);
        $drift = self::customer(self::USER, null, $acme, 1);
        // Already the new name, byte for byte: the rename does not change it.
        $same = self::customer(self::USER, 'ACME', $acme, 1);
        $elsewhere = self::customer(self::USER, 'Acme', null, 1);

        // Through the API, as PATCH /ltv/companies/{id} does; a case-only rename is a change.
        $this->api()->patchCompany($acme, ['name' => 'ACME']);

        self::assertSame([$a, $b, $drift], $this->queued(), 'each customer it changed, once a hook, old and new alike');
        foreach ([$a, $b, $drift, $same] as $id) {
            self::assertSame('ACME', self::row($id)['company']);
        }
        self::assertSame('1', self::row($same)['updated_at'], 'a customer it does not change is not written');
        self::assertSame(['company' => 'Acme', 'company_id' => null, 'updated_at' => '1'], self::row($elsewhere));

        // A domain-only change rewrites no customer.
        $this->api()->patchCompany($acme, ['domain' => 'acme.example']);
        self::assertSame([$a, $b, $drift], $this->queued());
    }

    public function testTheEventIsTheOneAChangeOfTheCustomerThroughTheApiQueues(): void
    {
        $acme = $this->newCompany('Acme');
        $renamed = self::customer(self::USER, 'Acme', $acme, 1);
        $patched = self::customer(self::USER, 'Acme', $acme, 1);
        $this->api()->upsertCustomer(['customer_id' => $patched, 'company' => 'Initech']);
        $this->api()->patchCompany($acme, ['name' => 'Acme Corp']);

        $bodies = $this->bodies();
        self::assertSame([$patched, $renamed], array_keys($bodies));
        self::assertSame(['customer_id' => $renamed], $bodies[$renamed]['data']);
        self::assertSame($bodies[$patched]['data'], ['customer_id' => $patched]);
        self::assertSame(self::shape($bodies[$patched]), self::shape($bodies[$renamed]));
        self::assertEqualsWithDelta(time(), $bodies[$renamed]['occurred_at'], 120);
    }

    public function testAMergeAnnouncesTheSourcesCustomers(): void
    {
        $kept = $this->newCompany('Kept');
        $gone = $this->newCompany('Gone');
        $own = self::customer(self::USER, 'Kept', $kept, 1);
        $moved = [self::customer(self::USER, 'Gone', $gone, 1), self::customer(self::USER, 'Gone', $gone, 1)];

        $this->api()->mergeCompany($kept, ['source_company_id' => $gone]);

        self::assertSame($moved, $this->queued(), 'the customers it moved, not the target\'s own');
        foreach ([$own, ...$moved] as $id) {
            self::assertSame(['Kept', (string) $kept], [self::row($id)['company'], self::row($id)['company_id']]);
        }
    }

    public function testTheSweepAnnouncesWhatItLinksAndClearsWhenLastWrittenRecently(): void
    {
        $now = time();
        $window = MysqlCompanyRepository::EVENT_WINDOW_SECONDS;
        $recent = self::customer(self::USER, 'Acme', null, $now - $window + 3600);
        $old = self::customer(self::USER, 'Acme', null, $now - $window - 3600);
        $recentBlank = self::customer(self::USER, "\t", null, $now - 60);
        $oldBlank = self::customer(self::USER, "\t", null, 1);
        $others = self::customer(self::OTHER, 'Acme', null, $now);

        self::assertSame(2, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));

        self::assertSame([$recent, $recentBlank], $this->queued(), 'every customer is written, the recent announced');
        self::assertNotNull(self::row($old)['company_id']);
        self::assertSame('', self::row($oldBlank)['company']);
        self::assertNull(self::row($others)['company_id'], 'another account is not swept');
        self::assertSame(0, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));
        self::assertSame([$recent, $recentBlank], $this->queued(), 'nothing written, nothing announced');
    }

    public function testTheSweepsStringRepairAnnouncesTheRecentCustomersItCorrects(): void
    {
        $acme = $this->newCompany('Acme Corp');
        $now = time();
        $recent = self::customer(self::USER, 'Acme Incorporated', $acme, $now - 60);
        $old = self::customer(self::USER, null, $acme, 1);
        $right = self::customer(self::USER, 'Acme Corp', $acme, $now - 60);

        self::assertGreaterThanOrEqual(2, $this->sweep()->reconcileAttachedStrings());

        self::assertSame([$recent], $this->queued());
        foreach ([$recent, $old, $right] as $id) {
            self::assertSame('Acme Corp', self::row($id)['company']);
        }
    }

    public function testAWriteRolledBackQueuesNothingAndItsRetryQueuesOnce(): void
    {
        $acme = $this->newCompany('Acme');
        $c = self::customer(self::USER, 'Acme', $acme, 1);
        self::q("CREATE TRIGGER p202_test_refuse_delivery BEFORE INSERT ON 202_ltv_webhook_deliveries FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'delivery refused by the test'");

        $repo = $this->sweep();
        $refused = null;
        try {
            $repo->update(self::USER, $acme, ['name' => 'Initech']);
        } catch (QueryException $e) {
            $refused = $e;
        }
        self::assertNotNull($refused, 'a rename whose event cannot be queued is not committed');
        self::assertStringContainsString('delivery refused by the test', $refused->getMessage());
        self::assertSame('Acme', self::row($c)['company'], 'the rename rolled back with its event');
        self::assertSame('Acme', $repo->get(self::USER, $acme)['name'] ?? null);

        self::q('DROP TRIGGER p202_test_refuse_delivery');
        $repo->update(self::USER, $acme, ['name' => 'Initech']);
        self::assertSame('Initech', self::row($c)['company']);
        self::assertSame([$c], $this->queued());
    }

    public function testARenameOfManyCustomersQueuesEachInChunks(): void
    {
        $acme = $this->newCompany('Acme');
        $count = (int) ceil(MysqlWebhookRepository::ENQUEUE_ROWS / 2) + 7;
        $values = [];
        for ($i = 0; $i < $count; $i++) {
            $values[] = '(' . self::USER . ", 'many-$i', 'Acme', $acme, 1, 1, 1, 1)";
        }
        self::q('INSERT INTO 202_customers (user_id, primary_ref, company, company_id, first_seen_time,'
            . ' last_activity_time, created_at, updated_at) VALUES ' . implode(', ', $values));

        $this->sweep()->update(self::USER, $acme, ['name' => 'Initech']);

        // Two hooks: more deliveries than one statement inserts.
        self::assertCount($count, $this->queued());
    }

    private function api(): LtvController
    {
        return new LtvController(self::$db ?? throw new \LogicException(), self::USER);
    }

    private function sweep(): MysqlCompanyRepository
    {
        return new MysqlCompanyRepository(new Connection(self::$db ?? throw new \LogicException()));
    }

    private function newCompany(string $name): int
    {
        return $this->sweep()->resolveOrCreate(self::USER, $name);
    }

    /**
     * The customers announced, in the order queued, after checking that both
     * hooks that take customer.updated got the same events and no other hook
     * got any.
     *
     * @return list<int>
     */
    private function queued(): array
    {
        return array_keys($this->bodies());
    }

    /** @return array<int, array<string, mixed>> customer id => body, in the order queued */
    private function bodies(): array
    {
        $byHook = [$this->all => [], $this->customers => []];
        $rows = self::$db->query('SELECT webhook_id, event_name, payload FROM 202_ltv_webhook_deliveries'
            . ' WHERE user_id IN (' . self::USER . ', ' . self::OTHER . ') ORDER BY delivery_id');
        self::assertInstanceOf(\mysqli_result::class, $rows);
        foreach ($rows->fetch_all(MYSQLI_ASSOC) as $row) {
            $hook = (int) $row['webhook_id'];
            self::assertArrayHasKey($hook, $byHook, 'a hook that does not take customer.updated was queued one');
            self::assertSame('customer.updated', $row['event_name']);
            $body = json_decode((string) $row['payload'], true, 8, JSON_THROW_ON_ERROR);
            self::assertIsArray($body);
            self::assertSame('customer.updated', $body['event']);
            $id = $body['data']['customer_id'] ?? null;
            self::assertIsInt($id, (string) $row['payload']);
            self::assertArrayNotHasKey($id, $byHook[$hook], 'one event per customer changed');
            $byHook[$hook][$id] = $body;
        }
        self::assertSame(array_keys($byHook[$this->all]), array_keys($byHook[$this->customers]));

        return $byHook[$this->all];
    }

    /** @return array{company: string|null, company_id: string|null, updated_at: string} */
    private static function row(int $customerId): array
    {
        $result = self::$db->query(
            "SELECT company, company_id, updated_at FROM 202_customers WHERE customer_id = $customerId"
        );
        self::assertNotFalse($result, self::$db->error);
        $row = $result->fetch_assoc();
        self::assertIsArray($row, "customer $customerId");

        return $row;
    }

    private static function customer(int $userId, ?string $company, ?int $companyId, int $updatedAt): int
    {
        return self::insert('INSERT INTO 202_customers SET user_id = ' . $userId
            . ", primary_ref = 'ref-" . uniqid('', true) . "'"
            . ', company = ' . ($company === null ? 'NULL' : self::str($company))
            . ', company_id = ' . ($companyId === null ? 'NULL' : $companyId)
            . ', first_seen_time = 1, last_activity_time = 1, created_at = 1, updated_at = ' . $updatedAt);
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
}
