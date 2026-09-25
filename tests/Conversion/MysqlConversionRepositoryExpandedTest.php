<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use RuntimeException;
use Tests\Support\FakeMysqliConnection;
use Tests\Support\InsertReportingFakeMysqliConnection;

/**
 * Expanded tests for MysqlConversionRepository.
 *
 * The original test only verified write connection usage and FOR UPDATE lock.
 * These tests cover:
 * - Transaction rollback on click not found
 * - Payout override vs click payout
 * - User ownership validation
 * - Soft delete behavior
 * - List filters and pagination
 */
final class MysqlConversionRepositoryExpandedTest extends TestCase
{
    private function buildRepo(?FakeMysqliConnection $write = null, ?FakeMysqliConnection $read = null): array
    {
        $write ??= new FakeMysqliConnection();
        $read ??= new FakeMysqliConnection();
        $conn = new Connection($write, $read);

        return [new MysqlConversionRepository($conn), $write, $read];
    }

    // --- create() ---

    public function testCreateThrowsForZeroClickId(): void
    {
        [$repo] = $this->buildRepo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('click_id is required');
        $repo->create(1, ['click_id' => 0]);
    }

    public function testCreateThrowsForNegativeClickId(): void
    {
        [$repo] = $this->buildRepo();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('click_id is required');
        $repo->create(1, ['click_id' => -1]);
    }

    public function testCreateThrowsWhenClickNotFound(): void
    {
        $write = new FakeMysqliConnection();
        // Return no rows for click lookup
        $write->whenQueryContainsReturnRows('FROM 202_clicks WHERE click_id = ?', []);

        [$repo] = $this->buildRepo($write);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Click not found or not owned by user');
        $repo->create(1, ['click_id' => 999]);
    }

    public function testCreateThrowsWhenClickOwnedByDifferentUser(): void
    {
        $write = new FakeMysqliConnection();
        // The query includes user_id in WHERE, so no rows returned for wrong user
        $write->whenQueryContainsReturnRows('FROM 202_clicks WHERE click_id = ?', []);

        [$repo] = $this->buildRepo($write);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Click not found or not owned by user');
        $repo->create(1, ['click_id' => 10]);
    }

    public function testCreateInsertsConversionLog(): void
    {
        // mysqli/mysqli_stmt expose insert_id as a read-only virtual property on
        // PHP 8.4+, so neither the shared FakeMysqliConnection nor its
        // FakeMysqliStatement (both subclass the native classes) can surface a
        // custom insert_id. Connection::executeInsert reads $stmt->insert_id, so
        // we use a write connection whose INSERT statement is a plain object with
        // a writable insert_id, while delegating every other prepare/transaction
        // call to the shared fake.
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );

        // buildRepo() type-hints FakeMysqliConnection, so wire this bespoke
        // write double through a Connection directly.
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $id = $repo->create(1, ['click_id' => 10, 'transaction_id' => 'TX-1']);

        self::assertSame(7, $id);

