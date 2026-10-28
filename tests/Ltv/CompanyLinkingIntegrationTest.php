<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\MysqlCustomerRepository;

/**
 * The company-linking sweep on a real server. A company string that
 * canonicalName() reduces to nothing can never link; the sweep took the same
 * $limit of them first on every run (no order, and nothing changed them), so
 * an account with more than a batch of them ahead never linked anyone else.
 * The sweep walks in id order past them and stores each as '', once.
 *
 * @group integration
 */
final class CompanyLinkingIntegrationTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990473;
    private const OTHER = 990474;

    /** Strings canonicalName() reduces to '' that `company <> ''` still takes. */
    private const BLANKS = ["\t", "\u{00A0}", "\u{3000}", "\0", " \n ", "\u{2028}", "\t \u{00A0}"];

    public static function setUpBeforeClass(): void
    {
        if (self::connectScratch()) {
            self::cleanUp();
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::cleanUp();
            self::$db->close();
            self::$db = null;
        }
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::cleanUp();
    }

    private static function cleanUp(): void
    {
        foreach (['202_customers', '202_companies'] as $table) {
            self::q("DELETE FROM $table WHERE user_id IN (" . self::USER . ', ' . self::OTHER . ')');
        }
    }

    /**
     * Customers of one account, inserted in the order given, so their ids
     * rise in that order.
     *
     * @param list<string|null> $companies
     * @return list<int> their customer ids
     */
    private static function customers(int $userId, string $prefix, array $companies): array
    {
        $values = [];
        foreach ($companies as $i => $company) {
            $values[] = "($userId, " . self::str($prefix . $i) . ', '
                . ($company === null ? 'NULL' : self::str($company)) . ', 1, 1, 1, 1)';
        }
        self::q('INSERT INTO 202_customers (user_id, primary_ref, company, first_seen_time, last_activity_time,'
            . ' created_at, updated_at) VALUES ' . implode(', ', $values));

        $byRef = [];
        $result = self::$db->query("SELECT customer_id, primary_ref FROM 202_customers WHERE user_id = $userId"
            . ' AND primary_ref LIKE ' . self::str($prefix . '%') . ' ORDER BY customer_id');
        while ($row = $result->fetch_assoc()) {
            $byRef[$row['primary_ref']] = (int) $row['customer_id'];
        }
        $ids = [];
        foreach (array_keys($companies) as $i) {
            $ids[] = $byRef[$prefix . $i];
        }
        self::assertSame($ids, array_values($byRef), 'ids rise in the order the customers were given');

        return $ids;
    }

    /** @return array{company: string|null, company_id: int|null} */
    private static function customer(int $customerId): array
    {
        $row = self::$db->query("SELECT company, company_id FROM 202_customers WHERE customer_id = $customerId")
            ->fetch_assoc();
        self::assertIsArray($row, "customer $customerId");

        return [
            'company' => $row['company'],
            'company_id' => $row['company_id'] === null ? null : (int) $row['company_id'],
        ];
    }

    private static function companyId(int $userId, string $name): int
    {
        $row = self::$db->query("SELECT company_id FROM 202_companies WHERE user_id = $userId AND name = "
            . self::str($name))->fetch_assoc();
        self::assertIsArray($row, "company $name");

        return (int) $row['company_id'];
    }

    /** The cron's probe: customers it would call the sweep for. */
    private static function pending(int $userId): int
    {
        return (int) self::$db->query("SELECT COUNT(*) FROM 202_customers
            WHERE user_id = $userId AND company_id IS NULL AND company IS NOT NULL AND company <> ''
              AND merged_into_customer_id IS NULL")->fetch_row()[0];
    }

    private function sweep(): MysqlCompanyRepository
    {
        return new MysqlCompanyRepository(new Connection(self::$db));
    }

    /**
     * The account's customers whose updated_at is at or after $since, in id
     * order: the predicate a list's updated_since applies (Controller::list()),
     * which no customer list takes yet.
     *
     * @return list<int>
     */
    private static function changedSince(int $userId, int $since): array
    {
        $ids = [];
        $result = self::$db->query("SELECT customer_id FROM 202_customers WHERE user_id = $userId"
            . " AND updated_at >= $since ORDER BY customer_id");
        while ($row = $result->fetch_row()) {
            $ids[] = (int) $row[0];
        }

        return $ids;
    }

    public function testMoreThanABatchOfUnlinkableNamesAheadDoesNotHoldBackALinkableOne(): void
    {
        $blanks = [];
        for ($i = 0; $i < 2100; $i++) {
            $blanks[] = self::BLANKS[$i % count(self::BLANKS)];
        }
        $blankIds = self::customers(self::USER, 'blank-', $blanks);
        [$acme, $pristine, $detached] = self::customers(self::USER, 'after-', ['  Acme   Corp ', null, '']);
        self::assertSame(2101, self::pending(self::USER), 'every blank is taken by the probe and the select');

        // The cron's call: 2,000 per account per run.
        self::assertSame(1, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));

        self::assertSame(
            ['company' => 'Acme Corp', 'company_id' => self::companyId(self::USER, 'Acme Corp')],
            self::customer($acme)
        );
        foreach ($blankIds as $id) {
            self::assertSame(['company' => '', 'company_id' => null], self::customer($id), "blank customer $id");
        }
        self::assertSame(['company' => null, 'company_id' => null], self::customer($pristine), 'NULL stays NULL');
        self::assertSame(['company' => '', 'company_id' => null], self::customer($detached));

        self::assertSame(0, self::pending(self::USER), 'nothing is left for the next run to take again');
        self::assertSame(0, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));
    }

    public function testACallLinksUpToItsLimitInIdOrderAndTheNextGoesOn(): void
    {
        $ids = self::customers(self::USER, 'c-', ["\t", 'One', "\u{00A0}", 'Two', 'Three', "\t", 'Four', 'Five']);
        $linked = static fn (): array => array_values(array_filter(
            [$ids[1], $ids[3], $ids[4], $ids[6], $ids[7]],
            static fn (int $id): bool => self::customer($id)['company_id'] !== null
        ));

        self::assertSame(2, $this->sweep()->linkUnlinkedCustomers(self::USER, 2), 'a full call: there may be more');
        self::assertSame([$ids[1], $ids[3]], $linked());
        self::assertSame(2, $this->sweep()->linkUnlinkedCustomers(self::USER, 2));
        self::assertSame([$ids[1], $ids[3], $ids[4], $ids[6]], $linked());
        self::assertSame(1, $this->sweep()->linkUnlinkedCustomers(self::USER, 2), 'fewer than the limit: done');
        self::assertSame(0, $this->sweep()->linkUnlinkedCustomers(self::USER, 2));

        foreach ([0, 2, 5] as $i) {
            self::assertSame('', self::customer($ids[$i])['company']);
        }
        self::assertSame('Five', self::customer($ids[7])['company']);
        self::assertSame(0, self::pending(self::USER));
    }

    /**
     * A customer the sweep links, or whose blank company it stores as '', has
     * changed: GET /ltv/customers/{id} answers its updated_at, and a reader
     * asking what changed since before the sweep has to find it. The link
     * left updated_at as it was.
     */
    public function testWhatTheSweepWritesReadsAsChangedSinceBeforeIt(): void
    {
        [$acme, $blank, $pristine, $detached] = self::customers(self::USER, 'u-', ['Acme', "\t", null, '']);
        self::customers(self::OTHER, 'o-', ['Acme']);
        self::assertSame([], self::changedSince(self::USER, 2), 'every customer was last written at 1');
        $before = time();

        self::assertSame(1, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));

        self::assertSame([$acme, $blank], self::changedSince(self::USER, $before));
        self::assertSame([], self::changedSince(self::OTHER, $before));

        $conn = new Connection(self::$db);
        $crm = new MysqlCustomerCrmRepository(
            $conn,
            new MysqlCustomerRepository($conn),
            new MysqlCustomerFieldRepository($conn)
        );
        foreach ([[$acme, 'Acme'], [$blank, '']] as [$id, $company]) {
            $detail = $crm->get(self::USER, $id);
            self::assertIsArray($detail, "customer $id");
            self::assertSame($company, $detail['company'], "customer $id");
            self::assertGreaterThanOrEqual($before, (int) $detail['updated_at'], "customer $id is a change");
        }
        foreach ([$pristine, $detached] as $id) {
            self::assertSame('1', (string) $crm->get(self::USER, $id)['updated_at'], "customer $id is not");
        }
    }

    public function testOnlyTheAccountsOwnUnmergedCustomersAreTaken(): void
    {
        [$otherBlank, $otherAcme] = self::customers(self::OTHER, 'o-', ["\t", 'Acme']);
        [$merged, $own] = self::customers(self::USER, 'u-', ['Gone Inc', 'Acme']);
        self::q("UPDATE 202_customers SET merged_into_customer_id = $own WHERE customer_id = $merged");

        self::assertSame(1, $this->sweep()->linkUnlinkedCustomers(self::USER, 2000));

        self::assertSame(self::companyId(self::USER, 'Acme'), self::customer($own)['company_id']);
        self::assertSame(['company' => 'Gone Inc', 'company_id' => null], self::customer($merged));
        self::assertSame(['company' => "\t", 'company_id' => null], self::customer($otherBlank));
        self::assertSame(['company' => 'Acme', 'company_id' => null], self::customer($otherAcme));
        self::assertSame(0, (int) self::$db->query('SELECT COUNT(*) FROM 202_companies WHERE user_id = ' . self::OTHER)
            ->fetch_row()[0]);
    }
}
