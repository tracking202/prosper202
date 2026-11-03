<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\Redactor;

/**
 * The log keeps a request's field names, and a value only for the fields its
 * endpoint is known to send, never a secret or a person's details.
 */
final class RedactorTest extends TestCase
{
    public function testOnlyTheEndpointsKnownFieldsKeepTheirValues(): void
    {
        $kept = Redactor::fields([
            'subid' => '9001', 'amount' => '49.00', 'acip' => '17', 'txid' => 'ORD-1001',
            'cverify' => 'A1B2C3D4', 'hash' => 'deadbeef', 'api_key' => 'k', 'token' => 't', 'signature' => 's',
            'hmac' => 'h', 'pw' => 'p', 'cust' => 'CUS-77', 'customer_ref' => 'CUS-77', 'note' => 'Jamie Rivera',
            'ccustemail' => 'jamie@northwind.example', 'phone' => '555-0100', 'ip' => '198.51.100.7',
        ], 'pb');

        foreach (['subid' => '9001', 'amount' => '49.00', 'acip' => '17', 'txid' => 'ORD-1001'] as $name => $value) {
            self::assertSame($value, $kept[$name], $name);
        }
        foreach (['cverify', 'hash', 'api_key', 'token', 'signature', 'hmac', 'pw', 'cust', 'customer_ref', 'note',
            'ccustemail', 'phone', 'ip'] as $name) {
            self::assertSame(Redactor::REDACTED, $kept[$name], $name);
        }
        self::assertSame(['subid' => Redactor::REDACTED], Redactor::fields(['subid' => '9001'], 'an-endpoint-not-listed'));
    }

    /** The PayKickstart IPN as PayKickstartIpnIntegrationTest posts it, licence keys included. */
    public function testAPayKickstartNotificationKeepsTheSaleAndNotTheBuyerOrTheirLicences(): void
    {
        $kept = Redactor::fields([
            'event' => 'sales', 'mode' => 'live', 'payment_processor' => 'stripe', 'is_rebill' => '0',
            'amount' => '47.00', 'quantity' => '1', 'buyer_first_name' => 'Ruggero', 'buyer_email' => 'buyer@example.com',
            'transaction_id' => 'PK-TX-0001', 'invoice_id' => 'PK-INV-0001', 'transaction_time' => '1793000000',
            'product_id' => '2354', 'campaign_id' => '215', 'custom_subid' => '9001',
            'affiliate_commission_amount' => '0', 'licenses' => ['LIC-AAAA-1111', 'LIC-BBBB-2222'],
            'hash' => 'f00d', 'buyer_last_name' => 'Rossi', 'shipping_address_1' => '1 Main St', 'some_new_field' => 'x',
        ], 'paykickstart');

        foreach (['event' => 'sales', 'amount' => '47.00', 'transaction_id' => 'PK-TX-0001', 'custom_subid' => '9001',
            'product_id' => '2354', 'transaction_time' => '1793000000'] as $name => $value) {
            self::assertSame($value, $kept[$name], $name);
        }
        foreach (['licenses', 'buyer_first_name', 'buyer_email', 'buyer_last_name', 'shipping_address_1', 'hash',
            'some_new_field'] as $name) {
            self::assertSame(Redactor::REDACTED, $kept[$name], $name);
        }
        self::assertStringNotContainsString('LIC-', (string) Redactor::encode($kept));
    }

    public function testAnEmailOrAnAddressInAKeptFieldIsRedacted(): void
    {
        $kept = Redactor::fields([
            'subid' => 'jamie@northwind.example', 'cid' => '203.0.113.42', 'txid' => '2001:db8::1',
            'event' => 'spring-sale', 'order_id' => 'ip=2001:db8::7 ua', 'event_id' => '10:30:00',
            'sku' => ['TEE-01', 'pat@contoso.example'], 'product_id' => ['sku' => 'TEE-01', 'buyer' => 'Pat'],
        ], 'gpx');

        self::assertSame(Redactor::REDACTED, $kept['subid']);
        self::assertSame(Redactor::REDACTED, $kept['cid']);
        self::assertSame(Redactor::REDACTED, $kept['txid']);
        self::assertSame(Redactor::REDACTED, $kept['order_id'], 'an IPv6 address inside longer text');
        self::assertSame('spring-sale', $kept['event']);
        self::assertSame('10:30:00', $kept['event_id'], 'a time is not an address');
        self::assertSame(['0' => 'TEE-01', '1' => Redactor::REDACTED], $kept['sku']);
        self::assertSame(['sku' => 'TEE-01', 'buyer' => Redactor::REDACTED], $kept['product_id']);
    }

    public function testAUrlEncodedEmailIsRedacted(): void
    {
        $kept = Redactor::fields([
            'cvendthru' => 'subid=9001&email=jane%40northwind.example', 'caffitid' => 'jane%2540northwind.example',
            'ctransreceipt' => 'ORD%2D20391',
        ], 'jvzoo');

        self::assertSame(Redactor::REDACTED, $kept['cvendthru']);
        self::assertSame(Redactor::REDACTED, $kept['caffitid'], 'encoded twice');
        self::assertSame('ORD%2D20391', $kept['ctransreceipt'], 'kept as sent');
    }

    public function testAValueThatCannotBeScannedIsRedacted(): void
    {
        $backtrackLimit = ini_get('pcre.backtrack_limit');
        $jit = ini_get('pcre.jit');
        ini_set('pcre.jit', '0');
        ini_set('pcre.backtrack_limit', '10');
        try {
            $kept = Redactor::fields(['subid' => 'x@' . str_repeat('a.', 300) . '1'], 'pb');
        } finally {
            ini_set('pcre.backtrack_limit', (string) $backtrackLimit);
            ini_set('pcre.jit', (string) $jit);
        }
        self::assertSame(Redactor::REDACTED, $kept['subid'], 'a PCRE error fails closed');
        self::assertSame(Redactor::REDACTED, Redactor::fields(['subid' => str_repeat('9', 3000)], 'pb')['subid']);
    }

    public function testTheKeptFieldsAreBounded(): void
    {
        $many = ['subid' => str_repeat('x', 500)];
        for ($i = 0; $i < 59; $i++) {
            $many["f$i"] = str_repeat('x', 500);
        }
        $kept = Redactor::fields($many, 'pb');

        self::assertCount(Redactor::MAX_FIELDS + 1, $kept);
        self::assertSame(20, $kept['_more_fields']);
        self::assertSame(Redactor::MAX_VALUE + 1, mb_strlen((string) $kept['subid']));
        $json = Redactor::encode($kept);
        self::assertIsString($json);
        self::assertLessThanOrEqual(Redactor::MAX_BYTES, strlen($json));
        self::assertNull(Redactor::encode([]));
    }
}
