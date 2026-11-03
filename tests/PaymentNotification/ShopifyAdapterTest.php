<?php

declare(strict_types=1);

namespace Tests\PaymentNotification;

use PHPUnit\Framework\TestCase;
use Prosper202\PaymentNotification\InboundRequest;
use Prosper202\PaymentNotification\Notification;
use Prosper202\PaymentNotification\NotificationKind;
use Prosper202\PaymentNotification\OutcomeKind;
use Prosper202\PaymentNotification\Refusal;
use Prosper202\PaymentNotification\Shopify\ShopifyAdapter;

/**
 * How ShopifyAdapter verifies a webhook and reads it, on Shopify-shaped
 * order and refund bodies (demo data). What a webhook records is
 * ShopifyWebhookIntegrationTest's.
 */
final class ShopifyAdapterTest extends TestCase
{
    private const SECRET = 'demo-store-webhook-secret-0001';
    private const OTHER_SECRET = 'other-store-webhook-secret-002';

    /** @param array<string, mixed> $overrides */
    public static function order(array $overrides = []): array
    {
        return $overrides + [
            'id' => 5550001234567,
            'admin_graphql_api_id' => 'gid://shopify/Order/5550001234567',
            'name' => '#1042',
            'email' => 'buyer@example.com',
            'created_at' => '2026-11-01T10:15:00-05:00',
            'processed_at' => '2026-11-01T10:15:02-05:00',
            'currency' => 'USD',
            'presentment_currency' => 'USD',
            'financial_status' => 'paid',
            'test' => false,
            'subtotal_price' => '54.00',
            'total_tax' => '5.00',
            'total_price' => '59.00',
            'browser_ip' => '203.0.113.7',
            'note_attributes' => [],
            'line_items' => [
                [
                    'id' => 13001, 'title' => 'Single-origin coffee beans, 1 kg', 'quantity' => 1, 'price' => '54.00',
                    'sku' => 'COF-1KG', 'properties' => [
                        ['name' => 'Grind', 'value' => 'Whole bean'],
                        ['name' => 't202subid', 'value' => '424242'],
                    ],
                ],
                ['id' => 13002, 'title' => 'Gift wrap', 'quantity' => 1, 'price' => '0.00', 'properties' => []],
            ],
        ];
    }

    /** @param array<string, mixed> $overrides */
    public static function refund(array $overrides = []): array
    {
        return $overrides + [
            'id' => 9100001,
            'order_id' => 5550001234567,
            'created_at' => '2026-11-03T09:00:00-05:00',
            'processed_at' => '2026-11-03T09:00:01-05:00',
            'note' => 'Damaged in transit',
            'restock' => true,
            'transactions' => [
                ['id' => 77001, 'kind' => 'refund', 'status' => 'success', 'amount' => '20.00', 'currency' => 'USD'],
                ['id' => 77002, 'kind' => 'refund', 'status' => 'failure', 'amount' => '39.00', 'currency' => 'USD'],
            ],
            'refund_line_items' => [
                ['id' => 88001, 'quantity' => 1, 'line_item_id' => 13001, 'line_item' => self::order()['line_items'][0]],
            ],
        ];
    }

    /**
     * A webhook as Shopify sends it: the JSON body, its topic, and the
     * signature over the exact body bytes.
     *
     * @param array<string, mixed> $body
     */
    public static function webhook(string $topic, array $body, ?string $secret = self::SECRET): InboundRequest
    {
        $json = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $headers = ['x-shopify-topic' => $topic, 'x-shopify-shop-domain' => 'demo-coffee.myshopify.com',
            'x-shopify-webhook-id' => 'b54557e4-bdd9-4b37-8a5f-bf7d70bcd043', 'x-shopify-api-version' => '2025-10'];
        if ($secret !== null) {
            $headers['x-shopify-hmac-sha256'] = ShopifyAdapter::sign($json, $secret);
        }

        return new InboundRequest('POST', 'application/json', [], '203.0.113.9', '203.0.113.9', 'Shopify-Captain-Hook',
            $json, $headers);
    }

    private static function parse(InboundRequest $request, ?ShopifyAdapter $adapter = null): Notification
    {
        $adapter ??= new ShopifyAdapter();
        self::assertNotNull($adapter->verify($request, [7 => [self::SECRET]]));

        return $adapter->parse($request);
    }

    private static function refusal(callable $call): Refusal
    {
        try {
            $call();
        } catch (Refusal $refusal) {
            return $refusal;
        }
        self::fail('expected a refusal');
    }

    public function testTheSignatureIsShopifysBase64HmacSha256OfTheRawBody(): void
    {
        // openssl dgst -sha256 -hmac <secret> -binary | base64
        self::assertSame(
            'BRcq6JIlhhUMC/MJRRk04F7ld587WM2BAhwwgv11lDE=',
            ShopifyAdapter::sign('{"id":5550001,"total_price":"59.00"}', self::SECRET)
        );
    }

