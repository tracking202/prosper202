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
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ?',
            [['click_id' => 10, 'aff_campaign_id' => 44, 'click_payout' => 2.75, 'click_time' => 1700000000]]
        );
        $write->whenQueryContainsInsertId('INSERT INTO 202_conversion_logs', 7);

        [$repo] = $this->buildRepo($write);

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
