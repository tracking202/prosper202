<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCompanyRepository;
use Prosper202\Ltv\MysqlCustomerCrmRepository;
use Prosper202\Ltv\MysqlCustomerFieldRepository;
use Prosper202\Ltv\MysqlCustomerRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * First-class company entities: name normalization, race-safe find-or-create,
 * rename/merge/delete invariants, the maintenance linking sweep, and the CRM
 * upsert integration (entity stamping + email-domain auto-attach).
 *
 * The fake statement cannot expose insert_id/affected_rows (readonly mysqli
 * internals), so these tests assert on the statements issued and on the
 * explicit failure paths, not on returned ids.
 */
final class CompanyTest extends TestCase
{
    public function testNormalizeNameCollapsesCaseAndWhitespace(): void
    {
        self::assertSame('acme corp', MysqlCompanyRepository::normalizeName('  Acme   Corp '));
        self::assertSame('acme corp', MysqlCompanyRepository::normalizeName("Acme\t\nCorp"));
        self::assertSame('', MysqlCompanyRepository::normalizeName('   '));
    }

    public function testNormalizeDomainValidatesAndCanonicalizes(): void
    {
        self::assertSame('example.com', MysqlCompanyRepository::normalizeDomain(' Example.COM '));
        self::assertSame('example.com', MysqlCompanyRepository::normalizeDomain('https://Example.com/path'));
        self::assertSame('sub.example.co.uk', MysqlCompanyRepository::normalizeDomain('sub.example.co.uk.'));

        foreach (['', 'no-dots', 'has space.com', 'javascript:alert(1)', '.com'] as $bad) {
            try {
                MysqlCompanyRepository::normalizeDomain($bad);
                self::fail('Expected rejection for ' . var_export($bad, true));
            } catch (\RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testDomainFromEmail(): void
    {
        self::assertSame('acme.com', MysqlCompanyRepository::domainFromEmail('Jane@Acme.com'));
        self::assertNull(MysqlCompanyRepository::domainFromEmail('not-an-email'));
        self::assertNull(MysqlCompanyRepository::domainFromEmail('trailing@'));
    }

    public function testResolveOrCreateUsesRaceSafeUpsertWithNormalizedName(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->resolveOrCreate(7, '  Acme   Corp ', 1700000000);

        $inserts = $write->statementsContaining('INSERT INTO 202_companies');
        self::assertCount(1, $inserts);
        self::assertSame('issii', $inserts[0]->boundTypes);
        self::assertContains('Acme Corp', $inserts[0]->boundValues, 'display name keeps its casing');
        self::assertContains('acme corp', $inserts[0]->boundValues, 'normalized name dedups variants');
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE company_id = LAST_INSERT_ID(company_id)', $inserts[0]->sql);

        $this->expectException(\RuntimeException::class);
        $repo->resolveOrCreate(7, '   ');
    }

    public function testRenameRejectsCollisionWithAnotherCompany(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme', 'normalized_name' => 'acme', 'domain' => null],
        ]);
        $write->whenQueryContainsReturnRows('normalized_name = ? AND company_id <> ?', [
            ['company_id' => 9],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('merge the two companies');
        $repo->rename(7, 2, 'Initech');
    }

    public function testRenameRewritesEntityAndAttachedCustomers(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme', 'normalized_name' => 'acme', 'domain' => null],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->rename(7, 2, '  Initech   Global ');

        $companyUpdates = $write->statementsContaining('UPDATE 202_companies SET name');
        self::assertCount(1, $companyUpdates);
        self::assertSame('ssiii', $companyUpdates[0]->boundTypes);
        self::assertContains('Initech Global', $companyUpdates[0]->boundValues);
        self::assertContains('initech global', $companyUpdates[0]->boundValues);

        $customerUpdates = $write->statementsContaining('UPDATE 202_customers SET company =');
        self::assertCount(1, $customerUpdates, 'legacy company strings must follow the rename');
        self::assertSame('siii', $customerUpdates[0]->boundTypes);
        self::assertContains('Initech Global', $customerUpdates[0]->boundValues);
    }

    public function testMergeRepointsCustomersAndDeletesSource(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme Corp', 'normalized_name' => 'acme corp', 'domain' => null],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->merge(7, 5, 2);

        $repoints = $write->statementsContaining('UPDATE 202_customers SET company_id = ?, company = ?');
        self::assertCount(1, $repoints);
        self::assertSame('isiii', $repoints[0]->boundTypes);
        self::assertContains(2, $repoints[0]->boundValues, 'target id');
        self::assertContains(5, $repoints[0]->boundValues, 'source id');
        self::assertContains('Acme Corp', $repoints[0]->boundValues, 'moved contacts adopt the target name');

        $deletes = $write->statementsContaining('DELETE FROM 202_companies');
        self::assertCount(1, $deletes);
        self::assertContains(5, $deletes[0]->boundValues);

        try {
            $repo->merge(7, 3, 3);
            self::fail('self-merge must be rejected');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }

    public function testDeleteBlockedWhileCustomersAttached(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme', 'normalized_name' => 'acme', 'domain' => null],
        ]);
        $write->whenQueryContainsReturnRows('SELECT COUNT(*) AS c FROM 202_customers WHERE company_id', [
            ['c' => 3],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('attached customer');
        $repo->delete(7, 2);
    }

    public function testLinkSweepAttachesPendingCustomersAndSkipsBlankNames(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('company_id IS NULL AND merged_into_customer_id IS NULL', [
            ['customer_id' => 11, 'company' => 'Acme'],
            ['customer_id' => 12, 'company' => '   '],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->linkUnlinkedCustomers(7, 100);

        self::assertCount(
            1,
            $write->statementsContaining('INSERT INTO 202_companies'),
            'only the customer with a real name resolves a company'
        );
        $stamps = $write->statementsContaining('UPDATE 202_customers SET company_id = ?');
        self::assertCount(1, $stamps);
        self::assertContains(11, $stamps[0]->boundValues);
        self::assertStringContainsString('company_id IS NULL', $stamps[0]->sql, 'stamp must not overwrite an existing link');
    }

    public function testCrmUpsertResolvesCompanyEntityForCompanyName(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT customer_id FROM 202_customers WHERE customer_id = ?', [
            ['customer_id' => 501],
        ]);
        $write->whenQueryContainsReturnRows('SELECT merged_into_customer_id', [
            ['merged_into_customer_id' => null],
        ]);
        $conn = new Connection($write, new FakeMysqliConnection());
        $crm = new MysqlCustomerCrmRepository($conn, new MysqlCustomerRepository($conn), new MysqlCustomerFieldRepository($conn));

        $crm->upsert(7, ['customer_id' => 501, 'company' => 'Acme Corp']);

        self::assertCount(
            1,
            $write->statementsContaining('INSERT INTO 202_companies'),
            'a CRM save with a company name must resolve/create the entity'
        );
    }

    public function testCrmUpsertAutoAttachesByEmailDomain(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT customer_id FROM 202_customers WHERE customer_id = ?', [
            ['customer_id' => 501],
        ]);
        $write->whenQueryContainsReturnRows('SELECT merged_into_customer_id', [
            ['merged_into_customer_id' => null],
        ]);
        $write->whenQueryContainsReturnRows('WHERE user_id = ? AND domain = ?', [
            ['company_id' => 4, 'name' => 'Acme Corp', 'domain' => 'acme.com'],
        ]);
        $conn = new Connection($write, new FakeMysqliConnection());
        $crm = new MysqlCustomerCrmRepository($conn, new MysqlCustomerRepository($conn), new MysqlCustomerFieldRepository($conn));

        $crm->upsert(7, ['customer_id' => 501, 'email' => 'jane@acme.com']);

        $attaches = $write->statementsContaining("company_id IS NULL\n               AND (company IS NULL OR company = '')");
        self::assertCount(1, $attaches, 'email domain must auto-attach an unattached customer');
        self::assertSame('isiii', $attaches[0]->boundTypes);
        self::assertContains(4, $attaches[0]->boundValues);
        self::assertContains('Acme Corp', $attaches[0]->boundValues);
        self::assertContains(501, $attaches[0]->boundValues);
    }

    public function testCrmUpsertDoesNotAttachWhenNoDomainMatches(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT customer_id FROM 202_customers WHERE customer_id = ?', [
            ['customer_id' => 501],
        ]);
        $write->whenQueryContainsReturnRows('SELECT merged_into_customer_id', [
            ['merged_into_customer_id' => null],
        ]);
        $conn = new Connection($write, new FakeMysqliConnection());
        $crm = new MysqlCustomerCrmRepository($conn, new MysqlCustomerRepository($conn), new MysqlCustomerFieldRepository($conn));

        $crm->upsert(7, ['customer_id' => 501, 'email' => 'jane@unknown.org']);

        self::assertCount(0, $write->statementsContaining("company IS NULL OR company = ''"));
    }

    public function testDeleteAliasIsScopedAndRejectsUnknown(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlCustomerRepository(new Connection($write, new FakeMysqliConnection()));

        // The fake reports 0 affected rows — exactly the unknown/foreign
        // alias case — so the guard must throw, and the DELETE must be
        // scoped to (alias, customer, user).
        try {
            $repo->deleteAlias(7, 501, 33);
            self::fail('unknown alias must be rejected');
        } catch (\RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $deletes = $write->statementsContaining('DELETE FROM 202_customer_aliases');
        self::assertCount(1, $deletes);
        self::assertSame('iii', $deletes[0]->boundTypes);
        self::assertSame([33, 501, 7], $deletes[0]->boundValues);
    }
}