    public function testTheAccountIsTheOneWhoseSecretSignsIt(): void
    {
        $request = self::webhook('orders/paid', self::order());
        $secrets = [3 => [self::OTHER_SECRET], 7 => [self::SECRET], 9 => [self::SECRET]];

        self::assertSame([7 => 0, 9 => 0], (new ShopifyAdapter())->verify($request, $secrets)?->secretByAccount);
    }

    public function testABadSignatureVerifiesNothing(): void
    {
        $forged = self::webhook('orders/paid', self::order(), self::OTHER_SECRET);
        self::assertNull((new ShopifyAdapter())->verify($forged, [7 => [self::SECRET]]));

        // Signed, then the amount changed on the way.
        $signed = self::webhook('orders/paid', self::order());
        $tampered = new InboundRequest('POST', 'application/json', [], '', '', '',
            str_replace('"59.00"', '"5900.00"', $signed->body), $signed->headers);
        self::assertNull((new ShopifyAdapter())->verify($tampered, [7 => [self::SECRET]]));
    }

    public function testAWebhookWithNoSignatureIsRefused(): void
    {
        $refusal = self::refusal(fn () => (new ShopifyAdapter())->verify(
            self::webhook('orders/paid', self::order(), null),
            [7 => [self::SECRET]]
        ));

        self::assertSame(OutcomeKind::UNVERIFIED, $refusal->outcome->kind);
        self::assertStringContainsString('carries no X-Shopify-Hmac-Sha256 header', $refusal->outcome->message);
    }

    public function testWithNoSecretStoredItIsRefusedSayingWhereToStoreOne(): void
    {
        $refusal = self::refusal(fn () => (new ShopifyAdapter())->verify(self::webhook('orders/paid', self::order()), []));

        self::assertSame(OutcomeKind::UNVERIFIED, $refusal->outcome->kind);
        self::assertStringContainsString('No Prosper202 account stores a Shopify webhook secret', $refusal->outcome->message);
        self::assertStringContainsString('Account › API integrations › Shopify', $refusal->outcome->message);
    }

    public function testAnOrderIsASaleUnderItsIdAtItsTotalOnTheLineItemsClick(): void
    {
        foreach (['orders/create', 'orders/paid'] as $topic) {
            $n = self::parse(self::webhook($topic, self::order()));

            self::assertSame(NotificationKind::SALE, $n->kind);
            self::assertSame($topic, $n->event);
            self::assertSame('5550001234567', $n->transactionId);
            self::assertSame('59.00', $n->amount);
            self::assertSame('USD', $n->currency);
            self::assertSame(424242, $n->clickId);
            self::assertSame(strtotime('2026-11-01T15:15:02Z'), $n->occurredAt);
        }
    }

    public function testTheClickIsReadAsPaid1956ReadIt(): void
    {
        $hidden = self::order(['line_items' => [['id' => 1, 'properties' => [['name' => '_T202SUBID', 'value' => ' 77 ']]]]]);
        self::assertSame(77, self::parse(self::webhook('orders/paid', $hidden))->clickId, 'any case, hidden, trimmed');

        $second = self::order(['line_items' => [
            ['id' => 1, 'properties' => []],
            ['id' => 2, 'properties' => [['name' => 't202subid', 'value' => '78']]],
        ]]);
        self::assertSame(78, self::parse(self::webhook('orders/paid', $second))->clickId);

        $none = self::order(['line_items' => [['id' => 1, 'properties' => [['name' => 'Grind', 'value' => 'Fine']]]]]);
        self::assertNull(self::parse(self::webhook('orders/paid', $none))->clickId);
    }

