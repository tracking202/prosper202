<?php

declare(strict_types=1);

namespace Tests\Rotator;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\Rotator\MysqlRotatorRepository;
use Tests\Support\FakeMysqliConnection;

final class MysqlRotatorRepositoryTest extends TestCase
{
    public function testDeleteChecksOwnershipBeforeCascadeQueries(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'SELECT id FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [['id' => 99]]
        );
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $repo->delete(99, 7);

        self::assertTrue($write->beginTransactionCalled);
        self::assertTrue($write->commitCalled);
        self::assertFalse($write->rollbackCalled);
        self::assertNotEmpty($write->preparedSql);
        self::assertStringContainsString(
            'SELECT id FROM 202_rotators WHERE id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            $write->preparedSql[0]
        );

        $firstDeleteIndex = null;
        foreach ($write->preparedSql as $index => $sql) {
            if (str_contains($sql, 'DELETE FROM 202_rotator_rules_criteria')) {
                $firstDeleteIndex = $index;
                break;
            }
        }

        self::assertNotNull($firstDeleteIndex);
        self::assertGreaterThan(0, $firstDeleteIndex);
    }

    public function testUpdateRuleScopesUpdateToRotatorId(): void
    {
        $write = new FakeMysqliConnection();
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $repo->updateRule(5, 8, ['rule_name' => 'Updated']);

        $updates = $write->statementsContaining('UPDATE 202_rotator_rules SET');
        self::assertCount(1, $updates);
        self::assertStringContainsString('WHERE id = ? AND rotator_id = ?', $updates[0]->sql);
        self::assertSame('sii', $updates[0]->boundTypes);
        self::assertSame(['Updated', 5, 8], $updates[0]->boundValues);
    }
}
