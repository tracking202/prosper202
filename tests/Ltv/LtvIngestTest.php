<?php

declare(strict_types=1);

namespace Tests\Ltv;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Prosper202\Ltv\MysqlCustomerRepository;
use Tests\Support\FakeMysqliConnection;

/**
 * LTV ingest behavior through the canonical conversion writer: customer
 * resolution, ledger event, rollup bump, subid caching, replay handling and
 * soft-delete compensation. Uses the shared FakeMysqliConnection double — no
 * real database.
 *
 * Note: PHP 8.4 makes mysqli_stmt::$insert_id readonly, so the fake cannot
 * hand back insert ids; these tests therefore resolve customers through
 * pre-existing aliases (the SELECT path) rather than the create path. The
 * create/race paths are covered by the integration verification steps in the
 * feature plan.
 */
final class LtvIngestTest extends TestCase
{
    private function clickRow(): array
    {
        return [
            'click_id' => 10,
            'aff_campaign_id' => 44,
            'click_payout' => 2.75,
            'click_time' => 1700000000,
        ];
    }

    private function fakeWithClick(): FakeMysqliConnection
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [$this->clickRow()]
        );

        return $write;
    }

    public function testConversionWithKnownCustomerRefWritesLedgerAndStampsEverything(): void
    {
        $write = $this->fakeWithClick();
        // The external ref already maps to customer 501 (terminal record).
        $write->whenQueryContainsReturnRows(
            'SELECT customer_id FROM 202_customer_aliases',
            [['customer_id' => 501]]
        );
        $write->whenQueryContainsReturnRows(
            'SELECT merged_into_customer_id FROM 202_customers',
            [['merged_into_customer_id' => null]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $result = $repo->record(7, [
            'click_id' => 10,
            'transaction_id' => 'order-1',
            'customer_ref' => 'cust-abc',
            'customer_ref_type' => 'merchant_id',
        ]);

        self::assertFalse($result['duplicate']);
        self::assertSame(501, $result['customerId']);

        // The conversion row carries the resolved customer.
        $convInserts = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $convInserts);
        self::assertStringContainsString('customer_id', $convInserts[0]->sql);
        self::assertContains(501, $convInserts[0]->boundValues);

        // Exactly one ledger purchase event, sourced from the conversion.
        $ledgerInserts = $write->statementsContaining('INSERT INTO 202_revenue_events');
        self::assertCount(1, $ledgerInserts);
        self::assertContains('purchase', $ledgerInserts[0]->boundValues);
        self::assertContains('conversion', $ledgerInserts[0]->boundValues);
        self::assertContains(2.75, $ledgerInserts[0]->boundValues);

        // Rollup cache bumped and the click stamped for future subid hits.
        self::assertCount(1, $write->statementsContaining('UPDATE 202_customers SET'));
        self::assertCount(1, $write->statementsContaining('INSERT INTO 202_clicks_tracking'));

        // Everything ran on the write connection inside the transaction.
        self::assertTrue($write->beginTransactionCalled);
        self::assertTrue($write->commitCalled);
    }

    public function testCachedClickCustomerResolvesWithoutIdentityParams(): void
    {
        $write = $this->fakeWithClick();
        // A prior conversion cached the customer on the click's tracking row.
        $write->whenQueryContainsReturnRows(
            'SELECT customer_id FROM 202_clicks_tracking',
            [['customer_id' => 501]]
        );
        $write->whenQueryContainsReturnRows(
            'SELECT merged_into_customer_id FROM 202_customers',
            [['merged_into_customer_id' => null]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $result = $repo->record(7, ['click_id' => 10, 'transaction_id' => 'order-2']);

        self::assertSame(501, $result['customerId'], 'subid fast-path must resolve the cached customer');
        self::assertCount(1, $write->statementsContaining('INSERT INTO 202_revenue_events'));
    }

    public function testMergedCustomerResolvesToTerminalRecord(): void
    {
        $write = $this->fakeWithClick();
        $write->whenQueryContainsReturnRows(
            'SELECT customer_id FROM 202_customer_aliases',
            [['customer_id' => 501]]
        );
        // 501 was merged into 777. (The fake returns this row for every
        // pointer lookup; resolution saturates at 777 within the bounded hop
        // count, which is exactly the terminal-record guarantee.)
        $write->whenQueryContainsReturnRows(
            'SELECT merged_into_customer_id FROM 202_customers',
            [['merged_into_customer_id' => 777]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlCustomerRepository($conn);

        $resolved = $repo->resolveForConversion(7, 10, 'cust-abc', 'merchant_id', [], 1700000100);
        self::assertSame(777, $resolved);
    }

    public function testDuplicateTransactionWritesNoLedgerEvent(): void
    {
        $write = $this->fakeWithClick();
        $write->whenQueryContainsReturnRows(
            'SELECT conv_id, customer_id FROM 202_conversion_logs WHERE click_id = ? AND transaction_id = ?',
            [['conv_id' => 9001, 'customer_id' => 501]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $result = $repo->record(7, [
            'click_id' => 10,
            'transaction_id' => 'order-1',
            'customer_ref' => 'cust-abc',
        ]);

        self::assertTrue($result['duplicate']);
        self::assertSame(9001, $result['convId']);
        self::assertSame(501, $result['customerId']);
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_revenue_events'));
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_conversion_logs'));
    }

    public function testConversionWithoutIdentitySignalRecordsUnlinked(): void
    {
        $write = $this->fakeWithClick();

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $result = $repo->record(7, ['click_id' => 10, 'transaction_id' => 'order-2']);

        self::assertNull($result['customerId']);
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_revenue_events'));
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_customers'));

        $convInserts = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $convInserts);
        self::assertStringNotContainsString('customer_id', $convInserts[0]->sql);
    }

    public function testExplicitCustomerIdFromAnotherAccountIsRejected(): void
    {
        $write = $this->fakeWithClick();
        // Ownership lookup finds nothing for this (customer, user) pair.
        $write->whenQueryContainsReturnRows(
            'SELECT customer_id FROM 202_customers WHERE customer_id = ? AND user_id = ?',
            []
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found for this account');
        $repo->record(7, ['click_id' => 10, 'customer_id' => 999]);
    }

    public function testAddAliasReportsExistingOwnerInsteadOfRepointing(): void
    {
        $write = new FakeMysqliConnection();
        // The alias already belongs to customer 555 (first-writer-wins).
        $write->whenQueryContainsReturnRows(
            'SELECT customer_id FROM 202_customer_aliases',
            [['customer_id' => 555]]
        );
        $write->whenQueryContainsReturnRows(
            'SELECT merged_into_customer_id FROM 202_customers',
            [['merged_into_customer_id' => null]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlCustomerRepository($conn);

        $owner = $repo->addAlias(7, 501, 'email_md5', 'abc123', 1700000100);
        self::assertSame(555, $owner, 'existing alias binding must win; callers surface the conflict');
    }

    public function testSoftDeleteVoidsLedgerAndReversesRollups(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'SELECT conv_id, customer_id, deleted FROM 202_conversion_logs',
            [['conv_id' => 9001, 'customer_id' => 501, 'deleted' => 0]]
        );
        $write->whenQueryContainsReturnRows(
            'FROM 202_revenue_events WHERE conv_id = ?',
            [['event_id' => 7001, 'amount' => 2.75, 'currency' => 'USD', 'event_type' => 'purchase', 'occurred_at' => 1700000050]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $repo->softDelete(9001, 7);

        $voids = $write->statementsContaining('INSERT INTO 202_revenue_events');
        self::assertCount(1, $voids);
        self::assertContains('adjustment', $voids[0]->boundValues);
        self::assertContains('void:conv:9001', $voids[0]->boundValues);
        self::assertContains(-2.75, $voids[0]->boundValues);

        // Rollups reversed: one fewer order, revenue backed out.
        $rollups = $write->statementsContaining('UPDATE 202_customers SET');
        self::assertCount(1, $rollups);
        self::assertContains(-1, $rollups[0]->boundValues);
        self::assertContains(-2.75, $rollups[0]->boundValues);
    }

    public function testSoftDeleteOfAlreadyDeletedConversionIsANoOp(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'SELECT conv_id, customer_id, deleted FROM 202_conversion_logs',
            [['conv_id' => 9001, 'customer_id' => 501, 'deleted' => 1]]
        );

        $conn = new Connection($write, new FakeMysqliConnection());
        $repo = new MysqlConversionRepository($conn);

        $repo->softDelete(9001, 7);

        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_revenue_events'));
        self::assertCount(0, $write->statementsContaining('UPDATE 202_conversion_logs SET deleted = 1'));
    }
}
