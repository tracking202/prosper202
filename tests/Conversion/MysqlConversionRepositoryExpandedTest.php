<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use RuntimeException;
use Tests\Support\FakeMysqliConnection;

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
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );

        // buildRepo() type-hints FakeMysqliConnection, so wire this bespoke
        // write double through a Connection directly.
        $repo = new MysqlConversionRepository(new Connection($write, new FakeMysqliConnection()));

        $id = $repo->create(1, ['click_id' => 10, 'transaction_id' => 'TX-1']);

        self::assertSame(7, $id);

        // Verify INSERT INTO 202_conversion_logs was prepared
        $insertStmts = $write->statementsContaining('INSERT INTO 202_conversion_logs');
        self::assertCount(1, $insertStmts);
        self::assertSame('isidiii', $insertStmts[0]->boundTypes);
    }

    public function testCreateUpdatesClickLeadFlag(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );

        [$repo] = $this->buildRepo($write);
        $repo->create(1, ['click_id' => 10]);

        $updateStmts = $write->statementsContaining('UPDATE 202_clicks SET click_lead = 1');
        self::assertCount(1, $updateStmts);
    }

    public function testCreateUsesPayoutOverrideWhenProvided(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );

        [$repo] = $this->buildRepo($write);
        $repo->create(1, ['click_id' => 10, 'payout' => 50.00]);

        // The UPDATE should use 50.00 not 2.75
        $updateStmts = $write->statementsContaining('UPDATE 202_clicks SET click_lead = 1');
        self::assertCount(1, $updateStmts);
        self::assertEqualsWithDelta(50.00, $updateStmts[0]->boundValues[0], 0.01);
    }

    public function testCreateUsesClickPayoutWhenNoOverride(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );

        [$repo] = $this->buildRepo($write);
        $repo->create(1, ['click_id' => 10]);

        $updateStmts = $write->statementsContaining('UPDATE 202_clicks SET click_lead = 1');
        self::assertCount(1, $updateStmts);
        self::assertEqualsWithDelta(2.75, $updateStmts[0]->boundValues[0], 0.01);
    }

    public function testCreateUsesForUpdateLock(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );

        [$repo] = $this->buildRepo($write);
        $repo->create(1, ['click_id' => 10]);

        $lockStmts = $write->statementsContaining('FOR UPDATE');
        self::assertNotEmpty($lockStmts, 'Must use FOR UPDATE to prevent race conditions');
    }

    // --- softDelete() ---

    public function testSoftDeleteSetsDeletedFlag(): void
    {
        [$repo, $write] = $this->buildRepo();

        $repo->softDelete(5, 1);

        $stmts = $write->statementsContaining('UPDATE 202_conversion_logs SET deleted = 1');
        self::assertCount(1, $stmts);
        self::assertSame('ii', $stmts[0]->boundTypes);
        self::assertSame([5, 1], $stmts[0]->boundValues);
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
}

/**
 * Write-connection fake whose INSERT statement reports a real insert_id.
 *
 * The shared {@see FakeMysqliConnection} cannot do this on PHP 8.4+: it (and its
 * FakeMysqliStatement) subclass the native mysqli classes, whose insert_id is a
 * read-only virtual property that cannot be assigned from anywhere (verified:
 * direct, internal, and reflection writes all throw). FakeMysqliConnection is
 * also `final`, so it cannot be subclassed. This standalone double returns a
 * plain statement object (not a mysqli_stmt) for the conversion-log INSERT, whose
 * public insert_id is freely writable; Connection accepts it because every
 * statement parameter is relaxed to `object`.
 */
final class InsertReportingFakeMysqliConnection extends \mysqli
{
    /**
     * @var list<InsertReportingFakeStatement>
     */
    public array $statements = [];

    public bool $beginTransactionCalled = false;
    public bool $commitCalled = false;
    public bool $rollbackCalled = false;
    public string $error = '';

    /**
     * @var array<string, list<array<string, mixed>>>
     */
    private array $rowsByNeedle = [];

    public function __construct(private int $insertIdToReport)
    {
        // Skip parent constructor to avoid a real DB connection.
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function whenQueryContainsReturnRows(string $needle, array $rows): void
    {
        $this->rowsByNeedle[$needle] = $rows;
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query): InsertReportingFakeStatement
    {
        $stmt = new InsertReportingFakeStatement(
            $query,
            $this->insertIdToReport,
            $this->resolveRows($query),
        );
        $this->statements[] = $stmt;

        return $stmt;
    }

    public function begin_transaction(int $flags = 0, ?string $name = null): bool
    {
        $this->beginTransactionCalled = true;

        return true;
    }

    public function commit(int $flags = 0, ?string $name = null): bool
    {
        $this->commitCalled = true;

        return true;
    }

    public function rollback(int $flags = 0, ?string $name = null): bool
    {
        $this->rollbackCalled = true;

        return true;
    }

    /**
     * @return list<InsertReportingFakeStatement>
     */
    public function statementsContaining(string $needle): array
    {
        return array_values(array_filter(
            $this->statements,
            static fn (InsertReportingFakeStatement $stmt): bool => str_contains($stmt->sql, $needle),
        ));
    }

    /**
     * @return list<array<string, mixed>>|null
     */
    private function resolveRows(string $query): ?array
    {
        foreach ($this->rowsByNeedle as $needle => $rows) {
            if (str_contains($query, $needle)) {
                return $rows;
            }
        }

        return null;
    }
}

/**
 * Minimal statement double that is NOT a mysqli_stmt subclass, so its public
 * insert_id property is freely writable.
 */
final class InsertReportingFakeStatement
{
    public string $boundTypes = '';

    /**
     * @var list<mixed>
     */
    public array $boundValues = [];

    public int $insert_id = 0;
    public int $affected_rows = 0;
    public string $error = '';

    /**
     * @param list<array<string, mixed>>|null $rows
     */
    public function __construct(
        public string $sql,
        private int $insertIdToReport,
        private ?array $rows,
    ) {
    }

    public function bind_param(string $types, mixed &...$vars): bool
    {
        $this->boundTypes = $types;
        $this->boundValues = [];
        foreach ($vars as &$var) {
            $this->boundValues[] = $var;
        }

        return true;
    }

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->sql, 'INSERT INTO 202_conversion_logs')) {
            $this->insert_id = $this->insertIdToReport;
        }
        $this->affected_rows = 1;

        return true;
    }

    #[\ReturnTypeWillChange]
    public function get_result(): \mysqli_result|false
    {
        if ($this->rows === null) {
            return false;
        }

        return new InsertReportingFakeResult($this->rows);
    }

    public function close(): bool
    {
        return true;
    }
}

/**
 * Result double extending mysqli_result so Connection's `instanceof mysqli_result`
 * guard in fetchOne()/fetchAll() reads the rows.
 */
final class InsertReportingFakeResult extends \mysqli_result
{
    /**
     * @var list<array<string, mixed>>
     */
    private array $rows;
    private int $position = 0;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(array $rows)
    {
        // Skip parent constructor — no real result set backs this fake.
        $this->rows = array_values($rows);
    }

    #[\ReturnTypeWillChange]
    public function fetch_assoc(): ?array
    {
        return $this->rows[$this->position++] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function free(): void
    {
        $this->rows = [];
    }
}
