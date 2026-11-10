<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\Database\Connection;
use Prosper202\PaymentNotification\Shopify\RefundSettlementStore;
use RuntimeException;

/** @group integration */
final class ShopifyRefundSettlementStoreTest extends TestCase
{
    use \Tests\Report\ScratchReportDatabase;

    private const USER = 990599;

    public static function setUpBeforeClass(): void
    {
        self::connectScratch();
    }

    protected function setUp(): void
    {
        self::requireScratch();
        self::q('DELETE FROM 202_shopify_pending_refunds WHERE user_id = ' . self::USER);
        self::q('DELETE FROM 202_shopify_refund_transactions WHERE user_id = ' . self::USER);
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$db !== null) {
            self::q('DELETE FROM 202_shopify_pending_refunds WHERE user_id = ' . self::USER);
            self::q('DELETE FROM 202_shopify_refund_transactions WHERE user_id = ' . self::USER);
            self::$db->close();
            self::$db = null;
        }
    }

    public function testUpdateBeforeRefundAndDuplicateDeliveryAreSafe(): void
    {
        $store = new RefundSettlementStore(new Connection(self::requireScratch()));
        $store->update(self::USER, '10', '20', 'success', '5.00', 'USD', 100);
        $store->update(self::USER, '10', '20', 'success', '5.00', 'USD', 101);
        $tx = [['id' => '20', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD']];
        $store->register(self::USER, '30', '10', 99, $tx);
        $store->register(self::USER, '30', '10', 99, $tx);
        self::assertSame([['refund_id' => '30', 'order_id' => '10', 'occurred_at' => 99, 'refused' => null,
            'pending' => false, 'amount' => '5.00000', 'currency' => 'USD']], $store->forOrder(self::USER, '10'));
        $store->complete(self::USER, '30', 102);
        self::assertSame([], $store->forOrder(self::USER, '10'));
    }

    public function testConflictingRepeatedTransactionIsRefused(): void
    {
        $store = new RefundSettlementStore(new Connection(self::requireScratch()));
        $store->update(self::USER, '10', '20', 'success', '5.00', 'USD', 100);
        $this->expectException(RuntimeException::class);
        $store->update(self::USER, '10', '20', 'success', '6.00', 'USD', 101);
    }

    public function testRefundBeforeUpdateWaitsForEveryTransaction(): void
    {
        $store = new RefundSettlementStore(new Connection(self::requireScratch()));
        $store->register(self::USER, '30', '10', 99, [
            ['id' => '20', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD'],
            ['id' => '21', 'status' => 'pending', 'amount' => '7.00', 'currency' => 'USD'],
        ]);
        $store->update(self::USER, '10', '20', 'success', '5.00', 'USD', 100);
        self::assertTrue($store->forOrder(self::USER, '10')[0]['pending']);
        $store->update(self::USER, '10', '21', 'failure', '7.00', 'USD', 101);
        $resolved = $store->forOrder(self::USER, '10')[0];
        self::assertFalse($resolved['pending']);
        self::assertSame('5.00000', $resolved['amount']);
    }

    public function testARefundWhoseUpdateDisagreesIsRefusedAndTheOthersAreStillAnswered(): void
    {
        $store = new RefundSettlementStore(new Connection(self::requireScratch()));
        $store->register(self::USER, '30', '10', 99, [
            ['id' => '20', 'status' => 'pending', 'amount' => '5.00', 'currency' => 'USD'],
        ]);
        $store->register(self::USER, '31', '10', 99, [
            ['id' => '21', 'status' => 'pending', 'amount' => '7.00', 'currency' => 'USD'],
        ]);
        $store->update(self::USER, '10', '20', 'success', '4.00', 'USD', 100);
        $store->update(self::USER, '10', '21', 'success', '7.00', 'USD', 101);

        [$bad, $good] = $store->forOrder(self::USER, '10');

        self::assertSame('30', $bad['refund_id']);
        self::assertNotNull($bad['refused']);
        self::assertFalse($bad['pending']);
        self::assertSame('0.00000', $bad['amount']);
        self::assertNull($good['refused']);
        self::assertSame('7.00000', $good['amount']);
    }
}