        // Verify INSERT INTO 202_conversion_logs was prepared
        $insertStmts = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insertStmts);
        // The NOT-NULL legacy columns (time_difference, ip, pixel_type, user_agent)
        // are always bound — even when the caller (e.g. the V3 API) omits them —
        // so the INSERT can't fail under STRICT sql_mode with "Field doesn't have a
        // default value"; then the six ledger columns. The amount is bound as the
        // exact decimal string (Amount), never a float.
        self::assertSame('isisiii' . 'ssis' . 'sssiis', $insertStmts[0]->boundTypes);
        $bound = $insertStmts[0]->boundValues;
        self::assertSame('2.75000', $bound[3], 'the click payout, exactly, when no amount is given (replace mode)');
        self::assertSame('', $bound[8], 'ip defaults to empty string');
        self::assertSame(0, $bound[9], 'pixel_type defaults to 0');
        self::assertSame('', $bound[10], 'user_agent defaults to empty string');
        self::assertSame(['api', null, null, 1, null, 'tx:TX-1'], array_slice($bound, 11),
            'source, source_ref, event_name, payable, reverses_conv_id, dedupe_key');
    }

    /**
     * The ip column is varchar(45). A forwarding chain, or anything that is
     * not one address, is bound as '' so the INSERT can never fail on it under
     * strict sql_mode and roll the conversion back.
     *
     * @dataProvider ipValuesTheWriterBounds
     */
    public function testRecordBindsOneValidAddressOrNothingAsTheIp(string $given, string $bound): void
    {
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $repo->record(1, ['click_id' => 10, 'transaction_id' => 'TX-1', 'ip' => $given]);

        $insertStmts = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insertStmts);
        self::assertSame($bound, $insertStmts[0]->boundValues[8]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ipValuesTheWriterBounds(): iterable
    {
        yield 'one IPv4' => ['198.51.100.7', '198.51.100.7'];
        yield 'one IPv6, trimmed' => [' 2001:db8::1 ', '2001:db8::1'];
        yield 'a forwarding chain longer than 45 chars' => [
            '2001:db8:85a3:8d3:1319:8a2e:370:7348, 2001:db8:85a3:8d3:1319:8a2e:370:7349',
            '',
        ];
        yield 'garbage' => [str_repeat('x', 60), ''];
        yield 'empty' => ['', ''];
    }

    public function testCreateUpdatesClickLeadFlag(): void
    {
        // An insert has to report its id (the writer refuses one that does
        // not), which only the insert-reporting double can do on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        $write->whenQueryContainsReturnRows(
            'FROM 202_conversion_logs WHERE click_id = ? ORDER BY conv_id',
            [['conv_id' => 7, 'click_payout' => '2.75000', 'payable' => 1, 'deleted' => 0, 'reverses_conv_id' => null,
                'source' => 'api', 'source_ref' => null, 'superseded_reason' => null, 'superseded_by' => null]]
        );

        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->create(1, ['click_id' => 10]);

        // The click's value is not written by the conversion: the ledger
        // recomputes it from the click's rows. With the inserted row visible
        // to the recompute, the click becomes a lead worth that row.
        $clickUpdates = $write->statementsContaining('UPDATE 202_clicks SET click_lead');
        self::assertCount(1, $clickUpdates);
        self::assertStringContainsString('click_lead = 1, click_payout = ?', $clickUpdates[0]->sql);
        self::assertSame(['2.75000', 10], $clickUpdates[0]->boundValues);
    }

    public function testCreateUsesPayoutOverrideWhenProvided(): void
    {
        // An insert has to report its id (the writer refuses one that does
        // not), which only the insert-reporting double can do on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );

        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->create(1, ['click_id' => 10, 'payout' => 50.00]);

        // The row records 50.00, not the click's 2.75.
        $insert = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insert);
        self::assertSame('50.00000', $insert[0]->boundValues[3]);
    }

    public function testCreateUsesClickPayoutWhenNoOverride(): void
    {
        // An insert has to report its id (the writer refuses one that does
        // not), which only the insert-reporting double can do on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );

        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->create(1, ['click_id' => 10]);

        $insert = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insert);
        self::assertSame('2.75000', $insert[0]->boundValues[3]);
    }

    public function testCreateUsesForUpdateLock(): void
    {
        // An insert has to report its id (the writer refuses one that does
        // not), which only the insert-reporting double can do on PHP 8.4.
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );

        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));
        $repo->create(1, ['click_id' => 10]);

        $lockStmts = $write->statementsContaining('FOR UPDATE');
        self::assertNotEmpty($lockStmts, 'Must use FOR UPDATE to prevent race conditions');
    }

    public function testCreateDeduplicatesOnTransactionId(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        // A conversion with this (click_id, transaction_id) already exists.
        // (The dedup lookup also returns the LTV customer link since the
        // customer_id column was added.)
        $write->whenQueryContainsReturnRows(
            'SELECT conv_id, customer_id FROM 202_conversion_logs',
            [['conv_id' => 99, 'customer_id' => null]]
        );

        [$repo] = $this->buildRepo($write);
        $id = $repo->create(1, ['click_id' => 10, 'transaction_id' => 'DUP']);

        self::assertSame(99, $id, 'A duplicate transaction id must return the existing conversion');
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_conversion_logs'), 'No second row may be inserted');
        self::assertCount(0, $write->statementsContaining('UPDATE 202_clicks SET click_lead'), 'The click must not be recomputed on a duplicate');
        $dup = $write->statementsContaining('AND dedupe_key = ?');
        self::assertNotEmpty($dup);
        self::assertSame([10, 'tx:DUP'], $dup[0]->boundValues, 'the replay is found by its ledger key');
    }

    // --- record() (shared writer used by the legacy static endpoints) ---

    public function testRecordWritesLegacyColumnsAndRunsClickSideCallbackInTransaction(): void
    {
        $write = new InsertReportingFakeMysqliConnection(5);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $callbackArgs = null;
        $result = $repo->record(
            1,
            [
                'click_id' => 10,
                'transaction_id' => '',
                'pixel_type' => 3,
                'ip' => '203.0.113.9',
                'user_agent' => 'UA/1.0',
                'time_difference' => '0 days',
            ],
            function (int $clickId, float $payout) use (&$callbackArgs): void {
                $callbackArgs = [$clickId, $payout];
            }
        );

        self::assertSame(5, $result['convId']);
        self::assertFalse($result['duplicate']);
        self::assertTrue($result['clickFound']);
        self::assertSame([10, 2.75], $callbackArgs, 'The click-side callback runs with the locked click id and payout');

        $insert = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insert);
        // Base 7 columns + the 4 legacy columns (time_difference, ip,
        // pixel_type, user_agent) + the 6 ledger columns = 17 bound params.
        self::assertSame(17, strlen($insert[0]->boundTypes));
        self::assertStringStartsWith('isisiii', $insert[0]->boundTypes);
        self::assertStringContainsString('pixel_type', $insert[0]->sql);
        // No transaction id, replace mode: the row's key is its own id,
        // written right after the insert.
        $keyed = $write->statementsContaining('UPDATE 202_conversion_logs SET dedupe_key = ?');
        self::assertCount(1, $keyed);
        self::assertSame(['row:5', 5], $keyed[0]->boundValues);
    }

    public function testRecordReturnsClickNotFoundWithoutThrowing(): void
    {
        $write = new FakeMysqliConnection(); // no click row registered → lock finds nothing

        [$repo] = $this->buildRepo($write);
        $result = $repo->record(1, ['click_id' => 999]);

        self::assertSame(0, $result['convId']);
        self::assertFalse($result['clickFound']);
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_conversion_logs'));
    }

    // --- softDelete() ---

    public function testSoftDeleteSetsDeletedFlag(): void
    {
        [$repo, $write] = $this->buildRepo();
        // softDelete finds the click, locks it, then locks the conversion
        // (and voids its LTV ledger event when one exists — none here).
        $write->whenQueryContainsReturnRows(
            'SELECT click_id FROM 202_conversion_logs WHERE conv_id = ?',
            [['click_id' => 10]]
        );
        $write->whenQueryContainsReturnRows(
            'SELECT conv_id, customer_id, deleted, reverses_conv_id FROM 202_conversion_logs',
            [['conv_id' => 5, 'customer_id' => null, 'deleted' => 0, 'reverses_conv_id' => null]]
        );

        $repo->softDelete(5, 1);

        $stmts = $write->statementsContaining('UPDATE 202_conversion_logs SET deleted = 1');
        self::assertCount(1, $stmts);
        self::assertSame('ii', $stmts[0]->boundTypes);
        self::assertSame([5, 1], $stmts[0]->boundValues);
        self::assertCount(0, $write->statementsContaining('INSERT INTO 202_revenue_events'), 'unlinked conversion has no ledger event to void');
    }

    // --- findById() ---

    public function testFindByIdUsesReadConnection(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows(
            'FROM 202_conversion_logs',
            [['conv_id' => 1, 'click_id' => 10, 'transaction_id' => 'TX-1']]
        );

        [$repo] = $this->buildRepo($write, $read);
        $result = $repo->findById(1, 1);

        self::assertNotNull($result);
        self::assertCount(0, $write->statements, 'findById should use read connection');
        self::assertNotEmpty($read->statements, 'findById should use read connection');
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        [$repo] = $this->buildRepo();
        $result = $repo->findById(999, 1);

        self::assertNull($result);
    }

    // --- list() ---

    public function testListUsesReadConnection(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $read->whenQueryContainsReturnRows('COUNT(*)', [['total' => 0]]);

        [$repo] = $this->buildRepo($write, $read);
        $result = $repo->list(1, [], 0, 10);

        self::assertSame(0, $result['total']);
        self::assertCount(0, $write->statements);
    }

    // --- once_per_click: the id-less gate, enforced under the click lock ---

    public function testOncePerClickRefusesAClickThatIsAlreadyALead(): void
    {
        // The row comes back from the SELECT ... FOR UPDATE with click_lead = 1:
        // another id-less request converted this click first. The writer
        // answers "duplicate" and prepares no INSERT, whatever the caller read
        // before it took the lock.
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 1]]
        );
        [$repo] = $this->buildRepo($write);

        $result = $repo->record(1, ['click_id' => 10, 'once_per_click' => true]);

        self::assertTrue($result['duplicate']);
        self::assertTrue($result['clickFound']);
        self::assertSame(0, $result['convId']);
        self::assertSame([], $write->statementsContaining('INSERT INTO 202_conversion_logs'));
        self::assertTrue($write->rollbackCalled || $write->commitCalled, 'the transaction is closed either way');
    }

    public function testOncePerClickRecordsWhileTheClickIsNotYetALead(): void
    {
        $write = new InsertReportingFakeMysqliConnection(7);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 0]]
        );
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $result = $repo->record(1, ['click_id' => 10, 'once_per_click' => true]);

        self::assertFalse($result['duplicate']);
        self::assertSame(7, $result['convId']);
        self::assertCount(1, $write->statementsContaining('INSERT INTO 202_conversion_logs'));
    }

    public function testWithoutOncePerClickALeadClickStillRecords(): void
    {
        // The gate is opt-in: a conversion carrying a transaction id (a repeat
        // purchase on the same click) records exactly as before.
        $write = new InsertReportingFakeMysqliConnection(8);
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000, 'click_lead' => 1]]
        );
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $result = $repo->record(1, ['click_id' => 10, 'transaction_id' => 'TX-2']);

        self::assertFalse($result['duplicate']);
        self::assertSame(8, $result['convId']);
    }

}
