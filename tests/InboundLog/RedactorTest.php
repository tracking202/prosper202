<?php

declare(strict_types=1);

namespace Tests\InboundLog;

use PHPUnit\Framework\TestCase;
use Prosper202\InboundLog\Redactor;

/**
 * The log keeps a request's field names and its ordinary values, never a
 * secret or a person's details, whatever the field is called.
 */
final class RedactorTest extends TestCase
{
    public function testSecretsAndVisitorDetailsAreRedactedByName(): void
    {
        $kept = Redactor::fields([
            'subid' => '9001', 'amount' => '49.00', 'acip' => '17', 'txid' => 'ORD-1001',
            'cverify' => 'A1B2C3D4', 'hash' => 'deadbeef', 'api_key' => 'k', 'token' => 't', 'signature' => 's',
            'iv' => 'abc', 'notification' => 'cipher text',
            'ccustemail' => 'jamie@northwind.example', 'ccustname' => 'Jamie Rivera', 'phone' => '555-0100',
            'ip' => '198.51.100.7', 'visitor_ip' => '198.51.100.7', 'address1' => '1 Main St', 'zip' => '94107',
        ]);

        foreach (['subid' => '9001', 'amount' => '49.00', 'acip' => '17', 'txid' => 'ORD-1001'] as $name => $value) {
            self::assertSame($value, $kept[$name], $name);
        }
        foreach (['cverify', 'hash', 'api_key', 'token', 'signature', 'iv', 'notification', 'ccustemail', 'ccustname',
            'phone', 'ip', 'visitor_ip', 'address1', 'zip'] as $name) {
            self::assertSame(Redactor::REDACTED, $kept[$name], $name);
        }
    }

    public function testAnEmailOrAnAddressIsRedactedWhateverTheFieldIsCalled(): void
    {
        $kept = Redactor::fields([
            'c1' => 'jamie@northwind.example', 'c2' => '203.0.113.42', 'c3' => '2001:db8::1',
            'c4' => 'spring-sale', 'items' => [['sku' => 'TEE-01', 'buyer' => 'pat@contoso.example']],
        ]);

        self::assertSame(Redactor::REDACTED, $kept['c1']);
        self::assertSame(Redactor::REDACTED, $kept['c2']);
        self::assertSame(Redactor::REDACTED, $kept['c3']);
        self::assertSame('spring-sale', $kept['c4']);
        self::assertSame(['0' => ['sku' => 'TEE-01', 'buyer' => Redactor::REDACTED]], $kept['items']);
    }

    public function testTheKeptFieldsAreBounded(): void
    {
        $many = [];
        for ($i = 0; $i < 60; $i++) {
            $many["f$i"] = str_repeat('x', 500);
        }
        $kept = Redactor::fields($many);

        self::assertCount(Redactor::MAX_FIELDS + 1, $kept);
        self::assertSame(20, $kept['_more_fields']);
        self::assertSame(Redactor::MAX_VALUE + 1, mb_strlen((string) $kept['f0']));
        $json = Redactor::encode($kept);
        self::assertIsString($json);
        self::assertLessThanOrEqual(Redactor::MAX_BYTES, strlen($json));
        self::assertGreaterThan(20, (int) json_decode($json, true)['_more_fields']);
        self::assertNull(Redactor::encode([]));
    }
}
