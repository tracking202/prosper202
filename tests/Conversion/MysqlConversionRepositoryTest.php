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
        self::assertCount(0, $read->statements, 'create() should not use read connection.');

        $lookupStatements = $write->statementsContaining('FROM 202_clicks WHERE click_id = ? AND user_id = ?');
        self::assertCount(1, $lookupStatements);
        self::assertStringContainsString('FOR UPDATE', $lookupStatements[0]->sql);
        self::assertSame('ii', $lookupStatements[0]->boundTypes);
        self::assertSame([10, 7], $lookupStatements[0]->boundValues);
    }
}
