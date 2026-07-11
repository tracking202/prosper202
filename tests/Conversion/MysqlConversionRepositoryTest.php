<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Conversion\MysqlConversionRepository;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

final class MysqlConversionRepositoryTest extends TestCase
{
    public function testCreateUsesWriteConnectionAndLocksClickRow(): void
    {
        $write = new FakeMysqliConnection();
        $read = new FakeMysqliConnection();
        $write->whenQueryContainsReturnRows(
            'FROM 202_clicks WHERE click_id = ? AND user_id = ? LIMIT 1 FOR UPDATE',
            [[
                'click_id' => 10,
                'aff_campaign_id' => 44,
                'click_payout' => 2.75,
                'click_time' => 1700000000,
            ]]
        );

        $conn = new Connection($write, $read);
        $repo = new MysqlConversionRepository($conn);

        $id = $repo->create(7, ['click_id' => 10, 'transaction_id' => 'tx-1']);
        self::assertIsInt($id);

        // The transactional write path must never touch the read connection —
        // it has to see the locked/uncommitted state. The ONLY reads allowed
        // are the post-commit bandit-bridge emit lookups (install context +
        // webhook subscriber match), which run after the transaction returns.
        foreach ($read->statements as $stmt) {
            self::assertMatchesRegularExpression(
                '/202_users|202_ltv_webhooks/', // users/users_pref (install ctx) + webhook match
                $stmt->sql,
                'transactional statement leaked onto the read connection: ' . $stmt->sql
            );
        }
        self::assertCount(0, $read->statementsContaining('202_clicks'), 'click lock/lookup must use the write connection');
        self::assertCount(0, $read->statementsContaining('202_conversion_logs'), 'dedupe/insert must use the write connection');

        $lookupStatements = $write->statementsContaining('FROM 202_clicks WHERE click_id = ? AND user_id = ?');
        self::assertCount(1, $lookupStatements);
        self::assertStringContainsString('FOR UPDATE', $lookupStatements[0]->sql);
        self::assertSame('ii', $lookupStatements[0]->boundTypes);
        self::assertSame([10, 7], $lookupStatements[0]->boundValues);
    }
}
