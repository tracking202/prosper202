<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Refusal;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaAdapter;
use Prosper202\PaymentNotification\Zaxaa\ZaxaaSignature;

/**
 * How ZaxaaAdapter verifies a ZPN and reads it. The database half is
 * ZaxaaZpnIntegrationTest's.
 */
final class ZaxaaAdapterTest extends TestCase
{
    private const SIG_A = 'zaxaa-api-signature-A';
    private const SIG_OTHER = 'zaxaa-api-signature-B';

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function signed(array $fields, string $signature): array
    {
        return ['hash_key' => ZaxaaSignature::sign(
            (string) ($fields['seller_id'] ?? ''),
            (string) ($fields['trans_receipt'] ?? ''),
            (string) ($fields['trans_amount'] ?? ''),
            $signature
        )] + $fields;
    }

    /**
     * A ZPN for a one-time sale on click 42, with the variables Zaxaa documents.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function sale(array $overrides = []): array
    {
        return $overrides + [
            'trans_type' => 'SALE', 'trans_gateway' => 'STRIPE', 'trans_date' => '2023-11-14 22:13:20 UTC',
            'trans_testmode' => '0', 'seller_id' => '12345', 'seller_email' => 'seller@example.com',
            'cust_email' => 'buyer@example.com', 'cust_firstname' => 'Joe', 'cust_lastname' => 'Buyer',
            'trans_amount' => '47.00', 'trans_currency' => 'USD', 'trans_receipt' => 'ZX-1001', 'item_count' => '1',
            'is_oto' => '0',
            'products' => [[
                'prod_number' => 'SKU1', 'prod_name' => 'Course', 'prod_type' => 'FRONTEND', 'amount' => '47.00',
                'payment_type' => 'onetime', 'custom' => '42',
            ]],
        ];
    }

    /** @param array<array-key, mixed> $fields */
    private static function request(array $fields): InboundRequest
    {
        return new InboundRequest('POST', 'application/x-www-form-urlencoded', $fields, '203.0.113.9', '203.0.113.9', 'Zaxaa');
    }

    /** @param array<string, mixed> $overrides */
    private static function parsed(array $overrides): Notification
    {
        return (new ZaxaaAdapter())->parse(self::request(self::sale($overrides)));
    }

