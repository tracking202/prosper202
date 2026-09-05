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
        // The rule must resolve to the requested rotator for the ownership
        // pre-check to pass.
        $write->whenQueryContainsReturnRows(
            'SELECT rotator_id FROM 202_rotator_rules',
            [['rotator_id' => 8]]
        );
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $repo->updateRule(5, 8, ['rule_name' => 'Updated']);

        $updates = $write->statementsContaining('UPDATE 202_rotator_rules SET');
        self::assertCount(1, $updates);
        self::assertStringContainsString('WHERE id = ? AND rotator_id = ?', $updates[0]->sql);
        self::assertSame('sii', $updates[0]->boundTypes);
        self::assertSame(['Updated', 5, 8], $updates[0]->boundValues);
    }

    public function testUpdateRuleRejectsRuleBelongingToAnotherRotator(): void
    {
        $write = new FakeMysqliConnection();
        // Rule 5 actually belongs to rotator 99, not the requested 8.
        $write->whenQueryContainsReturnRows(
            'SELECT rotator_id FROM 202_rotator_rules',
            [['rotator_id' => 99]]
        );
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $this->expectException(\RuntimeException::class);

        try {
            $repo->updateRule(5, 8, ['criteria' => [['type' => 'country', 'statement' => 'is', 'value' => 'US']]]);
        } finally {
            // The victim's criteria must never be deleted.
            self::assertSame([], $write->statementsContaining('DELETE FROM 202_rotator_rules_criteria'));
        }
    }

    public function testDeleteRuleRejectsRuleBelongingToAnotherRotator(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'SELECT rotator_id FROM 202_rotator_rules',
            [['rotator_id' => 99]]
        );
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $this->expectException(\RuntimeException::class);

        try {
            $repo->deleteRule(5, 8);
        } finally {
            self::assertSame([], $write->statementsContaining('DELETE FROM 202_rotator_rules_criteria'));
            self::assertSame([], $write->statementsContaining('DELETE FROM 202_rotator_rules_redirects'));
        }
    }

    /**
     * Rotators are matched between installs by public_id, so `p202 sync` sends
     * the source's value. Rejecting it outright made the target assign its own,
     * so the source never matched the target: every run re-created every rotator
     * and remapping trackers' rotator_id failed with "unresolvable target foreign
     * key". A free public_id must therefore be honoured.
     */
    public function testCreateHonoursAFreeCallerSuppliedPublicId(): void
    {
        $write = new FakeMysqliConnection();
        // No row comes back for the freeness probe, so 4242424 is available.
        $write->whenQueryContainsReturnRows('SELECT id FROM 202_rotators WHERE public_id = ?', []);
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $repo->create(7, ['name' => 'Synced', 'public_id' => 4242424]);

        $inserts = $write->statementsContaining('INSERT INTO 202_rotators');
        self::assertCount(1, $inserts);
        self::assertSame(4242424, $inserts[0]->boundValues[0]);
    }

    /**
     * The hazard the server-side derivation guards against is collision: public_id
     * is resolved by the unauthenticated redirect with no user scoping and has no
     * UNIQUE key. A value already in use must never be accepted.
     */
    public function testCreateRejectsAnAlreadyTakenPublicIdAndGeneratesInstead(): void
    {
        $write = new FakeMysqliConnection();
        // Every freeness probe reports the candidate as taken, including the
        // caller's, so create() must fall through to a generated id.
        $write->whenQueryContainsReturnRows(
            'SELECT id FROM 202_rotators WHERE public_id = ?',
            [['id' => 1]]
        );
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $this->expectException(\RuntimeException::class);
        $repo->create(7, ['name' => 'Colliding', 'public_id' => 4242424]);
    }

    public function testCreateGeneratesAPublicIdWhenNoneSupplied(): void
    {
        $write = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows('SELECT id FROM 202_rotators WHERE public_id = ?', []);
        $conn = new Connection($write);
        $repo = new MysqlRotatorRepository($conn);

        $repo->create(7, ['name' => 'Fresh']);

        $inserts = $write->statementsContaining('INSERT INTO 202_rotators');
        self::assertCount(1, $inserts);
        $generated = $inserts[0]->boundValues[0];
        self::assertIsInt($generated);
        self::assertGreaterThanOrEqual(100000, $generated);
        self::assertLessThanOrEqual(9999999, $generated);
    }
}