    public function testAPropertyThatIsNotAClickIdIsRefused(): void
    {
        $order = self::order(['line_items' => [['id' => 1, 'properties' => [['name' => 't202subid', 'value' => '12abc']]]]]);
        $refusal = self::refusal(fn () => self::parse(self::webhook('orders/paid', $order)));

        self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind);
        self::assertStringContainsString('"12abc", which is not a click id', $refusal->outcome->message);
    }

    public function testAnOrderCreatedUnpaidWaitsForItsPayment(): void
    {
        foreach (['pending', 'authorized', 'voided'] as $status) {
            $n = self::parse(self::webhook('orders/create', self::order(['financial_status' => $status])));
            self::assertSame(NotificationKind::IGNORED, $n->kind, $status);
            self::assertStringContainsString('not paid yet: ' . $status, $n->event);
        }
        $none = self::order();
        unset($none['financial_status']);
        self::assertSame(NotificationKind::IGNORED, self::parse(self::webhook('orders/create', $none))->kind);

        // orders/paid is the payment itself.
        $paid = self::parse(self::webhook('orders/paid', self::order(['financial_status' => 'partially_paid'])));
        self::assertSame(NotificationKind::SALE, $paid->kind);
    }

    public function testShopifyIsAnsweredWithA2xxForRefusalsARetryCannotChange(): void
    {
        foreach ([OutcomeKind::MALFORMED, OutcomeKind::UNKNOWN_CLICK, OutcomeKind::CONFLICT,
            OutcomeKind::CURRENCY_MISMATCH, OutcomeKind::NOTHING_TO_REVERSE] as $kind) {
            self::assertSame(200, ShopifyAdapter::deliveryStatus($kind), $kind->value);
        }
        self::assertSame(403, ShopifyAdapter::deliveryStatus(OutcomeKind::UNVERIFIED));
        self::assertSame(500, ShopifyAdapter::deliveryStatus(OutcomeKind::FAILED));
        self::assertSame(503, ShopifyAdapter::deliveryStatus(OutcomeKind::UPGRADE_PENDING));
        self::assertSame(405, ShopifyAdapter::deliveryStatus(OutcomeKind::METHOD_NOT_ALLOWED));
    }

    public function testATotalTheLedgerCannotHoldIsRefused(): void
    {
        $refusal = self::refusal(fn () => self::parse(self::webhook('orders/paid', self::order(['total_price' => '1000000.00']))));

        self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind);
        self::assertStringContainsString('at most 999999.99999', $refusal->outcome->message);
    }

    public function testATestOrderRecordsNothing(): void
    {
        self::assertSame(NotificationKind::TEST, self::parse(self::webhook('orders/create', self::order(['test' => true])))->kind);
    }

    public function testOtherTopicsAreAcknowledged(): void
    {
        foreach (['orders/updated', 'orders/cancelled', 'customers/create'] as $topic) {
            self::assertSame(NotificationKind::IGNORED, self::parse(self::webhook($topic, self::order()))->kind, $topic);
        }
    }

    public function testWhatAnOrderCannotSayIsRefused(): void
    {
        $cases = [
            'total_price' => [['total_price' => '59,00'], 'total_price is "59,00"'],
            'id' => [['id' => 'abc'], 'carries no id that is a Shopify id'],
            'time' => [['processed_at' => 'yesterday'], 'processed_at is "yesterday"'],
            'currency' => [['currency' => 'US Dollar'], 'currency is not an ISO 4217 code'],
        ];
        foreach ($cases as $name => [$overrides, $message]) {
            $refusal = self::refusal(fn () => self::parse(self::webhook('orders/paid', self::order($overrides))));
            self::assertSame(OutcomeKind::MALFORMED, $refusal->outcome->kind, $name);
            self::assertStringContainsString($message, $refusal->outcome->message, $name);
        }

        $missing = self::order();
        unset($missing['total_price']);
        $refusal = self::refusal(fn () => self::parse(self::webhook('orders/paid', $missing)));
        self::assertStringContainsString('carries no total_price', $refusal->outcome->message);

        $request = self::webhook('orders/paid', self::order());
        $untitled = new InboundRequest('POST', 'application/json', [], '', '', '', $request->body,
            ['x-shopify-hmac-sha256' => $request->header('x-shopify-hmac-sha256')]);
        $refusal = self::refusal(fn () => self::parse($untitled));
        self::assertStringContainsString('carries no X-Shopify-Topic header', $refusal->outcome->message);
    }

    public function testARefundReversesItsOrderByWhatWasReturned(): void
    {
        $n = self::parse(self::webhook('refunds/create', self::refund()));

        self::assertSame(NotificationKind::REFUND, $n->kind);
        self::assertSame('9100001', $n->transactionId, 'the refund\'s own id');
        self::assertSame('5550001234567', $n->originalTransactionId);
        self::assertSame('20', $n->amount, 'the successful refund transaction only');
        self::assertSame('USD', $n->currency);
        self::assertSame(424242, $n->clickId, 'the refunded line item\'s click');
        self::assertSame(strtotime('2026-11-03T14:00:01Z'), $n->occurredAt);
    }

    public function testARefundThatReturnsNoMoneyRecordsNothing(): void
    {
        $restock = self::refund(['transactions' => []]);

        self::assertSame(NotificationKind::IGNORED, self::parse(self::webhook('refunds/create', $restock))->kind);
    }

    public function testARefundNamingNoClickWithoutTheLedgerNamesNone(): void
    {
        $n = self::parse(self::webhook('refunds/create', self::refund(['refund_line_items' => []])));

        self::assertSame(NotificationKind::REFUND, $n->kind);
        self::assertNull($n->clickId);
    }

    public function testParseReadsOnlyTheRequestVerifyVerified(): void
    {
        $this->expectException(\LogicException::class);
        (new ShopifyAdapter())->parse(self::webhook('orders/paid', self::order()));
    }
}