    private static function refusal(callable $call): Refusal
    {
        try {
            $call();
        } catch (Refusal $refusal) {
            return $refusal;
        }
        self::fail('no refusal');
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<int, list<string>> $secrets
     * @return array<int, int>|null
     */
    private static function verified(array $fields, array $secrets): ?array
    {
        return (new ZaxaaAdapter())->verify(self::request($fields), $secrets)?->secretByAccount;
    }

    public function testTheHashIsZaxaasPublishedOne(): void
    {
        // zaxaa.com's PHP sample: strtoupper(md5($sellerID . $signature . $transReceipt . $transAmount)).
        self::assertSame(strtoupper(md5('12345' . 'sig' . 'R1' . '47.00')), ZaxaaSignature::sign('12345', 'R1', '47.00', 'sig'));
    }

    public function testTheAccountIsTheOneWhoseSignatureVerifies(): void
    {
        $secrets = [3 => [self::SIG_A], 7 => [self::SIG_OTHER]];

        self::assertSame([3 => 0], self::verified(self::signed(self::sale(), self::SIG_A), $secrets));
        self::assertSame([7 => 0], self::verified(self::signed(self::sale(), self::SIG_OTHER), $secrets));
        self::assertSame([3 => 0, 7 => 0], self::verified(self::signed(self::sale(), self::SIG_A), [3 => [self::SIG_A], 7 => [self::SIG_A]]));
    }

    public function testNothingVerifiesAChangedSignedValueAnotherSignatureOrALooseHash(): void
    {
        $secrets = [3 => [self::SIG_A]];
        $signed = self::signed(self::sale(), self::SIG_A);

        foreach (['trans_amount' => '470.00', 'trans_receipt' => 'ZX-1002', 'seller_id' => '12346'] as $name => $value) {
            self::assertNull(self::verified([$name => $value] + $signed, $secrets), $name);
        }
        self::assertNull(self::verified(self::signed(self::sale(), 'not-stored'), $secrets));
        self::assertNull(self::verified($signed, []));
        foreach ([strtolower($signed['hash_key']), ' ' . $signed['hash_key'], '0E1'] as $loose) {
            self::assertNull(self::verified(['hash_key' => $loose] + $signed, $secrets), $loose);
        }
    }

    public function testAnUnsignedOrEmptyRequestIsRefused(): void
    {
        $adapter = new ZaxaaAdapter();
        $unsigned = self::sale();
        self::assertSame(OutcomeKind::UNVERIFIED, self::refusal(static fn () => $adapter->verify(self::request($unsigned), []))->outcome->kind);
        $noSeller = self::signed(self::sale(['seller_id' => '']), self::SIG_A);
        self::assertSame(OutcomeKind::UNVERIFIED, self::refusal(static fn () => $adapter->verify(self::request($noSeller), []))->outcome->kind);
        self::assertSame(OutcomeKind::MALFORMED, self::refusal(static fn () => $adapter->verify(self::request([]), []))->outcome->kind);
        $listed = self::signed(self::sale(), self::SIG_A);
        $listed['trans_amount'] = ['47.00'];
        self::assertSame(OutcomeKind::MALFORMED, self::refusal(static fn () => $adapter->verify(self::request($listed), []))->outcome->kind);
    }

    public function testASaleIsRecordedUnderItsReceiptAtItsAmountOnTheCustomClick(): void
    {
        $n = self::parsed([]);

        self::assertSame(NotificationKind::SALE, $n->kind);
        self::assertSame('SALE', $n->event);
        self::assertSame('ZX-1001', $n->transactionId);
        self::assertNull($n->originalTransactionId);
        self::assertSame('47.00', $n->amount);
        self::assertSame('USD', $n->currency);
        self::assertSame(42, $n->clickId);
        self::assertSame(1700000000, $n->occurredAt);
    }

    public function testEachTransactionTypeMapsAsTheSiblingsDo(): void
    {
        self::assertSame(NotificationKind::SALE, self::parsed(['trans_type' => 'FIRST_BILL'])->kind);
        self::assertSame(NotificationKind::REBILL, self::parsed(['trans_type' => 'REBILL'])->kind);
        self::assertSame(NotificationKind::IGNORED, self::parsed(['trans_type' => 'CANCELED'])->kind);
        self::assertSame(NotificationKind::TEST, self::parsed(['trans_testmode' => '1'])->kind);

        $refund = self::parsed(['trans_type' => 'REFUND']);
        self::assertSame(NotificationKind::REFUND, $refund->kind);
        self::assertSame('ZX-1001', $refund->originalTransactionId);
        self::assertSame('47.00', $refund->amount);
        self::assertNull(self::parsed(['trans_type' => 'REFUND', 'trans_amount' => '0.00'])->amount, 'all of it');

        $unknown = self::refusal(static fn () => self::parsed(['trans_type' => 'PAYOUT']));
        self::assertSame(OutcomeKind::MALFORMED, $unknown->outcome->kind);
        self::assertStringContainsString('"PAYOUT"', $unknown->outcome->message);
    }

    public function testTheClickIsTheOneEveryProductsCustomNames(): void
    {
        $product = self::sale()['products'][0];
        $oto = ['products' => [$product, ['custom' => '42'] + $product, ['custom' => ''] + $product]];
        self::assertSame(42, self::parsed($oto)->clickId);
        self::assertNull(self::parsed(['products' => [['custom' => ''] + $product]])->clickId);
        self::assertNull(self::parsed(['products' => []])->clickId);

        $differ = self::refusal(static fn () => self::parsed(['products' => [$product, ['custom' => '43'] + $product]]));
        self::assertSame(OutcomeKind::MALFORMED, $differ->outcome->kind);
        $notClick = self::refusal(static fn () => self::parsed(['products' => [['custom' => 'user-9'] + $product]]));
        self::assertStringContainsString('custom=[[subid]]', $notClick->outcome->message);
    }

    public function testAValueThatCannotBeReadIsRefusedNeverGuessed(): void
    {
        $bad = [
            'trans_amount' => '-47.00', 'trans_currency' => 'US$', 'trans_date' => '09/05/2013',
            'trans_testmode' => 'yes', 'trans_receipt' => '', 'products' => 'x',
        ];
        foreach ($bad as $name => $value) {
            self::assertSame(OutcomeKind::MALFORMED, self::refusal(static fn () => self::parsed([$name => $value]))->outcome->kind, $name);
        }
        self::assertSame(OutcomeKind::MALFORMED, self::refusal(static fn () => self::parsed(['trans_date' => '2023-02-30 10:00:00 UTC']))->outcome->kind);
    }

    public function testTheStoredSignatureIsTrimmedAndAnEmptyOneSignsNothing(): void
    {
        self::assertSame('abc', ZaxaaSignature::stored(" abc\n"));
        self::assertNull(ZaxaaSignature::stored(''));
        self::assertNull(ZaxaaSignature::stored(null));
        self::assertNull(ZaxaaSignature::stored("a\x01b"));
    }
}
