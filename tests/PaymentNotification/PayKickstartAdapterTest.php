<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartAdapter;
use Prosper202\PaymentNotification\PayKickstart\PayKickstartSignature;
use Prosper202\PaymentNotification\Refusal;

/**
 * How PayKickstartAdapter verifies a request and reads it. The database
 * half (the stored keys, what a notification records) is
 * PayKickstartIpnIntegrationTest's.
 */
final class PayKickstartAdapterTest extends TestCase
{
    private const KEY_A = 'account-three-key-1';
    private const KEY_B = 'account-three-key-2';
    private const KEY_OTHER = 'account-seven-key-1';

    private const AMOUNT_RULE = 'an amount is an exact decimal such as 47 or 47.00, not negative,'
        . ' at most 999999.99999.';
    private const NOT_A_TRANSACTION = 'transaction_id is not a PayKickstart transaction id'
        . ' (1 to 255 letters, digits and punctuation).';
    private const NOT_A_CLICK = ', which is not a click id. The checkout link carries subid=[[subid]], which a'
        . ' Prosper202 tracker fills with the click id; open the checkout through the tracker.';

    /**
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    public static function signed(array $fields, string $key): array
    {
        return $fields + ['hash' => PayKickstartSignature::sign(PayKickstartSignature::message($fields), $key)];
    }

    /** @param array<array-key, mixed> $fields */
    private static function request(array $fields, string $type = 'application/x-www-form-urlencoded'): InboundRequest
    {
        return new InboundRequest('POST', $type, $fields, '203.0.113.9', '203.0.113.9', 'PayKickstart');
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function sale(array $overrides = []): array
    {
        return $overrides + [
            'event' => 'sales', 'mode' => 'live', 'is_rebill' => '0', 'amount' => '47.00',
            'transaction_id' => 'PK-SALE0001', 'invoice_id' => 'PK-INV0001', 'custom_subid' => '42',
            'transaction_time' => '1700000000', 'buyer_email' => 'buyer@example.com',
            'affiliate_commission_amount' => '0',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private static function parsed(array $overrides): Notification
    {
        return (new PayKickstartAdapter())->parse(self::request(self::sale($overrides)));
    }

    /**
     * @param array<int, list<string>> $secrets
     * @return array<int, int>|null
     */
    private static function verified(string $key, array $secrets): ?array
    {
        $request = self::request(self::signed(self::sale(), $key));

        return (new PayKickstartAdapter())->verify($request, $secrets)?->secretByAccount;
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

    public function testTheAccountIsTheOneWhoseKeyVerifies(): void
    {
        $secrets = [3 => [self::KEY_A, self::KEY_B], 7 => [self::KEY_OTHER]];

        self::assertSame([3 => 1], self::verified(self::KEY_B, $secrets));
        self::assertSame([7 => 0], self::verified(self::KEY_OTHER, $secrets));
        // Two accounts holding one campaign's key: both, and the click decides.
        $shared = [3 => [self::KEY_A], 7 => [self::KEY_OTHER, self::KEY_A]];
        self::assertSame([3 => 0, 7 => 1], self::verified(self::KEY_A, $shared));
    }

    public function testNothingVerifiesAChangedValueAnUnknownKeyOrNoKeys(): void
    {
        $adapter = new PayKickstartAdapter();
        $secrets = [3 => [self::KEY_A]];
        $signed = self::signed(self::sale(), self::KEY_A);

        self::assertNull($adapter->verify(self::request(['amount' => '470.00'] + $signed), $secrets));
        self::assertNull($adapter->verify(self::request(['custom_subid' => '43'] + $signed), $secrets));
        self::assertNull($adapter->verify(self::request(['transaction_time' => '1600000000'] + $signed), $secrets));
        self::assertNull(self::verified('not-a-stored-key', $secrets));
        self::assertNull($adapter->verify(self::request($signed), []));
        $upper = self::request(['hash' => strtoupper($signed['hash'])] + $signed);
        self::assertNull($adapter->verify($upper, $secrets), 'the validator compares the hex as sent');
        // A field added with an empty value or "0" is not signed (PayKickstart's algorithm).
        self::assertNotNull($adapter->verify(self::request($signed + ['coupon_rate' => '0']), $secrets));
    }

    public function testARequestThatIsNotAProductIpnIsRefusedBeforeAnyKey(): void
    {
        $adapter = new PayKickstartAdapter();
        $secrets = [3 => [self::KEY_A]];
        $kind = static fn (array $fields, string $type = 'application/x-www-form-urlencoded'): OutcomeKind
            => self::refusal(static fn () => $adapter->verify(self::request($fields, $type), $secrets))->outcome->kind;

        $affiliate = self::refusal(static fn () => $adapter->verify(self::request([], 'application/json'), $secrets));
        self::assertSame(OutcomeKind::UNSUPPORTED_BODY, $affiliate->outcome->kind);
        self::assertStringContainsString('Affiliate IPN', $affiliate->outcome->message);

        self::assertSame(OutcomeKind::MALFORMED, $kind([]));
        self::assertSame(OutcomeKind::UNVERIFIED, $kind(self::sale()));
        self::assertSame(OutcomeKind::UNVERIFIED, $kind(['hash' => ['x']] + self::sale()));
    }

    public function testASaleARebillAndARefund(): void
    {
        self::assertEquals(
            new Notification(NotificationKind::SALE, 'sales', 'PK-SALE0001', null, '47.00', null, 42, 1700000000),
            self::parsed([])
        );

        $rebill = self::parsed(['is_rebill' => '1', 'transaction_id' => 'PK-REBILL2']);
        self::assertSame(NotificationKind::REBILL, $rebill->kind);
        self::assertSame('PK-REBILL2', $rebill->transactionId);

        self::assertEquals(
            new Notification(
                NotificationKind::REFUND,
                'refund',
                'PK-SALE0001',
                'PK-SALE0001',
                '47.00',
                null,
                42,
                1700000000
            ),
            self::parsed(['event' => 'refund'])
        );
        self::assertSame('9.5', self::parsed(['event' => 'refund', 'amount' => '-9.5'])->amount, 'its sign dropped');
        self::assertNull(self::parsed(['event' => 'refund', 'amount' => '0.00'])->amount, 'no amount: all of it');
        $noAmount = array_diff_key(self::sale(['event' => 'refund']), ['amount' => 1]);
        self::assertNull((new PayKickstartAdapter())->parse(self::request($noAmount))->amount);
        self::assertSame('0', self::parsed(['amount' => '0'])->amount, 'a free sale is a sale at 0');
        $noTime = array_diff_key(self::sale(), ['transaction_time' => 1]);
        self::assertNull((new PayKickstartAdapter())->parse(self::request($noTime))->occurredAt, 'no time: none');
        self::assertNull(self::parsed(['transaction_time' => ' '])->occurredAt);
    }

    public function testTestModeAndEveryOtherEventRecordNothing(): void
    {
        $adapter = new PayKickstartAdapter();

        self::assertSame(NotificationKind::TEST, self::parsed(['mode' => 'test'])->kind);
        $refund = $adapter->parse(self::request(['event' => 'refund', 'mode' => 'test']));
        self::assertSame(NotificationKind::TEST, $refund->kind);
        foreach (['subscription-payment', 'subscription-created', 'refunded', 'lead-added', 'Sales'] as $event) {
            $read = $adapter->parse(self::request(['event' => $event, 'mode' => 'live']));
            self::assertSame(NotificationKind::IGNORED, $read->kind, $event);
            self::assertSame($event, $read->event);
        }
        $odd = $adapter->parse(self::request(['event' => "bad\nevent"]));
        self::assertSame('bad?event', $odd->event, 'shown, never as sent');
    }

    public function testClickIdIsTheCheckoutLinksSubidOrNone(): void
    {
        $noSubid = array_diff_key(self::sale(), ['custom_subid' => 1]);
        self::assertNull((new PayKickstartAdapter())->parse(self::request($noSubid))->clickId);
        self::assertNull(self::parsed(['custom_subid' => ''])->clickId);
        self::assertSame(9, self::parsed(['custom_subid' => ' 9 '])->clickId, 'trimmed, as signed');
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function malformed(): array
    {
        return [
            'no event' => [['event' => ''], 'The notification carries no event.'],
            'no mode' => [['mode' => ''], 'The notification carries no mode (live or test).'],
            'another mode' => [['mode' => 'sandbox'], 'mode is "sandbox"; PayKickstart sends live or test.'],
            'no transaction id' => [['transaction_id' => ' '], 'The sales notification carries no transaction_id.'],
            'a transaction id with a space' => [['transaction_id' => 'PK 1'], self::NOT_A_TRANSACTION],
            'a transaction id too long' => [['transaction_id' => str_repeat('T', 256)], self::NOT_A_TRANSACTION],
            'no amount' => [['amount' => ''], 'The sale carries no amount.'],
            'an amount with a comma' => [['amount' => '1,000.00'], 'amount is "1,000.00"; ' . self::AMOUNT_RULE],
            'a negative sale' => [['amount' => '-5'], 'amount is "-5"; ' . self::AMOUNT_RULE],
            'an exponent' => [['amount' => '1e3'], 'amount is "1e3"; ' . self::AMOUNT_RULE],
            'more than the column holds' => [['amount' => '1000000'], 'amount is "1000000"; ' . self::AMOUNT_RULE],
            'six decimals' => [['amount' => '1.000001'], 'amount is "1.000001"; ' . self::AMOUNT_RULE],
            'is_rebill not 0 or 1' => [['is_rebill' => 'yes'], 'is_rebill is "yes"; PayKickstart sends 0 or 1.'],
            'a date, not a Unix time' => [['transaction_time' => '2026-10-07'], self::notATime('2026-10-07')],
            'a time of 0' => [['transaction_time' => '0'], self::notATime('0')],
            'a fraction of a second' => [['transaction_time' => '1469014598.5'], self::notATime('1469014598.5')],
            'milliseconds' => [['transaction_time' => '1469014598000'], self::notATime('1469014598000')],
            'past what a row holds' => [['transaction_time' => '2147483648'], self::notATime('2147483648')],
            'a subid that is not a click id' => [
                ['custom_subid' => '12abc'],
                'custom_subid is "12abc"' . self::NOT_A_CLICK,
            ],
            'an unfilled token' => [['custom_subid' => '[[subid]]'], 'custom_subid is "[[subid]]"' . self::NOT_A_CLICK],
            'a list where a value belongs' => [
                ['custom_subid' => ['42']],
                'custom_subid arrived as a list; PayKickstart sends it as one value.',
            ],
        ];
    }

    private static function notATime(string $sent): string
    {
        return 'transaction_time is "' . $sent . '"; PayKickstart sends it as a Unix time (seconds since 1970, such as'
            . ' 1469014598).';
    }

    /**
     * @dataProvider malformed
     * @param array<string, mixed> $overrides
     */
    public function testAFieldTheSaleNeedsIsReadExactlyOrRefusedByName(array $overrides, string $message): void
    {
        $refusal = self::refusal(static fn () => self::parsed($overrides));

        self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind);
        self::assertSame($message, $refusal->outcome->message);
    }

    public function testAListThePayloadMayCarryIsNotRead(): void
    {
        self::assertSame(NotificationKind::SALE, self::parsed(['licenses' => ['LIC-1', 'LIC-2']])->kind);
    }
}
