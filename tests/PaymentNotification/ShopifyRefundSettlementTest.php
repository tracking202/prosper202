<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\Shopify\RefundSettlement;

final class ShopifyRefundSettlementTest extends TestCase
{
    public function testUpdatesBeforeOrAfterTheRefundResolveToTheSameResult(): void
    {
        $transactions = [
            ['id' => '1', 'status' => 'success', 'amount' => '10.00', 'currency' => 'USD'],
            ['id' => '2', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD'],
        ];
        self::assertSame(['pending' => true, 'amount' => '10.00000', 'currency' => 'USD'],
            RefundSettlement::resolve($transactions, []));
        $update = ['2' => ['status' => 'success', 'amount' => '5.00', 'currency' => 'USD']];
        self::assertSame(['pending' => false, 'amount' => '15.00000', 'currency' => 'USD'],
            RefundSettlement::resolve($transactions, $update));
        $alreadySettled = $transactions;
        $alreadySettled[1]['status'] = 'success';
        self::assertSame(RefundSettlement::resolve($alreadySettled, []), RefundSettlement::resolve($transactions, $update));
    }

    public function testFailedTransactionIsNotRefunded(): void
    {
        $transactions = [['id' => '1', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD']];
        self::assertSame(['pending' => false, 'amount' => '0.00000', 'currency' => null],
            RefundSettlement::resolve($transactions, ['1' => ['status' => 'failure', 'amount' => '5.00', 'currency' => 'USD']]));
    }

    public function testChangedAmountIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RefundSettlement::resolve(
            [['id' => '1', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD']],
            ['1' => ['status' => 'success', 'amount' => '8.00', 'currency' => 'USD']]
        );
    }

    public function testConflictingTerminalUpdateIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RefundSettlement::resolve(
            [['id' => '1', 'status' => 'success', 'amount' => '5.00', 'currency' => 'USD']],
            ['1' => ['status' => 'failure', 'amount' => '5.00', 'currency' => 'USD']]
        );
    }

    public function testDuplicateTransactionIdsAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $transaction = ['id' => '1', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD'];
        RefundSettlement::resolve([$transaction, $transaction], []);
    }
}
