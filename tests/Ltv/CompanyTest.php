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
    public function testSearchEscapesLikeAndExcludesTheMergeTarget(): void
    {
        $read = new FakeMysqliConnection();
        $repo = new MysqlCompanyRepository(new Connection(new FakeMysqliConnection(), $read));

        $repo->search(7, '50%_off', 12, 8);

        $queries = $read->statementsContaining('co.name LIKE ?');
        self::assertCount(1, $queries);
        self::assertStringContainsString('co.company_id <> ?', $queries[0]->sql, 'the merge target itself is excluded');
        self::assertStringContainsString('merged_into_customer_id IS NULL', $queries[0]->sql, 'contact counts skip merged customers');
        self::assertSame('iissi', $queries[0]->boundTypes);
        self::assertSame([7, 12, '%50\%\_off%', '%50\%\_off%', 8], $queries[0]->boundValues, 'LIKE metacharacters escaped');
    }

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
        $repo->update(7, 2, ['name' => 'Initech']);
    }

    public function testRenameRewritesEntityAndAttachedCustomers(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme', 'normalized_name' => 'acme', 'domain' => null],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->update(7, 2, ['name' => '  Initech   Global ']);

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
        // In-transaction lock re-read: both rows still present.
        $write->whenQueryContainsReturnRows('ORDER BY company_id FOR UPDATE', [
            ['company_id' => 2, 'name' => 'Acme Corp', 'domain' => null],
            ['company_id' => 5, 'name' => 'Old Co', 'domain' => null],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->merge(7, 5, 2);

        $locks = $write->statementsContaining('ORDER BY company_id FOR UPDATE');
        self::assertCount(1, $locks, 'concurrent merges must serialize on locked company rows');
        self::assertSame([2, 5, 7], $locks[0]->boundValues, 'ids locked in ascending order, then user scope');

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

        $inserts = $write->statementsContaining('INSERT INTO 202_companies');
        self::assertCount(1, $inserts, 'only the customer with a real name resolves a company');
        self::assertContains('Acme', $inserts[0]->boundValues);

        // The stamp UPDATE only runs once resolveOrCreate yields a real id;
        // the fake cannot expose insert_id (readonly mysqli internals), so
        // here the guard correctly suppresses a company_id=0 stamp.
        self::assertCount(0, $write->statementsContaining('UPDATE 202_customers SET company_id = ?'));
    }

    public function testLinkSweepDoesNotSwallowDatabaseFailures(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('company_id IS NULL AND merged_into_customer_id IS NULL', [
            ['customer_id' => 11, 'company' => 'Acme'],
        ]);
        // A real DB failure (missing table, broken insert) must propagate to
        // the caller instead of being silently skipped as a "blank name".
        $write->whenQueryContainsExecuteReturns('INSERT INTO 202_companies', false);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $this->expectException(\RuntimeException::class);
        $repo->linkUnlinkedCustomers(7, 100);
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

        $attaches = $write->statementsContaining('company_id IS NULL
               AND company IS NULL');
        self::assertCount(1, $attaches, 'email domain must auto-attach an unattached customer');
        self::assertSame('isiii', $attaches[0]->boundTypes);
        self::assertContains(4, $attaches[0]->boundValues);
        self::assertContains('Acme Corp', $attaches[0]->boundValues);
        self::assertContains(501, $attaches[0]->boundValues);
        self::assertStringContainsString(
            'AND company IS NULL',
            $attaches[0]->sql,
            'strictly-NULL guard: an explicitly detached customer (company = \'\') must never re-attach'
        );
    }

    public function testCrmUpsertDetachLeavesMarkerBlockingReattach(): void
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

        $crm->upsert(7, ['customer_id' => 501, 'company' => '']);

        // Only a pristine row (no company_id AND no string) keeps NULL and
        // stays auto-attach eligible; attached rows, unstamped ingest
        // strings, and existing markers all become/stay '' — the operator's
        // clear must never be reversed by the sweep or auto-attach.
        $detaches = $write->statementsContaining("SET company = IF(company_id IS NULL AND company IS NULL, NULL, '')");
        self::assertCount(1, $detaches, 'detach must be marker-guarded, not unconditional');
        self::assertContains(501, $detaches[0]->boundValues);
        self::assertStringContainsString('company_id = NULL', $detaches[0]->sql);

        // applyCrmFields must not touch the company column — it would
        // clobber the marker on every save carrying an empty company field.
        foreach ($write->statementsContaining('UPDATE 202_customers SET') as $update) {
            if (str_contains($update->sql, 'first_name')) {
                self::assertStringNotContainsString('company = ?', $update->sql);
            }
        }
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

    public function testStrictCreateIsSingleInsertAndConflictsAreTyped(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->create(7, 'Acme Corp', 'ACME.com');

        $inserts = $write->statementsContaining('INSERT INTO 202_companies');
        self::assertCount(1, $inserts, 'strict create is one plain INSERT — the unique key arbitrates races');
        self::assertStringNotContainsString('ON DUPLICATE KEY', $inserts[0]->sql, 'ODKU would silently adopt a concurrent row');
        self::assertSame('isssii', $inserts[0]->boundTypes);
        self::assertContains('acme.com', $inserts[0]->boundValues, 'domain rides the same INSERT — no second statement');
        self::assertCount(0, $write->statementsContaining('UPDATE 202_companies SET domain'));

        // Existing name → typed conflict, no INSERT attempted.
        $write2 = new FakeMysqliConnection();
        $write2->whenQueryContainsReturnRows('normalized_name = ? LIMIT 1', [
            ['company_id' => 9, 'name' => 'Acme Corp', 'normalized_name' => 'acme corp', 'domain' => null],
        ]);
        $repo2 = new MysqlCompanyRepository(new Connection($write2, new FakeMysqliConnection()));
        try {
            $repo2->create(7, 'ACME  Corp');
            self::fail('existing name must conflict');
        } catch (\Prosper202\Ltv\CompanyConflictException) {
            $this->addToAssertionCount(1);
        }
        self::assertCount(0, $write2->statementsContaining('INSERT INTO 202_companies'));
    }

    public function testAttachCustomerStampsEntityNameNotCallerString(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('normalized_name = ? LIMIT 1', [
            ['company_id' => 4, 'name' => 'Acme Corp', 'normalized_name' => 'acme corp', 'domain' => null],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        // Caller string differs in case; the stamp must use the entity's
        // stored name so string-grouped reports converge on one bucket.
        $repo->attachCustomer(7, 501, 'ACME CORP', '', 1700000000);

        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_companies'), 'existing entity: no insert');
        $stamps = $write->statementsContaining('UPDATE 202_customers SET company_id = ?, company = ?');
        self::assertCount(1, $stamps);
        self::assertSame('isiii', $stamps[0]->boundTypes);
        self::assertContains('Acme Corp', $stamps[0]->boundValues, 'entity name, not the caller casing');
        self::assertContains(4, $stamps[0]->boundValues);
    }

    public function testReconcileAttachedStringsConvergesOntoEntityNames(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->reconcileAttachedStrings();

        $updates = $write->statementsContaining('JOIN 202_companies co ON co.company_id = c.company_id');
        self::assertCount(1, $updates);
        self::assertStringContainsString('SET c.company = co.name', $updates[0]->sql);
        self::assertStringContainsString('c.company IS NULL OR c.company <> co.name', $updates[0]->sql, 'must also repair NULL-string-with-id partial writes');
    }

    public function testDeleteStrayGuardMatchesUncanonicalLegacyStrings(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT company_id, name, normalized_name', [
            ['company_id' => 2, 'name' => 'Acme Corp', 'normalized_name' => 'acme corp', 'domain' => null],
        ]);
        // Zero ATTACHED customers, but one unstamped legacy row whose string
        // is uncanonical (inner double space) — exact SQL equality would miss
        // it; the normalized PHP comparison must not.
        $write->whenQueryContainsReturnRows('SELECT COUNT(*) AS c FROM 202_customers WHERE company_id', [
            ['c' => 0],
        ]);
        $write->whenQueryContainsReturnRows('GROUP BY company', [
            ['company' => 'Acme  Corp', 'c' => 3],
            ['company' => 'Unrelated Inc', 'c' => 5],
        ]);
        $repo = new MysqlCompanyRepository(new Connection($write, new FakeMysqliConnection()));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('pending entity linking');
        $repo->delete(7, 2);
    }

    public function testDeleteAliasIsScopedAndRejectsUnknown(): void
    {
        $write = new FakeMysqliConnection();
        $repo = new MysqlCustomerRepository(new Connection($write, new FakeMysqliConnection()));

        // The fake reports 0 affected rows — exactly the unknown/foreign
        // alias case — so the guard must throw the TYPED not-found (the only
        // thing callers may map to 404; DB failures are plain
        // RuntimeException and must never read as "already deleted").
        try {
            $repo->deleteAlias(7, 501, 33);
            self::fail('unknown alias must be rejected');
        } catch (\Prosper202\Ltv\RecordNotFoundException) {
            $this->addToAssertionCount(1);
        }

        $deletes = $write->statementsContaining('DELETE FROM 202_customer_aliases');
        self::assertCount(1, $deletes);
        self::assertSame('iii', $deletes[0]->boundTypes);
        self::assertSame([33, 501, 7], $deletes[0]->boundValues);
    }
}
