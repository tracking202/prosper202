<?php

declare(strict_types=1);

namespace Tests\Conversion;

use PHPUnit\Framework\TestCase;
use Prosper202\Click\LatestVisit;
use Prosper202\Conversion\Ledger\MysqlConversionLedger;
use Prosper202\Database\Connection;
use Tests\Support\FakeMysqliConnection;

/**
 * The ledger's click lock comes before its transaction's first plain read
 * (MysqlConversionLedger::lockClick()): asked for after one, it is refused
 * before anything is locked; a click the transaction locked first is read
 * again, after plain reads, without a second lock.
 */
final class LockClickFirstTest extends TestCase
{
    /** The click's lock, with or without its account. */
    private const LOCK = 'LIMIT 1 FOR UPDATE';

    private static function clickHandle(): FakeMysqliConnection
    {
        $write = new FakeMysqliConnection();
        $visit = [[
            'click_id' => 7, 'user_id' => 1, 'aff_campaign_id' => 11, 'click_payout' => '3.00000',
            'click_time' => 1700000000, 'click_lead' => 0,
        ]];
        $write->whenQueryContainsReturnRows('SELECT click_id FROM 202_clicks WHERE click_id = ?', [['click_id' => 7]]);
        $write->whenQueryContainsReturnRows(LatestVisit::of('?'), $visit);

        return $write;
    }

    public function testALockAskedForAfterAPlainReadIsRefusedBeforeAnythingIsLocked(): void
    {
        $write = self::clickHandle();
        $conn = new Connection($write);

        try {
            $conn->transaction(static function () use ($conn): void {
                $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs WHERE click_id = 7'));
                (new MysqlConversionLedger($conn))->lockClick(7);
            });
            self::fail('a lock after a plain read was taken');
        } catch (\LogicException $e) {
            self::assertStringContainsString(
                'Click 7\'s lock was asked for after its transaction read',
                $e->getMessage()
            );
        }
        self::assertSame([], $write->statementsContaining(self::LOCK));
    }

    public function testAClickLockedFirstIsReadAgainWithoutASecondLock(): void
    {
        $write = self::clickHandle();
        $conn = new Connection($write);
        $ledger = new MysqlConversionLedger($conn);

        $visits = $conn->transaction(static function () use ($conn, $ledger): array {
            $first = $ledger->lockClick(7, 1);
            $conn->fetchAll($conn->prepareWrite('SELECT conv_id FROM 202_conversion_logs WHERE click_id = 7'));

            return [$first, $ledger->lockClick(7, 1)];
        });

        self::assertSame(11, $visits[0]['aff_campaign_id'] ?? null);
        self::assertSame($visits[0], $visits[1]);
        self::assertCount(1, $write->statementsContaining(self::LOCK));
        self::assertCount(2, $write->statementsContaining(LatestVisit::of('?')));
    }

    public function testEachTransactionTakesItsOwnLock(): void
    {
        $write = self::clickHandle();
        $ledger = new MysqlConversionLedger(new Connection($write));

        $ledger->clickTransaction(7, null, static fn (?array $visit): ?array => $visit);
        $ledger->clickTransaction(7, null, static fn (?array $visit): ?array => $visit);

        self::assertCount(2, $write->statementsContaining(self::LOCK));
    }
}
