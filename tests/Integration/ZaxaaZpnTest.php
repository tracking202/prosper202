<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Prosper202\Integration\ZaxaaZpn;

final class ZaxaaZpnTest extends TestCase
{
    private const SIGNATURE = 'zaxaa-api-signature';

    /** @return array{prod_number: string, custom: string, amount?: string} */
    private static function product(string $number, ?string $amount, string $custom = '1001'): array
    {
        return ['prod_number' => $number, 'custom' => $custom] + ($amount !== null ? ['amount' => $amount] : []);
    }

    /**
     * @param list<array<string, string>>|null $products
     * @return array<string, mixed>
     */
    private static function sale(?array $products = null, string $total = '47.00'): array
    {
        $products ??= [self::product('SKU1', '47.00')];
        $post = [
            'trans_type' => 'SALE',
            'trans_testmode' => '0',
            'seller_id' => 'seller42',
            'trans_receipt' => 'ZX-123',
            'trans_amount' => $total,
            'trans_currency' => 'USD',
            'products' => $products,
        ];
        // The construction three independent ZPN clients agree on: Prosper202's
        // former handler, aMember's plugin and remix-api.
        $post['hash_key'] = strtoupper(md5('seller42' . self::SIGNATURE . 'ZX-123' . $total));

        return $post;
    }

    public function testASignedNotificationVerifies(): void
    {
        self::assertTrue(ZaxaaZpn::verify(self::sale(), self::SIGNATURE));
        $lower = self::sale();
        $lower['hash_key'] = strtolower($lower['hash_key']);
        self::assertTrue(ZaxaaZpn::verify($lower, self::SIGNATURE));
    }

    public function testWhatTheHashCoversCannotChange(): void
    {
        $changes = ['seller_id' => 'seller43', 'trans_receipt' => 'ZX-124', 'trans_amount' => '470.00'];
        foreach ($changes as $field => $value) {
            $post = self::sale();
            $post[$field] = $value;
            self::assertFalse(ZaxaaZpn::verify($post, self::SIGNATURE), "$field changed after signing");
        }
        self::assertFalse(ZaxaaZpn::verify(self::sale(), 'another'));
        self::assertFalse(ZaxaaZpn::verify(self::sale(), ''));
    }

    public function testEachProductIsItsOwnTransaction(): void
    {
        $post = self::sale([
            ['prod_number' => 'SKU1', 'amount' => '47.00', 'custom' => '1001'],
            ['prod_number' => 'OTO2', 'amount' => '20.00', 'custom' => ' 1001 '],
            ['prod_number' => '', 'amount' => '10.00', 'custom' => ''],
        ], '77.00');
        self::assertSame([
            ['custom' => '1001', 'transaction_id' => 'ZX-123:SKU1', 'amount' => '47.00'],
            ['custom' => '1001', 'transaction_id' => 'ZX-123:OTO2', 'amount' => '20.00'],
            ['custom' => '', 'transaction_id' => 'ZX-123:2', 'amount' => '10.00'],
        ], ZaxaaZpn::products($post, true));
    }

    public function testALoneProductWithoutAnAmountTakesTheSignedTotal(): void
    {
        $products = ZaxaaZpn::products(self::sale([['prod_number' => 'SKU1', 'custom' => '1001']], '47.00'), true);
        self::assertSame('47.00', $products[0]['amount']);
    }

    /** @dataProvider malformed */
    public function testAMalformedNotificationIsRefusedByName(array $products, string $total, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        ZaxaaZpn::products(self::sale($products, $total), true);
    }

    /** @return iterable<string, array{array, string, string}> */
    public static function malformed(): iterable
    {
        yield 'amounts above the signed total' => [
            [self::product('A', '47.00'), self::product('B', '40.00')],
            '47.00',
            'add up to more than trans_amount',
        ];
        yield 'a product without an amount among several' => [
            [['prod_number' => 'A', 'amount' => '47.00', 'custom' => '1'], ['prod_number' => 'B', 'custom' => '1']],
            '47.00',
            'products[1] has no amount',
        ];
        yield 'an amount that is not one' => [[self::product('A', 'free')], '47.00', 'products[0][amount]'];
        yield 'a total that is not one' => [[['prod_number' => 'A', 'custom' => '1']], '47,00', 'trans_amount'];
        yield 'no products' => [[], '47.00', 'products is missing'];
    }

    public function testARefundNeedsNoAmounts(): void
    {
        $post = self::sale([self::product('SKU1', null), self::product('OTO2', null)], 'n/a');
        $post['trans_type'] = 'REFUND';
        $products = ZaxaaZpn::products($post, false);
        self::assertSame(['ZX-123:SKU1', 'ZX-123:OTO2'], array_column($products, 'transaction_id'));
    }
}
